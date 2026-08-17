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
use ReadyData\Import\Model\ImportLocks;
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
 * **Paths are resolved before the transaction opens**, in
 * {@see prepareUnderLocks()}, because creating a category goes through the
 * category repository and that opens a transaction of its own — which cannot
 * nest inside the batch's without poisoning the connection. See
 * {@see LockedPreparableInterface}. One consequence is worth stating plainly:
 * entity IDs do not exist that early, so the resolution covers every path a
 * VALID product references rather than only those of products the batch will
 * actually write. A product EntityProcessor later rejects — unknown type,
 * unknown attribute set, no name, EE staging — can therefore leave a category
 * behind that nothing links to. That is the same trade the module already makes
 * for media downloads, and it is bounded the same way: only paths that were
 * already missing create anything, a product-less category is inert, and
 * creation is idempotent, so a retry resolves rather than duplicates.
 *
 * Publishes to the context data bag:
 *  - "category_resolved_paths": path key => {id, message}, the resolution
 *    prepareUnderLocks() performed; consumed by this step's own process().
 *  - "affected_category_ids": int[] category IDs whose product set changed;
 *    consumed by InvalidationHandler for FPC tag cleaning.
 *  - "affected_product_ids": int[] product IDs whose category links changed;
 *    consumed by ImportEventDispatcher for the category-changed event.
 */
class CategoryLinkProcessor implements ProcessorInterface, LockAwareInterface, LockedPreparableInterface
{
    public const CONTEXT_RESOLVED_PATHS = 'category_resolved_paths';
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

    /**
     * The tree lock, and only when a path in this batch does not resolve to a
     * category that is already there.
     *
     * The old test was "a `categories` field is present", which a feed sends on
     * every product of every push while the tree it names has existed since the
     * first one. Measured on prelive, that cost 322 ms of hold and 572 ms of a
     * competitor's wait per batch, to guard nothing: resolving an existing path
     * is a read, and only the create is a race. It is the most expensive of the
     * four locks — the create behind it runs through the category repository —
     * so it is also the one most worth not taking.
     *
     * {@see CategoryPathResolver::lookupPaths()} is the same walk
     * {@see process()} makes, minus the creating, and the resolver is shared and
     * caches — so the reads move OUT of the lock rather than being added to it.
     *
     * Numeric IDs and an empty `categories` array are deliberately not counted:
     * neither can create a category.
     */
    public function requiredLocks(BatchContext $context): array
    {
        $collected = $this->collectReferences($context, false);
        if (!$collected['paths']) {
            return [];
        }

        $resolved = $this->pathResolver->lookupPaths($collected['paths'], $context->getRootCategoryId());
        if (count($resolved) === count($collected['paths'])) {
            return [];
        }

        // The rewrite lock comes too, because creating a category is not only a
        // tree write: the repository save makes core's category-rewrite observer
        // write into `url_rewrite`, which the module's suppression plugin does
        // not cover (it only silences the PRODUCT observer). Category and product
        // URL suffixes are both ".html" by default, so a new category "Men/Shirts"
        // claims `men/shirts.html` — the same request path as the category-path
        // rewrite of a product slugged "shirts" under "Men".
        return [ImportLocks::CATEGORY_TREE, ImportLocks::URL_REWRITE];
    }

    /**
     * Resolve the batch's category paths — creating what is missing — under the
     * batch's locks but before its transaction opens.
     *
     * This is here rather than in {@see process()} because
     * {@see \ReadyData\Import\Model\Category\CategoryWriter::createBare()} goes
     * through the category repository, which opens its own transaction; nested
     * inside the batch's, a failure there flags the connection and the batch's
     * COMMIT dies with an unrelated error instead of the real cause. Run first,
     * the repository resolves cleanly and a failure is reportable against the
     * product that caused it. See {@see LockedPreparableInterface}.
     *
     * $reportProblems is false: `process()` remains the one place a per-product
     * message is recorded, or every warning in this step would be emitted twice.
     *
     * The bag key is set unconditionally, so a missing key means the phase did
     * not run at all rather than that it found nothing.
     */
    public function prepareUnderLocks(BatchContext $context): void
    {
        $collected = $this->collectReferences($context, false);
        if (!$collected['paths']) {
            $context->set(self::CONTEXT_RESOLVED_PATHS, []);

            return;
        }

        $context->set(self::CONTEXT_RESOLVED_PATHS, $this->resolvePaths($context, $collected['paths']));
    }

    public function process(BatchContext $context): void
    {
        $collected = $this->collectReferences($context, true);

        $refsBySku = [];
        foreach ($collected['refs'] as $sku => $refs) {
            // Products whose row could not be resolved are dropped HERE, so no
            // link is written for a product this batch is not writing. Note the
            // paths of such a product were still RESOLVED — and possibly created
            // — by prepareUnderLocks(), which runs before entity IDs exist; see
            // the class docblock.
            $entityId = $context->getEntityId($sku);
            if ($entityId !== null) {
                $refsBySku[$sku] = ['entity_id' => $entityId] + $refs;
            }
        }

        if (!$refsBySku) {
            return;
        }

        $uniquePaths = [];
        $uniqueIds = [];
        foreach ($refsBySku as $refs) {
            foreach (array_keys($refs['paths']) as $key) {
                $uniquePaths[$key] = $collected['paths'][$key];
            }
            foreach (array_keys($refs['ids']) as $categoryId) {
                $uniqueIds[$categoryId] = true;
            }
        }

        // Resolved by prepareUnderLocks(), before the transaction. The map is a
        // superset of $uniquePaths — that phase collects from every valid
        // product, this one from the subset with an entity row — but the
        // fallback keeps a missing entry from being a fatal index error if the
        // phase was ever skipped.
        $pathResults = $context->get(self::CONTEXT_RESOLVED_PATHS, []);

        $validIds = $this->pathResolver->validateIds(array_keys($uniqueIds));

        // Path IDs were resolved in an earlier phase, so a category deleted in
        // between would otherwise reach the insert as a stale ID and fail the
        // whole batch on a foreign key — the failure class splitting the phase
        // exists to remove. Asked as "which of these are gone" rather than folded
        // into validateIds(): that call answers a different question about the
        // payload's own numeric references, and an unknown ID the caller sent is
        // not the same event as a category that vanished under us.
        $pathIds = [];
        foreach ($pathResults as $result) {
            if ($result['id'] !== null) {
                $pathIds[$result['id']] = true;
            }
        }
        $vanishedPathIds = $pathIds ? $this->pathResolver->findVanished(array_keys($pathIds)) : [];

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
                $result = $pathResults[$key] ?? [
                    'id' => null,
                    'message' => sprintf('Category "%s" was not resolved for this batch.', $key),
                ];
                if ($result['id'] === null) {
                    $context->addMessage($sku, $result['message']);
                    $partial = true;
                } elseif (isset($vanishedPathIds[$result['id']])) {
                    // Resolved in the earlier phase, deleted before we could write
                    // against it. Reported rather than written, because the insert
                    // would fail on the foreign key and take the batch with it.
                    $context->addMessage($sku, sprintf(
                        'Category "%s" was removed before its links could be written; skipped.',
                        $key
                    ));
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

    /**
     * Parse every product's `categories` entries once, into the unique paths and
     * IDs the batch names plus a per-SKU index of which it named.
     *
     * Shared by {@see requiredLocks()} and {@see process()} so the lock decision
     * and the write can never disagree about what the payload says — a
     * predicate parsing paths even slightly differently would answer for a
     * different set of categories than the one that gets created.
     *
     * @param bool $reportProblems whether to attach per-product messages; false
     *        for the predicate pass, which runs before the write and must not
     *        report a problem the write is about to report again
     * @return array{paths: array<string, string[]>, ids: array<int, true>,
     *         refs: array<string, array{paths: array<string, true>,
     *         ids: array<int, true>, partial: bool}>}
     */
    private function collectReferences(BatchContext $context, bool $reportProblems): array
    {
        $uniquePaths = [];
        $uniqueIds = [];
        $refsBySku = [];

        foreach ($context->getValidProducts() as $sku => $product) {
            if ($product->getCategories() === null) {
                continue;
            }

            $refs = ['paths' => [], 'ids' => [], 'partial' => false];
            foreach ($product->getCategories() as $reference) {
                $parsed = $this->pathParser->parse((string)$reference);
                if ($parsed === null) {
                    if ($reportProblems) {
                        $context->addMessage($sku, 'Empty category reference skipped.');
                    }
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

        return ['paths' => $uniquePaths, 'ids' => $uniqueIds, 'refs' => $refsBySku];
    }

    /**
     * Resolve the batch's paths, creating missing subtrees only if this batch
     * reserved the right to.
     *
     * Without the lock the walk is the non-creating one. A path that resolved
     * during {@see requiredLocks()} and does not now was deleted in between;
     * creating it here would be the unguarded read-then-create the lock exists
     * to prevent, so it is reported instead. The product is then applied
     * additively by the caller — its existing links survive — and the retry,
     * whose predicate now sees the gap, takes the lock and creates it.
     *
     * @param array<string, string[]> $paths cache key => segments
     * @return array<string, array{id: ?int, message: ?string}> keyed like $paths
     */
    private function resolvePaths(BatchContext $context, array $paths): array
    {
        if ($context->holdsLock(ImportLocks::CATEGORY_TREE)) {
            return $this->pathResolver->resolvePaths($paths, $context->getRootCategoryId());
        }

        $resolved = $this->pathResolver->lookupPaths($paths, $context->getRootCategoryId());

        $results = [];
        foreach (array_keys($paths) as $key) {
            $results[$key] = isset($resolved[$key])
                ? ['id' => $resolved[$key], 'message' => null]
                : [
                    'id' => null,
                    'message' => sprintf(
                        'Category "%s" stopped resolving after this batch decided it had nothing to create;'
                        . ' it was not created here. Re-send to have it created.',
                        $key
                    ),
                ];
        }

        return $results;
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
