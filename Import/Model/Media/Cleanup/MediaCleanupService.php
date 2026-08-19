<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Media\Cleanup;

use Magento\Catalog\Model\Product\Image\RemoveDeletedImagesFromCache;
use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use ReadyData\Import\Api\MediaReferenceCheckerInterface;
use ReadyData\Import\Logger\Logger;
use ReadyData\Import\Model\Config;

/**
 * Deletes product media files that nothing references any more.
 *
 * The single place any file is removed from pub/media by this module, and there
 * are three callers, not one: a batch that detached files, a batch that rolled
 * back after downloading them, and a product delete. That alone is why this is a
 * service rather than logic inside whichever hook came first — and it also means
 * a future products/delete endpoint, which will write by direct SQL and fire no
 * model event, can call it and inherit the behaviour instead of silently
 * reintroducing the problem. See §9.2 of PLAN.md.
 *
 * Gated by {@see Config::ownsProductMedia()}, an assertion the operator makes:
 * that nothing else writes to pub/media/catalog/product. Off by default, and
 * with it off this class does nothing at all.
 *
 * TWO RULES THIS MUST NOT BREAK
 *
 *  - Never inside a transaction. A file delete cannot be rolled back, so a
 *    caller must have committed (or definitively rolled back) first.
 *  - Never fatal. Every caller has already finished its real work; a file that
 *    cannot be removed is a logged annoyance, not a failed import.
 *
 * The reference check is not a formality. Target paths are a deterministic
 * function of the source URL, so two SKUs fed the same image URL share one file
 * on disk — detaching from one must not delete what the other still shows.
 *
 * The check is not sufficient either, which is what {@see GRACE_PERIOD_SECONDS}
 * is for: it answers for the instant it runs, and a concurrent import can bind a
 * row to a file moments later. See {@see deleteUnreferenced()}.
 */
class MediaCleanupService
{
    /**
     * How recently a file may have been written and still be spared.
     *
     * The reference check is a snapshot, and nothing holds a lock across it.
     * A concurrent batch resolves an image URL during its UNLOCKED prepare
     * phase, finds the target already on disk, skips the download — and only
     * then, after taking its locks and running its transaction, commits the
     * gallery row that references it. Between those two moments the file is
     * genuinely unreferenced, and a cleanup that fires in that window deletes a
     * file another batch is about to point at. Nothing errors: the row commits
     * fine and the storefront serves a broken image.
     *
     * A window wide enough to cover another batch's prepare-to-commit span
     * closes it. The cost is that a file detached very soon after being written
     * is skipped and, since nothing revisits it, leaks until the orphan report
     * surfaces it — a recoverable, reported outcome, against a silent broken
     * image. That is the trade this module makes everywhere else too.
     *
     * Cheap in the normal case, which is the point: a file this batch detaches
     * was written by some EARLIER import, so its mtime is nowhere near the
     * window and nothing is spared at all.
     */
    public const GRACE_PERIOD_SECONDS = 900;

    public function __construct(
        private readonly Config $config,
        private readonly MediaReferenceCheckerInterface $referenceChecker,
        private readonly Filesystem $filesystem,
        private readonly MediaConfig $mediaConfig,
        private readonly RemoveDeletedImagesFromCache $removeDeletedImagesFromCache,
        private readonly Logger $logger
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->ownsProductMedia();
    }

    /**
     * Delete whichever of these files nothing references any more, sparing any
     * written within {@see GRACE_PERIOD_SECONDS}.
     *
     * For the callers that DETACHED a file — a committed batch, a product
     * delete. They know the database no longer points at it; they cannot know
     * whether a concurrent import is midway through pointing at it again, which
     * is what the grace period covers.
     *
     * @param string[] $files stored paths as the gallery holds them ("/a/b/x.jpg")
     * @return string[] the paths actually deleted
     */
    public function deleteUnreferenced(array $files): array
    {
        return $this->deleteFiles($files, true);
    }

    /**
     * Delete files the CALLER ITSELF downloaded and then abandoned, at any age.
     *
     * For a rolled-back batch, and the one case the grace period cannot apply
     * to: these files were written seconds ago by definition, so honouring it
     * would spare every one of them and the rollback would clean up nothing.
     *
     * The reference check still runs and still carries the residual window it
     * documents — between our download and this call, a concurrent batch may
     * have adopted the file and not yet committed its row. Unlike the detach
     * case that window cannot be widened away, because the file's youth is the
     * very reason we are entitled to remove it. It is narrow (one batch's
     * failure path) and the alternative is leaking every rolled-back download.
     *
     * @param string[] $files stored paths ("/a/b/x.jpg")
     * @return string[] the paths actually deleted
     */
    public function deleteAbandonedDownloads(array $files): array
    {
        return $this->deleteFiles($files, false);
    }

    /**
     * @param string[] $files
     * @param bool $spareRecent honour the grace period; see the two callers above
     * @return string[]
     */
    private function deleteFiles(array $files, bool $spareRecent): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $candidates = [];
        foreach ($files as $file) {
            $file = trim((string)$file);
            if ($file !== '') {
                $candidates[$file] = true;
            }
        }
        if (!$candidates) {
            return [];
        }

        // One batched query per source rather than one per file — the reason
        // MediaReferenceCheckerInterface takes an array.
        $unreferenced = $this->referenceChecker->getUnreferenced(array_keys($candidates));
        if (!$unreferenced) {
            return [];
        }

        $directory = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $base = rtrim($this->mediaConfig->getBaseMediaPath(), '/');

        $cutoff = $spareRecent ? time() - self::GRACE_PERIOD_SECONDS : null;

        $deleted = [];
        $spared = 0;
        foreach ($unreferenced as $file) {
            $path = $base . '/' . ltrim($file, '/');
            try {
                if (!$directory->isExist($path)) {
                    // Already gone: a concurrent run, or a gallery row that
                    // outlived its file. Nothing to do and nothing to report.
                    continue;
                }
                if (!$directory->isFile($path)) {
                    // WriteInterface::delete() removes a DIRECTORY RECURSIVELY,
                    // guarding only the media root itself — so a stored path
                    // that happens to name a directory would take the whole
                    // subtree with it. The reference check cannot save us here:
                    // no gallery row ever equals a directory path, so every
                    // directory classifies as unreferenced. Same reasoning as
                    // FileWalker's excluded list, one layer down.
                    $this->logger->warning(sprintf(
                        'Refusing to delete media path "%s": it is not a file.',
                        $file
                    ));
                    continue;
                }
                if ($cutoff !== null && !$this->isOlderThan($directory, $path, $cutoff)) {
                    $spared++;
                    continue;
                }
                $directory->delete($path);
                $deleted[] = $file;
            } catch (\Throwable $e) {
                $this->logger->warning(sprintf(
                    'Could not delete unreferenced media file "%s": %s',
                    $file,
                    $e->getMessage()
                ));
            }
        }

        if ($spared > 0) {
            // Said out loud, because otherwise "the report lists orphans and the
            // cleanup ran and the disk did not shrink" has no visible cause.
            $this->logger->info(sprintf(
                'Left %d unreferenced media file(s) in place: written within the last %d seconds, so a'
                . ' concurrent import may still be about to reference them.',
                $spared,
                self::GRACE_PERIOD_SECONDS
            ));
        }

        if ($deleted) {
            $this->purgeRenditions($deleted);
            $this->logger->info(sprintf(
                'Deleted %d unreferenced media file(s): %s',
                count($deleted),
                implode(', ', array_slice($deleted, 0, 20)) . (count($deleted) > 20 ? ' …' : '')
            ));
        }

        return $deleted;
    }

    /**
     * Whether the file was last written before the cutoff.
     *
     * False whenever the answer is not knowable — a stat that fails or reports
     * no mtime means "spare it", the same reading OrphanScanner gives an mtime
     * of 0 when it buckets it as `unknown` rather than as the oldest files an
     * operator would act on. An unreadable timestamp is not evidence of age.
     */
    private function isOlderThan(WriteInterface $directory, string $path, int $cutoff): bool
    {
        try {
            $stat = $directory->stat($path);
        } catch (\Throwable $e) {
            return false;
        }

        $mtime = (int)($stat['mtime'] ?? 0);

        return $mtime > 0 && $mtime < $cutoff;
    }

    /**
     * Drop the resized renditions of files just deleted, so the source going
     * away does not simply move the bytes into catalog/product/cache.
     *
     * Core's helper resolves the frontend view config itself, so it does not
     * depend on the caller's area — which matters here, where the callers are a
     * web API request and a model observer. It only covers image types CURRENTLY
     * configured in view.xml, so renditions for dimensions a theme no longer
     * asks for survive this; clearing those is the wholesale cache/ delete that
     * §9 recommends separately.
     *
     * @param string[] $files
     */
    private function purgeRenditions(array $files): void
    {
        try {
            // Core's own callers pass paths without the leading slash.
            $this->removeDeletedImagesFromCache->removeDeletedImagesFromCache(
                array_map(static fn (string $file): string => ltrim($file, '/'), $files)
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('Could not purge cached renditions for %d deleted file(s): %s', count($files), $e->getMessage())
            );
        }
    }
}
