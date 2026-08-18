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
        public readonly array $skipped,
        public readonly array $referencesLoaded,
        public readonly array $referencedCandidates,
        public readonly array $missingReferences,
        public readonly array $orphansByAge,
        public readonly int $unboundGalleryRows
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

    /**
     * The share of gallery references whose file is not on disk.
     *
     * High on its own is ambiguous — see {@see isTrustworthy()} for the
     * discriminator that gives it a meaning.
     */
    public function galleryMissRate(): float
    {
        $loaded = $this->referencesLoaded[MediaOrphanScan::SOURCE_GALLERY] ?? 0;
        if ($loaded === 0) {
            return 0.0;
        }

        return ($this->missingReferences[MediaOrphanScan::SOURCE_GALLERY] ?? 0) / $loaded;
    }

    /**
     * Whether the unreferenced figures mean anything.
     *
     * A high miss rate alone does NOT decide this, and treating it as if it did
     * was wrong: a staging copy with a full database and a pruned media
     * directory produces a miss rate near 100% while working perfectly. That is
     * indistinguishable from broken path normalisation by rate alone.
     *
     * The discriminator is whether the files that ARE on disk matched. If any
     * did, the disk and database conventions agree and the misses are simply
     * images this environment does not have — the orphan count is then correct,
     * because it is computed from the files actually present. If NONE matched
     * while files were present and references exist, nothing lines up and every
     * figure is fiction.
     */
    public function isTrustworthy(): bool
    {
        if ($this->scannedFiles === 0) {
            // Nothing on disk means no orphan claim to distrust.
            return true;
        }
        if (($this->referencesLoaded[MediaOrphanScan::SOURCE_GALLERY] ?? 0) === 0) {
            // No gallery rows at all: an empty catalogue, not a mismatch.
            return true;
        }
        if (($this->referencedCandidates[MediaOrphanScan::SOURCE_GALLERY] ?? 0) > 0) {
            return true;
        }

        return $this->galleryMissRate() <= self::TRUST_THRESHOLD;
    }

    /**
     * The benign half of a high miss rate: the conventions agree, but the media
     * directory holds fewer files than the database expects. Worth reporting in
     * its own right — those are missing images — without discrediting the
     * orphan count.
     */
    public function hasIncompleteMedia(): bool
    {
        // Requires a match, not merely trustworthiness: the message this drives
        // asserts that the files present DID line up, and with nothing on disk
        // there is no such evidence to cite. An empty media directory says
        // nothing about either conclusion, and the candidate count of zero
        // already speaks for itself.
        return ($this->referencedCandidates[MediaOrphanScan::SOURCE_GALLERY] ?? 0) > 0
            && $this->galleryMissRate() > self::TRUST_THRESHOLD;
    }

    public function missingGalleryFiles(): int
    {
        return $this->missingReferences[MediaOrphanScan::SOURCE_GALLERY] ?? 0;
    }
}
