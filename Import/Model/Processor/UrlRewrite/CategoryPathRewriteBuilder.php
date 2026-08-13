<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Processor\UrlRewrite;

use ReadyData\Import\Model\ResourceModel\Category;

/**
 * Builds category-path product URL-rewrite candidates (and anchor-ancestor
 * rollup rewrites), reproducing the output of Magento's
 * CategoriesUrlRewriteGenerator + AnchorUrlRewriteGenerator in bulk.
 *
 * For a product in category C: one rewrite whose request path is the category
 * url_path + product url_key + product suffix, targeting
 * catalog/product/view/id/{pid}/category/{C}; plus one rewrite for each anchor
 * ancestor A of C (is_anchor, below the store root), targeting {A}.
 */
class CategoryPathRewriteBuilder
{
    public function __construct(
        private readonly Category $categoryResource
    ) {
    }

    /**
     * @param array<int, int[]> $categoriesByEntity entity_id => assigned category IDs
     * @param array<int, string> $urlKeyByEntity entity_id => product url_key
     * @return array<int, array<int, array{request_path: string, target_path: string, category_id: int}>>
     *         entity_id => list of candidate rewrites
     */
    public function build(
        array $categoriesByEntity,
        array $urlKeyByEntity,
        int $storeId,
        string $productSuffix
    ): array {
        $assignedIds = $this->uniqueCategoryIds($categoriesByEntity);
        if (!$assignedIds) {
            return [];
        }

        $ancestry = $this->categoryResource->getAncestry($assignedIds);
        $anchorIds = $this->collectAncestorIds($ancestry);
        $allIds = array_values(array_unique(array_merge($assignedIds, $anchorIds)));

        // Levels for assigned categories come from the ancestry lookup already;
        // only the anchor ancestors need a second fetch.
        $levels = [];
        foreach ($ancestry as $id => $entry) {
            $levels[$id] = $entry['level'];
        }
        $anchorLevelIds = array_values(array_diff($anchorIds, array_keys($ancestry)));
        foreach ($this->categoryResource->getExistingByIds($anchorLevelIds) as $id => $row) {
            $levels[$id] = $row['level'];
        }

        $urlPaths = $this->categoryResource->getUrlPaths($allIds, $storeId);
        $isAnchor = $this->categoryResource->getIsAnchor($anchorIds);

        $result = [];
        foreach ($categoriesByEntity as $entityId => $categoryIds) {
            $urlKey = $urlKeyByEntity[$entityId] ?? '';
            if ($urlKey === '') {
                continue;
            }

            // Dedupe by request path — an anchor shared by two assigned
            // categories must not yield two identical rewrites.
            $seen = [];
            $candidates = [];
            foreach ($categoryIds as $categoryId) {
                $this->addCandidate(
                    $candidates,
                    $seen,
                    (int)$entityId,
                    $categoryId,
                    $urlPaths[$categoryId] ?? '',
                    $urlKey,
                    $productSuffix
                );

                foreach ($ancestry[$categoryId]['ancestors'] ?? [] as $ancestorId) {
                    // Anchor rollup: only anchor categories below the store root (level >= 2).
                    if (($isAnchor[$ancestorId] ?? false) && ($levels[$ancestorId] ?? 0) >= 2) {
                        $this->addCandidate(
                            $candidates,
                            $seen,
                            (int)$entityId,
                            $ancestorId,
                            $urlPaths[$ancestorId] ?? '',
                            $urlKey,
                            $productSuffix
                        );
                    }
                }
            }

            if ($candidates) {
                $result[(int)$entityId] = $candidates;
            }
        }

        return $result;
    }

    /**
     * @param array<int, array{request_path: string, target_path: string, category_id: int}> $candidates
     * @param array<string, true> $seen
     */
    private function addCandidate(
        array &$candidates,
        array &$seen,
        int $entityId,
        int $categoryId,
        string $categoryUrlPath,
        string $urlKey,
        string $productSuffix
    ): void {
        if ($categoryUrlPath === '') {
            return;
        }
        $requestPath = $categoryUrlPath . '/' . $urlKey . $productSuffix;
        if (isset($seen[$requestPath])) {
            return;
        }
        $seen[$requestPath] = true;
        $candidates[] = [
            'request_path' => $requestPath,
            'target_path' => 'catalog/product/view/id/' . $entityId . '/category/' . $categoryId,
            'category_id' => $categoryId,
        ];
    }

    /**
     * @param array<int, int[]> $categoriesByEntity
     * @return int[]
     */
    private function uniqueCategoryIds(array $categoriesByEntity): array
    {
        $ids = [];
        foreach ($categoriesByEntity as $categoryIds) {
            foreach ($categoryIds as $categoryId) {
                $ids[$categoryId] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * @param array<int, array{level: int, ancestors: int[]}> $ancestry
     * @return int[]
     */
    private function collectAncestorIds(array $ancestry): array
    {
        $ids = [];
        foreach ($ancestry as $entry) {
            foreach ($entry['ancestors'] as $ancestorId) {
                $ids[$ancestorId] = true;
            }
        }

        return array_keys($ids);
    }
}
