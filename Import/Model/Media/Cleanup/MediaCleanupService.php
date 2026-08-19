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
 */
class MediaCleanupService
{
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
     * Delete whichever of these files nothing references any more.
     *
     * @param string[] $files stored paths as the gallery holds them ("/a/b/x.jpg")
     * @return string[] the paths actually deleted
     */
    public function deleteUnreferenced(array $files): array
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

        $deleted = [];
        foreach ($unreferenced as $file) {
            $path = $base . '/' . ltrim($file, '/');
            try {
                if (!$directory->isExist($path)) {
                    // Already gone: a concurrent run, or a gallery row that
                    // outlived its file. Nothing to do and nothing to report.
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
