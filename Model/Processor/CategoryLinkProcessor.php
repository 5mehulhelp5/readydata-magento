<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Processor;

use ReadyData\Import\Model\BatchContext;
use ReadyData\Import\Model\Cache\CategoryPathResolver;
use ReadyData\Import\Model\Cache\RootCategoryRegistry;
use ReadyData\Import\Model\Category\PathParser;
use ReadyData\Import\Model\Config;
use ReadyData\Import\Model\ResourceModel\Category as CategoryResource;
use ReadyData\Import\Model\ResourceModel\CategoryLink;

/**
 * Product-to-category assignments with REPLACE semantics: a present
 * "categories" field becomes the product's exact assignment set (an empty
 * array removes all links), null/omitted leaves assignments untouched.
 * Entries are full category paths ("Default Category/Men/Shirts") or
 * numeric category IDs; missing path segments below an existing root are
 * auto-created (see CategoryPathResolver).
 *
 * **How far the replace reaches** is bounded by the replace scope: a set of
 * root categories whose links this payload may remove. It defaults to the
 * whole catalog, which is right when one caller owns the catalog and wrong the
 * moment several root trees are fed by several sources — there, each source's
 * push would delete the links the others just wrote, and the only symptom is
 * storefront navigation quietly going missing. Set per product with
 * `categories_replace_scope`, or catalog-wide with
 * `readydata_import/categories/replace_scope`.
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
        private readonly PathParser $pathParser,
        private readonly CategoryResource $categoryResource,
        private readonly RootCategoryRegistry $rootCategories,
        private readonly Config $config
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

        $pathResults = $this->pathResolver->resolvePaths($uniquePaths, $context->getRootCategoryId());
        $validIds = $this->pathResolver->validateIds(array_keys($uniqueIds));
        $currentAssignments = $this->categoryLink->getAssignments(
            array_column($refsBySku, 'entity_id')
        );

        // Two passes: the desired sets have to exist in full before roots can be
        // resolved, because that is one query for the whole batch rather than
        // one per product.
        $plans = [];
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

            $plans[$sku] = [
                'entity_id' => $refs['entity_id'],
                'desired' => $desired,
                'partial' => $partial,
                'current' => array_fill_keys($currentAssignments[$refs['entity_id']] ?? [], true),
            ];
        }

        $rootByCategoryId = $this->resolveRoots($context, $plans);

        $toInsert = [];
        $toDelete = [];
        $touchedCategoryIds = [];
        $touchedProductIds = [];

        foreach ($plans as $sku => $plan) {
            $entityId = $plan['entity_id'];

            foreach (array_keys($plan['desired']) as $categoryId) {
                if (!isset($plan['current'][$categoryId])) {
                    $toInsert[] = [
                        'category_id' => $categoryId,
                        'product_id' => $entityId,
                        'position' => 0,
                    ];
                    $touchedCategoryIds[$categoryId] = true;
                    $touchedProductIds[$entityId] = true;
                }
            }

            $removals = array_keys(array_diff_key($plan['current'], $plan['desired']));
            if (!$removals) {
                continue;
            }
            if ($plan['partial']) {
                $context->addMessage(
                    $sku,
                    'Category set applied additively: some references could not be'
                    . ' resolved, so no existing assignments were removed.'
                );
                continue;
            }

            $removals = $this->withinReplaceScope($context, $sku, $plan, $rootByCategoryId, $removals);
            foreach ($removals as $categoryId) {
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

    /**
     * Root category per category ID, for every ID the batch might reason about
     * — one query for the whole batch, and none at all when nothing in it
     * restricts its replace (the default configuration with no per-product
     * scope, i.e. every payload that predates this feature).
     *
     * @param array<string, array{entity_id: int, desired: array<int, true>,
     *     partial: bool, current: array<int, true>}> $plans
     * @return array<int, int> category ID => root category ID
     */
    private function resolveRoots(BatchContext $context, array $plans): array
    {
        $restricts = $this->config->getCategoryReplaceScope() === Config::REPLACE_SCOPE_PAYLOAD_ROOTS;
        if (!$restricts) {
            foreach (array_keys($plans) as $sku) {
                if ($context->getProduct($sku)?->getCategoriesReplaceScope() !== null) {
                    $restricts = true;
                    break;
                }
            }
        }
        if (!$restricts) {
            return [];
        }

        $categoryIds = [];
        foreach ($plans as $plan) {
            $categoryIds += $plan['current'] + $plan['desired'];
        }
        if (!$categoryIds) {
            return [];
        }

        $roots = [];
        foreach ($this->categoryResource->getAncestry(array_keys($categoryIds)) as $categoryId => $ancestry) {
            // A level-1 category is its own root; deeper ones take the first
            // ancestor, since getAncestry() already drops the tree root.
            $roots[$categoryId] = $ancestry['level'] === 1
                ? $categoryId
                : ($ancestry['ancestors'][0] ?? $categoryId);
        }

        return $roots;
    }

    /**
     * The removals the product's replace scope actually permits.
     *
     * A category whose root cannot be determined — it vanished between the
     * assignment read and now — is KEPT. The scope is a statement about which
     * trees the payload owns, and a link that cannot be placed in a tree cannot
     * be shown to be in one.
     *
     * @param array{entity_id: int, desired: array<int, true>, partial: bool,
     *     current: array<int, true>} $plan
     * @param array<int, int> $rootByCategoryId
     * @param int[] $removals
     * @return int[]
     */
    private function withinReplaceScope(
        BatchContext $context,
        string|int $sku,
        array $plan,
        array $rootByCategoryId,
        array $removals
    ): array {
        $allowedRoots = $this->allowedRoots($context, $sku, $plan, $rootByCategoryId);
        if ($allowedRoots === null) {
            return $removals;
        }

        $allowed = array_fill_keys($allowedRoots, true);
        $permitted = array_values(array_filter(
            $removals,
            static fn (int $categoryId): bool => isset($rootByCategoryId[$categoryId])
                && isset($allowed[$rootByCategoryId[$categoryId]])
        ));

        $keptCount = count($removals) - count($permitted);
        if ($keptCount > 0) {
            // Two whole sentences rather than one with inflected fragments: a
            // message assembled from "categor" + "y"/"ies" cannot be translated
            // and reads as a bug when the list is empty.
            $context->addMessage($sku, $allowedRoots === []
                ? sprintf(
                    'Category replacement was limited to no root categories, so nothing was removed;'
                    . ' %d existing assignment(s) were kept.',
                    $keptCount
                )
                : sprintf(
                    'Category replacement was limited to root categories %s;'
                    . ' %d existing assignment(s) outside them were kept.',
                    implode(', ', $allowedRoots),
                    $keptCount
                ));
        }

        return $permitted;
    }

    /**
     * Root IDs whose links this product may lose, or null for "no restriction".
     *
     * The payload's own `categories_replace_scope` wins over the configuration:
     * a feed that states which trees it owns knows something the instance-wide
     * setting does not.
     *
     * @param array{entity_id: int, desired: array<int, true>, partial: bool,
     *     current: array<int, true>} $plan
     * @param array<int, int> $rootByCategoryId
     * @return int[]|null
     */
    private function allowedRoots(
        BatchContext $context,
        string|int $sku,
        array $plan,
        array $rootByCategoryId
    ): ?array {
        $declared = $context->getProduct($sku)?->getCategoriesReplaceScope();
        if ($declared !== null) {
            return $this->validRoots($context, $sku, $declared);
        }
        if ($this->config->getCategoryReplaceScope() !== Config::REPLACE_SCOPE_PAYLOAD_ROOTS) {
            return null;
        }

        // The roots the payload's own entries land in. An empty `categories`
        // names no roots and therefore removes nothing — name the root in
        // `categories_replace_scope` to clear a tree.
        $roots = [];
        foreach (array_keys($plan['desired']) as $categoryId) {
            if (isset($rootByCategoryId[$categoryId])) {
                $roots[$rootByCategoryId[$categoryId]] = true;
            }
        }

        return array_keys($roots);
    }

    /**
     * Drops entries that are not root categories, reporting each: silently
     * ignoring one would narrow the scope without saying so, and the caller
     * would see links survive a replace with no explanation.
     *
     * @param int[] $declared
     * @return int[]
     */
    private function validRoots(BatchContext $context, string|int $sku, array $declared): array
    {
        $valid = [];
        foreach (array_unique(array_map('intval', $declared)) as $rootId) {
            if ($this->rootCategories->isRoot($rootId)) {
                $valid[] = $rootId;
                continue;
            }
            $context->addMessage($sku, sprintf(
                'Ignored %d in categories_replace_scope: not a root category.',
                $rootId
            ));
        }

        return $valid;
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
