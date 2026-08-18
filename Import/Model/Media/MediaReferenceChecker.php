<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Media;

use ReadyData\Import\Api\MediaReferenceCheckerInterface;
use ReadyData\Import\Model\Cache\AttributeMetadataCache;
use ReadyData\Import\Model\ResourceModel\EavValue;
use ReadyData\Import\Model\ResourceModel\ProductMediaGallery;

/**
 * Two queries behind {@see MediaReferenceCheckerInterface}: bound gallery rows
 * first, then the image role attributes for whatever the gallery did not account
 * for. See the interface for what counts as a reference and what does not.
 *
 * The role pass runs only on the paths the gallery pass left over, so the common
 * case — a file that is plainly still in some product's gallery — costs one query.
 *
 * Role attributes are injected rather than hardcoded (see etc/di.xml): a store
 * with a custom role attribute of its own, say a `hover_image`, holds a reference
 * this check would otherwise miss, and adding the code to the list is the fix.
 * Each code is looked up for its real backend type, so a role attribute that is
 * not `varchar` is handled without special-casing.
 */
class MediaReferenceChecker implements MediaReferenceCheckerInterface
{
    /**
     * @param string[] $roleAttributeCodes
     */
    public function __construct(
        private readonly ProductMediaGallery $productMediaGallery,
        private readonly EavValue $eavValue,
        private readonly AttributeMetadataCache $attributeMetadataCache,
        private readonly array $roleAttributeCodes = []
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getUnreferenced(array $files): array
    {
        // Deduplicated up front: removed_files is already a union across a batch,
        // but the singular entry point and third-party callers are not bound by
        // that, and an empty path must never reach an IN () list.
        $candidates = [];
        foreach ($files as $file) {
            $file = (string)$file;
            if ($file !== '') {
                $candidates[$file] = true;
            }
        }
        if (!$candidates) {
            return [];
        }

        $remaining = $this->withoutReferenced(
            $candidates,
            $this->productMediaGallery->findReferencedFiles(array_keys($candidates))
        );
        if (!$remaining) {
            return [];
        }

        foreach ($this->roleAttributeIdsByBackendType() as $backendType => $attributeIds) {
            $remaining = $this->withoutReferenced(
                $remaining,
                $this->eavValue->findValuesInUse($backendType, $attributeIds, array_keys($remaining))
            );
            if (!$remaining) {
                return [];
            }
        }

        // Back to strings: paths were used as array keys to deduplicate, and PHP
        // casts a numeric-looking key to int, which would break the declared
        // string[] for a caller that passes something other than a stored path.
        return array_map('strval', array_keys($remaining));
    }

    public function isReferenced(string $file): bool
    {
        return $file !== '' && $this->getUnreferenced([$file]) === [];
    }

    /**
     * @param array<string, true> $candidates
     * @param string[] $referenced
     * @return array<string, true>
     */
    private function withoutReferenced(array $candidates, array $referenced): array
    {
        foreach ($referenced as $file) {
            unset($candidates[$file]);
        }

        return $candidates;
    }

    /**
     * Group the configured role codes by the backend type of the table their
     * values actually live in. A code that does not resolve to an attribute is
     * dropped: a role list naming an attribute this store never installed is a
     * misconfiguration, not a reason to report every file as unreferenced.
     *
     * @return array<string, int[]> backend type => attribute ids
     */
    private function roleAttributeIdsByBackendType(): array
    {
        if (!$this->roleAttributeCodes) {
            return [];
        }

        $this->attributeMetadataCache->warm($this->roleAttributeCodes);

        $byType = [];
        foreach ($this->roleAttributeCodes as $code) {
            $attribute = $this->attributeMetadataCache->get((string)$code);
            if ($attribute === null || !in_array($attribute['backend_type'], EavValue::BACKEND_TYPES, true)) {
                continue;
            }
            $byType[$attribute['backend_type']][] = $attribute['attribute_id'];
        }

        return $byType;
    }
}
