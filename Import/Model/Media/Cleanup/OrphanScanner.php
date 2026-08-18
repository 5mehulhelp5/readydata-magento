<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Media\Cleanup;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\MediaStorage\Helper\File\Storage\Database as StorageDatabase;
use ReadyData\Import\Logger\Logger;
use ReadyData\Import\Model\ResourceModel\MediaOrphanScan;

/**
 * Puts the two halves of the orphan question together: what is on disk
 * ({@see FileWalker}) and what the database still points at
 * ({@see MediaOrphanScan}).
 *
 * Read-only from end to end. It deletes nothing, and phase two's deleter is a
 * separate decision that has not been taken — see §9 of PLAN.md.
 *
 * The ORDER of the two halves is an invariant, not an implementation detail.
 * The disk is walked FIRST and the references read SECOND, so that references
 * can only grow relative to the candidate snapshot: a concurrent import then
 * skews the result toward "referenced". Reversed, a file written and committed
 * between the two passes is reported as an orphan, which is the direction that
 * does harm.
 */
class OrphanScanner
{
    private const ORPHAN_PAGE_SIZE = 1000;

    private const DAY = 86400;
    public const BUCKET_OLDEST = '>180d';
    public const BUCKET_UNKNOWN = 'unknown';

    public function __construct(
        private readonly FileWalker $walker,
        private readonly MediaOrphanScan $scan,
        private readonly StorageDatabase $storageDatabase,
        private readonly Filesystem $filesystem,
        private readonly Logger $logger
    ) {
    }

    /**
     * Refuse the two storage configurations this cannot answer honestly.
     *
     * Database media storage: the files this walks are not the files the
     * storefront serves, so every number would be fiction. Same refusal
     * FileResolver makes for the same reason.
     *
     * Remote storage: not primarily the per-file metadata round trip, which is
     * merely slow. AwsS3::stat() routes through the remote-storage metadata
     * provider, which PERSISTS every stat into the Magento cache backend — half
     * a million of those evict the live config and block caches on a Redis LRU,
     * so a read-only report would take the storefront down with it. An operator
     * on S3 has better tools for the disk half of this question (an inventory
     * report, or `aws s3 ls --recursive --summarize`) and only needs the
     * database half from here.
     *
     * @throws LocalizedException
     */
    public function assertSupported(bool $allowRemoteStorage): void
    {
        if ($this->storageDatabase->checkDbUsage()) {
            throw new LocalizedException(
                __(
                    'Database media storage is enabled, so the files on disk are not the files the storefront'
                    . ' serves and this report would be meaningless.'
                )
            );
        }

        if (!$this->isRemoteStorage() || $allowRemoteStorage) {
            return;
        }

        throw new LocalizedException(
            __(
                'This store uses remote storage. Reading one file\'s size and modification time is a request'
                . ' to the remote, and every one of them is persisted into the Magento cache backend, which'
                . ' on a large catalogue can evict the live configuration and block caches. Re-run with'
                . ' --allow-remote-storage if you accept that.'
            )
        );
    }

    /**
     * Walk, load references, and measure. Nothing large is ever held in PHP:
     * the candidate set lives in a temporary table and every figure below is an
     * aggregate computed by the database.
     *
     * @param callable(string): void|null $onProgress narration for a long walk
     * @param callable(string): void|null $onOrphanPath receives every
     *        unreferenced path, streamed a page at a time. Consumed HERE rather
     *        than returned because the paths live in a temporary table that the
     *        finally below destroys.
     * @throws LocalizedException
     */
    public function scan(
        bool $sizeExcluded = true,
        ?callable $onProgress = null,
        ?callable $onOrphanPath = null
    ): OrphanReport {
        $this->scan->createTables();

        try {
            $walked = 0;
            $totals = $this->walker->walk(
                function (array $batch) use (&$walked, $onProgress): void {
                    $this->scan->addCandidates($batch);
                    $walked += count($batch);
                    if ($onProgress !== null && $walked % 50000 === 0) {
                        $onProgress(sprintf('%d files scanned', $walked));
                    }
                },
                $sizeExcluded
            );

            if ($onProgress !== null) {
                $onProgress(sprintf('%d files on disk; reading references', $totals['included']['files']));
            }

            // Second, never first. See the class docblock.
            $referencesLoaded = $this->scan->loadReferences();

            $report = new OrphanReport(
                $totals['included']['files'],
                $totals['included']['bytes'],
                $totals['excluded'],
                $totals['skipped'],
                $referencesLoaded,
                $this->scan->countReferencedCandidates(),
                $this->scan->countMissingReferences(),
                $this->scan->aggregateOrphansByAge($this->ageBoundaries(), self::BUCKET_OLDEST, self::BUCKET_UNKNOWN),
                $this->scan->countUnboundGalleryRows()
            );

            if ($onOrphanPath !== null) {
                $this->streamOrphanPaths($onOrphanPath);
            }

            $this->logger->info(sprintf(
                'Media orphan scan: %d files on disk, %d unreferenced (%d bytes).',
                $report->scannedFiles,
                $report->orphanFiles(),
                $report->orphanBytes()
            ));

            return $report;
        } finally {
            $this->scan->dropTables();
        }
    }

    /**
     * @param callable(string): void $onPath
     */
    private function streamOrphanPaths(callable $onPath): void
    {
        $after = '';
        do {
            $page = $this->scan->fetchOrphanPage($after, self::ORPHAN_PAGE_SIZE);
            foreach ($page as $path) {
                $onPath($path);
            }
            $after = $page === [] ? '' : (string)end($page);
        } while (count($page) === self::ORPHAN_PAGE_SIZE);
    }

    /**
     * Bucket label => inclusive lower mtime bound, newest first. Anything older
     * than the last boundary falls into BUCKET_OLDEST, which is where the
     * recoverable disk actually is — a file a few days old is more likely to be
     * an import still in flight than a leak.
     *
     * @return array<string, int>
     */
    private function ageBoundaries(): array
    {
        $now = time();

        return [
            '<7d' => $now - 7 * self::DAY,
            '7-30d' => $now - 30 * self::DAY,
            '30-180d' => $now - 180 * self::DAY,
        ];
    }

    private function isRemoteStorage(): bool
    {
        $driver = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA)->getDriver();

        // Safe when Magento_RemoteStorage is absent: instanceof against a class
        // that does not exist is false and does not autoload.
        return $driver instanceof \Magento\RemoteStorage\Driver\RemoteDriverInterface;
    }
}
