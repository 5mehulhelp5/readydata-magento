<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Media\Cleanup;

use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use ReadyData\Import\Logger\Logger;

/**
 * Enumerates the files under pub/media/catalog/product, in batches, with their
 * size and modification time.
 *
 * Descends ONE DIRECTORY AT A TIME rather than calling readRecursively(). That
 * method materialises the entire tree into an array and then sorts it, and the
 * framework offers no generator-based walk, so on a catalogue with hundreds of
 * thousands of images it holds two full path lists in memory before the caller
 * sees a single entry. Walking per directory keeps the resident set to one
 * directory plus one batch, which is what lets this run on a store big enough
 * to need it.
 *
 * All I/O goes through the media directory, never PHP's filesystem primitives,
 * so remote-storage set-ups are honoured — the same rule {@see FileResolver}
 * follows. Note the directory is obtained with getDirectoryWrite() despite this
 * class only ever reading: getDriver() is declared on WriteInterface alone, and
 * the symlink containment check below needs the driver. Do not "fix" it to
 * getDirectoryRead().
 *
 * Nothing here decides what is an orphan. It reports what is on disk; the
 * database half of the question belongs to MediaOrphanScan.
 */
class FileWalker
{
    /**
     * Subtrees that are never candidates, matched on the top-level segment
     * under catalog/product.
     *
     * A constant and not a constructor argument: an injected array is
     * configuration by another name, and this list must not be configurable.
     * `cache` is the reason. Renditions are derived and no database row
     * references them, so the entire subtree would classify as unreferenced —
     * harmless in a report, catastrophic once a phase-two deleter reads the
     * same list.
     *
     * They are still walked for their file count and bytes unless the caller
     * opts out: "how much of this directory is regenerable cache" is the first
     * question the report answers, and it cannot be answered without looking.
     */
    private const EXCLUDED_DIRECTORIES = ['cache', 'watermark', 'placeholder'];

    /**
     * Runaway guard, not a shape rule. Real product images sit at depth 2
     * (/x/y/name.ext), but a gallery row's stored value may be any relative
     * path, so refusing to descend further would report referenced files as
     * missing. This only stops a pathological tree.
     */
    private const MAX_DEPTH = 10;

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly MediaConfig $mediaConfig,
        private readonly MediaPathNormalizer $normalizer,
        private readonly Logger $logger
    ) {
    }

    /**
     * Walk the product media directory, handing the caller batches of files.
     *
     * A callback rather than a generator, matching DownloaderInterface's shape
     * in this module: the caller cannot then forget to exhaust an iterator
     * before reading the totals, which would silently under-report everything.
     *
     * @param callable(array<int, array{path: string, size: int, mtime: int}>): void $onBatch
     *        receives at most $batchSize rows, canonical paths ("/a/b/x.jpg")
     * @param bool $sizeExcluded whether to visit excluded subtrees to count
     *        their files and bytes; false skips them outright
     * @return array{
     *     included: array{files: int, bytes: int},
     *     excluded: array<string, array{files: int, bytes: int}>,
     *     skipped: array{too_long: int, vanished: int, unreadable: int, outside_tree: int}
     * }
     */
    public function walk(callable $onBatch, bool $sizeExcluded = true, int $batchSize = 1000): array
    {
        $totals = [
            'included' => ['files' => 0, 'bytes' => 0],
            'excluded' => [],
            'skipped' => ['too_long' => 0, 'vanished' => 0, 'unreadable' => 0, 'outside_tree' => 0],
        ];
        foreach (self::EXCLUDED_DIRECTORIES as $name) {
            $totals['excluded'][$name] = ['files' => 0, 'bytes' => 0];
        }

        $directory = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $base = $this->normalizer->basePath();
        if (!$directory->isExist($base) || !$directory->isDirectory($base)) {
            // A store that has never had a product image. Empty totals, not an
            // exception: "nothing on disk" is a legitimate answer.
            return $totals;
        }

        $batch = [];
        $visited = [];
        $this->descend($directory, $base, $base, 0, null, $sizeExcluded, $totals, $batch, $visited, $onBatch, $batchSize);

        if ($batch !== []) {
            $onBatch($batch);
        }

        return $totals;
    }

    /**
     * @param string|null $excludedAs the excluded top-level directory this
     *        subtree belongs to, or null when inside the candidate tree
     * @param array<string, array<string, int>|int> $totals
     * @param array<int, array{path: string, size: int, mtime: int}> $batch
     * @param array<string, true> $visited realpaths already descended into
     * @param callable(array<int, array{path: string, size: int, mtime: int}>): void $onBatch
     */
    private function descend(
        WriteInterface $directory,
        string $base,
        string $path,
        int $depth,
        ?string $excludedAs,
        bool $sizeExcluded,
        array &$totals,
        array &$batch,
        array &$visited,
        callable $onBatch,
        int $batchSize
    ): void {
        if ($depth > self::MAX_DEPTH) {
            $this->logger->warning(sprintf('Media scan stopped descending at "%s": maximum depth reached.', $path));

            return;
        }
        if (!$this->enterDirectory($directory, $base, $path, $totals, $visited)) {
            return;
        }

        try {
            $entries = $directory->read($path);
        } catch (\Throwable $e) {
            $totals['skipped']['unreadable']++;
            $this->logger->warning(sprintf('Media scan could not read "%s": %s', $path, $e->getMessage()));

            return;
        }

        foreach ($entries as $entry) {
            $entry = (string)$entry;

            try {
                $isDirectory = $directory->isDirectory($entry);
            } catch (\Throwable $e) {
                // Vanished between the read and the probe — a concurrent import
                // or an admin save. Counted, never fatal: the scan is a
                // snapshot and is allowed to be one file out of date.
                $totals['skipped']['vanished']++;
                continue;
            }

            if ($isDirectory) {
                $childExcludedAs = $excludedAs ?? $this->excludedNameFor($base, $entry, $depth);
                if ($childExcludedAs !== null && !$sizeExcluded) {
                    continue;
                }
                $this->descend(
                    $directory,
                    $base,
                    $entry,
                    $depth + 1,
                    $childExcludedAs,
                    $sizeExcluded,
                    $totals,
                    $batch,
                    $visited,
                    $onBatch,
                    $batchSize
                );
                continue;
            }

            $this->collectFile($directory, $entry, $excludedAs, $totals, $batch, $onBatch, $batchSize);
        }
    }

    /**
     * Containment and loop guard for a directory about to be descended into.
     *
     * A symlink inside catalog/product either points out of the tree — where
     * its bytes are not ours to report and its files are certainly not product
     * images — or points back into it, where following it recurses forever.
     * The containment check is the same one FileResolver applies to inbound
     * payload paths, deliberately: one idiom, recognisable to a reviewer.
     *
     * @param array<string, array<string, int>|int> $totals
     * @param array<string, true> $visited
     */
    private function enterDirectory(
        WriteInterface $directory,
        string $base,
        string $path,
        array &$totals,
        array &$visited
    ): bool {
        $driver = $directory->getDriver();
        $realPath = $driver->getRealPath($directory->getAbsolutePath($path));
        $realBase = $driver->getRealPath($directory->getAbsolutePath($base));

        if (!is_string($realPath) || !is_string($realBase)) {
            $totals['skipped']['unreadable']++;

            return false;
        }

        $realBase = rtrim($realBase, '/');
        if ($realPath !== $realBase && !str_starts_with($realPath, $realBase . '/')) {
            $totals['skipped']['outside_tree']++;
            $this->logger->warning(
                sprintf('Media scan skipped "%s": it resolves outside pub/media/%s.', $path, $base)
            );

            return false;
        }

        if (isset($visited[$realPath])) {
            return false;
        }
        $visited[$realPath] = true;

        return true;
    }

    /**
     * @param array<string, array<string, int>|int> $totals
     * @param array<int, array{path: string, size: int, mtime: int}> $batch
     * @param callable(array<int, array{path: string, size: int, mtime: int}>): void $onBatch
     */
    private function collectFile(
        WriteInterface $directory,
        string $entry,
        ?string $excludedAs,
        array &$totals,
        array &$batch,
        callable $onBatch,
        int $batchSize
    ): void {
        try {
            $stat = $directory->stat($entry);
        } catch (\Throwable $e) {
            $totals['skipped']['vanished']++;

            return;
        }

        $size = (int)($stat['size'] ?? 0);
        $mtime = (int)($stat['mtime'] ?? 0);

        if ($excludedAs !== null) {
            $totals['excluded'][$excludedAs]['files']++;
            $totals['excluded'][$excludedAs]['bytes'] += $size;

            return;
        }

        $canonical = $this->normalizer->fromMediaRelative($entry);
        if ($canonical === null) {
            $totals['skipped']['outside_tree']++;

            return;
        }
        if ($this->normalizer->exceedsColumnLimit($canonical)) {
            // Cannot be held by any reference column, so it cannot be matched
            // and must not enter the candidate table, where SQL_MODE='' would
            // truncate it into a collision. Reported on its own count.
            $totals['skipped']['too_long']++;

            return;
        }

        $totals['included']['files']++;
        $totals['included']['bytes'] += $size;
        $batch[] = ['path' => $canonical, 'size' => $size, 'mtime' => $mtime];
        if (count($batch) >= $batchSize) {
            $onBatch($batch);
            $batch = [];
        }
    }

    /**
     * The excluded directory a path belongs to, or null. Only the segment
     * directly under catalog/product counts — a nested "a/b/cache" is an
     * ordinary directory that happens to share a name.
     */
    private function excludedNameFor(string $base, string $path, int $depth): ?string
    {
        if ($depth !== 0) {
            return null;
        }

        $name = basename($path);

        return in_array($name, self::EXCLUDED_DIRECTORIES, true) ? $name : null;
    }
}
