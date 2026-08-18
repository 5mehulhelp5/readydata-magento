<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Media\Cleanup;

use ReadyData\Import\Model\ResourceModel\MediaOrphanScan;

/**
 * What one scan found. A value object, deliberately: nothing outside this
 * module consumes it, so it needs no interface, no Api/Data contract and no
 * factory.
 */
final class OrphanReport
{
    /**
     * Above this share of the gallery source pointing at files that are not on
     * disk, the numbers are not worth reading. A healthy store has a few
     * percent — images an admin deleted from disk, a botched migration — but
     * a broken path normalisation makes it very nearly everything, and every
     * other figure here is then confidently wrong.
     */
    public const TRUST_THRESHOLD = 0.05;

    /**
     * @param array<string, array{files: int, bytes: int}> $excluded per skipped directory
     * @param array{too_long: int, vanished: int, unreadable: int, outside_tree: int} $skipped
     * @param array<int, int> $referencesLoaded source => rows read
     * @param array<int, int> $referencedCandidates source => candidates accounted for
     * @param array<int, int> $missingReferences source => references with no file on disk
     * @param array<string, array{files: int, bytes: int}> $orphansByAge bucket label => totals
     */
    public function __construct(
        public readonly int $scannedFiles,
        public readonly int $scannedBytes,
        public readonly array $excluded,
        public readonly int $dispersedFiles,
        public readonly array $skipped,
        public readonly array $referencesLoaded,
        public readonly array $referencedCandidates,
        public readonly array $missingReferences,
        public readonly array $orphansByAge,
        public readonly int $unboundGalleryRows,
        public readonly int $assetRowsUnderBasePath,
        public readonly bool $mediaGalleryCatalogEnabled
    ) {
    }

    public function orphanFiles(): int
    {
        return array_sum(array_column($this->orphansByAge, 'files'));
    }

    public function orphanBytes(): int
    {
        return array_sum(array_column($this->orphansByAge, 'bytes'));
    }

    public function referencedFiles(): int
    {
        return $this->scannedFiles - $this->orphanFiles();
    }

    public function excludedFiles(): int
    {
        return array_sum(array_column($this->excluded, 'files'));
    }

    public function excludedBytes(): int
    {
        return array_sum(array_column($this->excluded, 'bytes'));
    }

    /**
     * The share of gallery references whose file is not on disk. The report's
     * own confidence measure — see {@see TRUST_THRESHOLD}.
     */
    public function galleryMissRate(): float
    {
        $loaded = $this->referencesLoaded[MediaOrphanScan::SOURCE_GALLERY] ?? 0;
        if ($loaded === 0) {
            return 0.0;
        }

        return ($this->missingReferences[MediaOrphanScan::SOURCE_GALLERY] ?? 0) / $loaded;
    }

    public function isTrustworthy(): bool
    {
        return $this->galleryMissRate() <= self::TRUST_THRESHOLD;
    }
}
