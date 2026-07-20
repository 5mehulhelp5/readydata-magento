<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Processor;

use ReadyData\Import\Model\BatchContext;
use ReadyData\Import\Model\Cache\CategoryPathResolver;
use ReadyData\Import\Model\Category\PathParser;
use ReadyData\Import\Model\ResourceModel\CategoryLink;

/**
 * Product-to-category assignments with REPLACE semantics: a present
 * "categories" field becomes the product's exact assignment set (an empty
 * array removes all links), null/omitted leaves assignments untouched.
 * Entries are full category paths ("Default Category/Men/Shirts") or
 * numeric category IDs; missing path segments below an existing root are
 * auto-created (see CategoryPathResolver).
 *
 * Safety valve: when any of a product's entries fails to resolve, that
 * product is applied additively — inserts happen, deletions are withheld —
 * so a typo cannot wipe valid merchandising links.
 *
 * Only path leaves are linked; is_anchor handles ancestor rollup. New links
 * get position 0, existing links keep their admin-set positions.
 *
 * Publishes to the context data bag:
 *  - "affected_category_ids": int[] category IDs whose product set changed;
 *    consumed by InvalidationHandler for FPC tag cleaning.
 *  - "affected_product_ids": int[] product IDs whose category links changed;
 *    consumed by ImportEventDispatcher for the category-changed event.
 */
class CategoryLinkProcessor implements ProcessorInterface
{
    public const CONTEXT_AFFECTED_CATEGORY_IDS = 'affected_category_ids';
    public const CONTEXT_AFFECTED_PRODUCT_IDS = 'affected_product_ids';

    public function __construct(
        private readonly CategoryLink $categoryLink,
        private readonly CategoryPathResolver $pathResolver,
        private readonly PathParser $pathParser
    ) {
    }

    public function process(BatchContext $context): void
    {
        $uniquePaths = [];
        $uniqueIds = [];
        $refsBySku = [];

        foreach ($context->getValidProducts() as $sku => $product) {
            if ($product->getCategories() === null) {
                continue;
            }
            $entityId = $context->getEntityId($sku);
            if ($entityId === null) {
                continue;
            }

            $refs = ['entity_id' => $entityId, 'paths' => [], 'ids' => [], 'partial' => false];
            foreach ($product->getCategories() as $reference) {
                $parsed = $this->pathParser->parse((string)$reference);
                if ($parsed === null) {
                    $context->addMessage($sku, 'Empty category reference skipped.');
                    $refs['partial'] = true;
                    continue;
                }
                if ($parsed['type'] === PathParser::TYPE_ID) {
                    $uniqueIds[$parsed['id']] = true;
                    $refs['ids'][$parsed['id']] = true;
                } else {
                    // Escaped canonical key — a plain implode would collide
                    // ["a/b"] with ["a","b"].
                    $key = PathParser::buildKey($parsed['segments']);
                    $uniquePaths[$key] = $parsed['segments'];
                    $refs['paths'][$key] = true;
                }
            }
            $refsBySku[$sku] = $refs;
        }

        if (!$refsBySku) {
            return;
        }

        $pathResults = $this->pathResolver->resolvePaths($uniquePaths);
        $validIds = $this->pathResolver->validateIds(array_keys($uniqueIds));
        $currentAssignments = $this->categoryLink->getAssignments(
            array_column($refsBySku, 'entity_id')
        );

        $toInsert = [];
        $toDelete = [];
        $touchedCategoryIds = [];
        $touchedProductIds = [];

        foreach ($refsBySku as $sku => $refs) {
            $partial = $refs['partial'];
            $desired = [];

            foreach (array_keys($refs['paths']) as $key) {
                $result = $pathResults[$key];
                if ($result['id'] === null) {
                    $context->addMessage($sku, $result['message']);
                    $partial = true;
                } else {
                    $desired[$result['id']] = true;
                }
            }
            foreach (array_keys($refs['ids']) as $categoryId) {
                if (isset($validIds[$categoryId])) {
                    $desired[$categoryId] = true;
                } else {
                    $context->addMessage(
                        $sku,
                        sprintf('Unknown or root category ID %d skipped.', $categoryId)
                    );
                    $partial = true;
                }
            }

            $entityId = $refs['entity_id'];
            $currentSet = array_fill_keys($currentAssignments[$entityId] ?? [], true);

            foreach (array_keys($desired) as $categoryId) {
                if (!isset($currentSet[$categoryId])) {
                    $toInsert[] = [
                        'category_id' => $categoryId,
                        'product_id' => $entityId,
                        'position' => 0,
                    ];
                    $touchedCategoryIds[$categoryId] = true;
                    $touchedProductIds[$entityId] = true;
                }
            }

            $removals = array_diff_key($currentSet, $desired);
            if (!$removals) {
                continue;
            }
            if ($partial) {
                $context->addMessage(
                    $sku,
                    'Category set applied additively: some references could not be'
                    . ' resolved, so no existing assignments were removed.'
                );
                continue;
            }
            foreach (array_keys($removals) as $categoryId) {
                $toDelete[] = ['category_id' => $categoryId, 'product_id' => $entityId];
                $touchedCategoryIds[$categoryId] = true;
                $touchedProductIds[$entityId] = true;
            }
        }

        $this->categoryLink->unassign($toDelete);
        $this->categoryLink->assign($toInsert);

        $context->set(self::CONTEXT_AFFECTED_CATEGORY_IDS, array_keys($touchedCategoryIds));
        $context->set(self::CONTEXT_AFFECTED_PRODUCT_IDS, array_keys($touchedProductIds));
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function getSortOrder(): int
    {
        return 700;
    }
}
