<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Cache;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use ReadyData\Import\Logger\Logger;
use ReadyData\Import\Model\Category\CategoryWriter;
use ReadyData\Import\Model\Category\PathParser;
use ReadyData\Import\Model\ResourceModel\Category as CategoryResource;

/**
 * Request-scoped category path => ID resolver with auto-creation of missing
 * subtrees. Must stay a shared instance (see di.xml).
 *
 * Paths are matched segment-by-segment against store-0 names (exact match,
 * segments pre-decoded and trimmed by PathParser; cache keys use the
 * escaped canonical form via PathParser::buildKey, so a segment containing
 * "/" cannot collide with a deeper path). The first segment must name a level-1
 * root that already exists — this resolver never creates one, so a typo in a
 * product feed cannot spawn a new tree. (The category sync endpoint does create
 * roots, on explicit request; it tells
 * RootCategoryRegistry, and this resolver through
 * {@see forgetPathsUnderRoot()}.) Missing segments below a root are created through
 * CategoryWriter, i.e. through the category repository: the model save
 * maintains path/level/children_count, generates the url_key and category URL
 * rewrites, and handles EE row_id — a deliberate exception to the module's
 * direct-SQL rule, bounded by the low cardinality of distinct new paths per
 * request.
 *
 * Creation happens BEFORE the caller's transaction opens and {@see resolvePaths()}
 * refuses to run inside one, because the repository save opens a nested
 * transaction whose failure would corrupt the outer one. A created category is
 * therefore committed on its own and survives a batch that later rolls back.
 *
 * A category can still be deleted by another request between two of our batches,
 * so every cached entry is re-verified on every call and evicted (and re-resolved
 * on demand) when gone — see {@see evictVanishedCategories()}.
 */
class CategoryPathResolver
{
    /**
     * Cached resolutions, keyed by ROOT first: two roots may share a name, and
     * then "Outdoor Catalog/Men" names two different categories. A flat cache
     * would serve the first root's ID to the second root's path — inside a
     * single request, since that is this cache's whole lifetime.
     *
     * @var array<int, array<string, int>> root entity_id => path cache key => entity_id
     */
    private array $idByPath = [];

    /**
     * @var array<int, array<string, true>> root entity_id => cache keys created by this resolver
     */
    private array $createdPaths = [];

    public function __construct(
        private readonly CategoryResource $categoryResource,
        private readonly CategoryWriter $categoryWriter,
        private readonly RootCategoryRegistry $rootCategories,
        private readonly Logger $logger,
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Bulk-resolve normalized paths, creating missing subtrees below
     * existing roots. One tree query per depth level, never per path.
     *
     * Every failure is reported PER PATH — an unknown root, a root-only path, or
     * a category that could not be created. The caller decides what that means
     * for the product that referenced it; in the product import it trips the
     * additive safety valve, so existing links survive and the batch continues.
     *
     * **MUST NOT be called inside an open transaction**, and refuses to run in
     * one. Creation goes through the category repository, which opens a
     * transaction of its own, and Magento's adapter counts nesting rather than
     * emitting savepoints: a nested rollBack() writes no SQL at all, it only
     * flags the connection and decrements. So a failed save nested inside a
     * caller's transaction leaves its partial rows live, and the caller's COMMIT
     * then fails with "Partial rollback is not supported" instead of the real
     * cause. Reporting the failure per path — rather than throwing, as this used
     * to — is only safe because there is no outer transaction to corrupt, which
     * is why that precondition is enforced here and not merely documented.
     *
     * @param array<string, string[]> $paths cache key => trimmed segments
     * @param int|null $pinnedRootId fixes the first segment to this root
     *        instead of letting the name pick one; see {@see resolveRootId()}
     * @return array<string, array{id: ?int, message: ?string}> keyed like
     *         $paths; id is null when unresolved and message explains why
     * @throws LocalizedException when called inside an open transaction
     */
    public function resolvePaths(array $paths, ?int $pinnedRootId = null): array
    {
        if ($this->resourceConnection->getConnection()->getTransactionLevel() > 0) {
            throw new LocalizedException(__(
                'Category paths cannot be resolved inside an open transaction:'
                . ' creating a category opens a nested one, whose failure would corrupt it.'
                . ' Resolve before the transaction opens (see LockedPreparableInterface).'
            ));
        }

        return $this->walk($paths, true, $pinnedRootId);
    }

    /**
     * Resolve paths WITHOUT creating anything: an unresolvable path simply has
     * no entry in the result.
     *
     * The category sync endpoint uses this rather than {@see resolvePaths()}
     * because implicit creation would produce nodes carrying this resolver's
     * defaults, with no result row and no counter in the endpoint's response —
     * a category the caller never sees and never asked for.
     *
     * @param array<string, string[]> $paths cache key => trimmed segments
     * @param int|null $pinnedRootId see {@see resolvePaths()}
     * @return array<string, int> cache key => entity_id, for resolvable paths only
     */
    public function lookupPaths(array $paths, ?int $pinnedRootId = null): array
    {
        $resolved = [];
        foreach ($this->walk($paths, false, $pinnedRootId) as $key => $result) {
            if ($result['id'] !== null) {
                $resolved[$key] = $result['id'];
            }
        }

        return $resolved;
    }

    /**
     * Drop a cached path => ID mapping.
     *
     * {@see evictVanishedCategories()} only notices categories whose row
     * disappeared. A rename leaves the row in place under a different name, so
     * the cached entry for the OLD path would keep resolving and a later write
     * would land on the wrong category. Whoever renames a category is
     * responsible for forgetting its path here.
     */
    public function forget(string $cacheKey): void
    {
        // Under every root: the caller knows the path that changed meaning, not
        // which tree it was resolved in, and forgetting one entry too many
        // costs a re-query while forgetting one too few writes to the wrong
        // category.
        foreach (array_keys($this->idByPath) as $rootId) {
            unset($this->idByPath[$rootId][$cacheKey], $this->createdPaths[$rootId][$cacheKey]);
        }
    }

    /**
     * Drop every cached path => ID mapping.
     *
     * {@see forget()} handles a rename, where exactly one path changed meaning.
     * A move or a delete re-paths or removes a whole subtree at once, so the
     * stale set is "every key under the old location and every key under the
     * new one" — and for a category addressed by ID we do not even hold the
     * names needed to build those prefixes. Enumerating them is not worth it:
     * this cache exists to collapse repeated lookups within one request, and
     * rebuilding it costs one query per depth level.
     */
    public function forgetAllPaths(): void
    {
        $this->idByPath = [];
        $this->createdPaths = [];
    }

    /**
     * Drop everything cached below a root that was just renamed: "OldRoot/Men"
     * points at a category whose path no longer starts with that name.
     *
     * The root name => ID map itself is not here — it belongs to
     * {@see RootCategoryRegistry}, shared with every other consumer, and the
     * caller that renamed the root invalidates it there.
     */
    public function forgetPathsUnderRoot(string $renamedRootName): void
    {
        // The escaped canonical form, so a root whose name contains "/" cannot
        // match a deeper path's prefix — same reasoning as prefixKey().
        $prefix = PathParser::buildKey([$renamedRootName]) . '/';
        foreach ($this->idByPath as $rootId => $keys) {
            foreach (array_keys($keys) as $key) {
                if (str_starts_with($key, $prefix)) {
                    unset($this->idByPath[$rootId][$key], $this->createdPaths[$rootId][$key]);
                }
            }
        }
    }

    /**
     * @param array<string, string[]> $paths cache key => trimmed segments
     * @param bool $create whether to create the missing tail of a stalled walk
     * @param int|null $pinnedRootId fixes the first segment to this root
     * @return array<string, array{id: ?int, message: ?string}>
     */
    private function walk(array $paths, bool $create, ?int $pinnedRootId = null): array
    {
        $this->evictVanishedCategories();

        $results = [];
        $walks = [];

        foreach ($paths as $key => $segments) {
            if (count($segments) === 1) {
                $results[$key] = [
                    'id' => null,
                    'message' => sprintf(
                        'Path "%s" names a root category; a root cannot be used here.',
                        $segments[0]
                    ),
                ];
                continue;
            }

            // The root has to be settled before the cache can be consulted:
            // the same path key means different categories under different
            // roots, so there is no root-agnostic answer to look up.
            $root = $this->resolveRootId($segments[0], $pinnedRootId);
            if ($root['id'] === null) {
                $results[$key] = ['id' => null, 'message' => $root['message']];
                continue;
            }
            $rootId = $root['id'];

            if (isset($this->idByPath[$rootId][$key])) {
                $results[$key] = ['id' => $this->idByPath[$rootId][$key], 'message' => null];
                continue;
            }

            // depth = number of segments already resolved; parentId = ID of
            // the last resolved segment.
            $walks[$key] = [
                'segments' => $segments,
                'depth' => 1,
                'parentId' => $rootId,
                'root_id' => $rootId,
            ];
        }

        // Walk the existing tree level by level for all paths at once.
        while ($walks) {
            foreach ($walks as $key => &$walk) {
                $this->advanceThroughCache($walk);
                if ($walk['depth'] >= count($walk['segments'])) {
                    $results[$key] = ['id' => $walk['parentId'], 'message' => null];
                    unset($walks[$key]);
                }
            }
            unset($walk);

            if (!$walks) {
                break;
            }

            $children = $this->categoryResource->getChildrenByParentIds(
                array_values(array_unique(array_column($walks, 'parentId')))
            );

            foreach ($walks as $key => &$walk) {
                $segment = $walk['segments'][$walk['depth']];
                $childId = $children[$walk['parentId']][$segment] ?? null;

                if ($childId === null) {
                    // Tree walk stalled: the rest of the chain is missing.
                    // Without $create the only caller is lookupPaths(), which
                    // reports absence by omission and never reads a message.
                    $results[$key] = $create
                        ? $this->createChain($walk)
                        : ['id' => null, 'message' => null];
                    unset($walks[$key]);
                    continue;
                }

                $this->idByPath[$walk['root_id']][$this->prefixKey($walk)] = $childId;
                $walk['parentId'] = $childId;
                $walk['depth']++;

                if ($walk['depth'] >= count($walk['segments'])) {
                    $results[$key] = ['id' => $childId, 'message' => null];
                    unset($walks[$key]);
                }
            }
            unset($walk);
        }

        return $results;
    }

    /**
     * Validate numeric category references in bulk. Usable = existing and
     * below a root (level >= 2); root and unknown IDs are absent from the
     * result.
     *
     * @param int[] $categoryIds
     * @return array<int, bool> entity_id => true for usable categories
     */
    public function validateIds(array $categoryIds): array
    {
        $this->evictVanishedCategories();

        if (!$categoryIds) {
            return [];
        }

        $valid = [];
        $existing = $this->categoryResource->getExistingByIds(array_values(array_unique($categoryIds)));
        foreach ($existing as $id => $row) {
            if ($row['level'] >= 2) {
                $valid[$id] = true;
            }
        }

        return $valid;
    }

    /**
     * Which of these category IDs no longer exist.
     *
     * For a caller that resolved paths in an earlier phase and is about to write
     * against the IDs it got: a category deleted in between would reach the
     * insert as a stale ID and fail on a foreign key, taking a whole batch with
     * it. Asked as "what is gone" rather than "what is usable" because the empty
     * answer — nothing vanished — is the normal one, and because
     * {@see validateIds()} answers a different question about references the
     * CALLER supplied, where an unknown ID is the caller's mistake rather than a
     * concurrent deletion.
     *
     * @param int[] $categoryIds
     * @return array<int, true> entity_id => true for categories that are gone
     */
    public function findVanished(array $categoryIds): array
    {
        if (!$categoryIds) {
            return [];
        }

        $ids = array_values(array_unique($categoryIds));
        $existing = $this->categoryResource->getExistingByIds($ids);

        $vanished = [];
        foreach ($ids as $id) {
            if (!isset($existing[$id])) {
                $vanished[$id] = true;
            }
        }

        return $vanished;
    }

    /**
     * Advance a walk through prefixes already resolved in the cache.
     *
     * @param array{segments: string[], depth: int, parentId: int, root_id: int} $walk
     */
    private function advanceThroughCache(array &$walk): void
    {
        while ($walk['depth'] < count($walk['segments'])) {
            $cachedId = $this->idByPath[$walk['root_id']][$this->prefixKey($walk)] ?? null;
            if ($cachedId === null) {
                return;
            }
            $walk['parentId'] = $cachedId;
            $walk['depth']++;
        }
    }

    /**
     * The root a path's first segment resolves to, honouring a pin, in the
     * messages this resolver reports to its caller.
     *
     * The mechanics live in {@see RootCategoryRegistry::resolve()}; what is local
     * to this class is the POLICY it passes: ambiguity is not refused here, it is
     * settled by taking the lowest entity ID. That is a read's answer, and it is
     * why the pin exists — two roots with one name are two different catalogs,
     * and picking the older one is a guess. A write refuses instead; see
     * CategorySyncService.
     *
     * @return array{id: ?int, message: ?string}
     */
    private function resolveRootId(string $firstSegment, ?int $pinnedRootId): array
    {
        $root = $this->rootCategories->resolve($firstSegment, $pinnedRootId, false);

        return match ($root['outcome']) {
            RootCategoryRegistry::OUTCOME_OK => ['id' => $root['id'], 'message' => null],
            RootCategoryRegistry::OUTCOME_PIN_NOT_ROOT => [
                'id' => null,
                'message' => sprintf('Root category ID %d does not exist.', $pinnedRootId),
            ],
            RootCategoryRegistry::OUTCOME_PIN_NAME_MISMATCH => [
                'id' => null,
                'message' => sprintf(
                    'Path starts with root "%s" but root category ID %d is named "%s".',
                    $firstSegment,
                    $pinnedRootId,
                    $root['pinnedName']
                ),
            ],
            default => [
                'id' => null,
                'message' => sprintf(
                    'Unknown root category "%s" — root categories are not auto-created.',
                    $firstSegment
                ),
            ],
        };
    }

    /**
     * Create the missing tail of a stalled walk, parent-first.
     *
     * A failure is reported PER PATH rather than thrown. That is safe only
     * because {@see resolvePaths()} guarantees there is no open transaction —
     * see its docblock for what a nested repository rollback does to one, and
     * why this method used to have no choice but to throw.
     *
     * Two failure shapes are handled differently. A slug collision with an
     * existing sibling is the common one and is PRE-CHECKED, because the
     * repository reports it as a deep exception whose message names neither
     * category. Everything else — a required attribute with no default, a
     * throwing category-save observer, EE staging, a genuine race — is caught.
     *
     * A partially created chain PERSISTS: if "Men" is created and "Men/Shirts"
     * then fails, "Men" is real, committed and cached, and appears in storefront
     * navigation. It is not rolled back, because it was never in a transaction.
     * A retry completes the chain rather than duplicating it.
     *
     * @param array{segments: string[], depth: int, parentId: int, root_id: int} $walk
     * @return array{id: ?int, message: ?string}
     */
    private function createChain(array $walk): array
    {
        $parentId = $walk['parentId'];
        $rootId = $walk['root_id'];
        // Whether $parentId is a category we just created. A category created
        // moments ago is empty, so nothing can collide beneath it and the
        // pre-check below is a query with a known answer. Only the first parent
        // in a chain — and any pre-existing one we land on — needs asking.
        $parentIsFresh = false;

        for ($i = $walk['depth'], $count = count($walk['segments']); $i < $count; $i++) {
            $prefixKey = PathParser::buildKey(array_slice($walk['segments'], 0, $i + 1));

            // A chain created moments ago by another path may already cover
            // this prefix.
            if (isset($this->idByPath[$rootId][$prefixKey])) {
                $parentId = $this->idByPath[$rootId][$prefixKey];
                $parentIsFresh = false;
                continue;
            }

            $segment = $walk['segments'][$i];

            if (!$parentIsFresh) {
                $conflict = $this->categoryWriter->findNewChildConflict($parentId, $segment);
                if ($conflict !== null) {
                    return [
                        'id' => null,
                        'message' => sprintf(
                            'Category "%s" was not created: its URL key "%s" is already used by'
                            . ' category ID %d. Give that category a different name, or create this'
                            . ' one through the category endpoint with an explicit url_key.',
                            $prefixKey,
                            $conflict['value'],
                            $conflict['category_id']
                        ),
                    ];
                }
            }

            try {
                // CategoryWriter owns the defaults and the store-0 emulation
                // the repository needs; see createBare().
                $parentId = $this->categoryWriter->createBare($parentId, $segment);
            } catch (\Throwable $e) {
                $this->logger->error(
                    sprintf('Failed to create category "%s": %s', $prefixKey, $e->getMessage()),
                    ['exception' => $e]
                );

                return [
                    'id' => null,
                    'message' => sprintf(
                        'Category "%s" could not be created: %s',
                        $prefixKey,
                        $e->getMessage()
                    ),
                ];
            }

            $this->idByPath[$rootId][$prefixKey] = $parentId;
            $this->createdPaths[$rootId][$prefixKey] = true;
            $parentIsFresh = true;
        }

        return ['id' => $parentId, 'message' => null];
    }

    /**
     * Drop cached entries whose category no longer exists. They are re-resolved
     * — and, where the caller creates, re-created — on the next resolution that
     * needs them.
     *
     * An entry goes stale when its category is **deleted by another request**
     * after we cached it. The product import releases its locks between batches,
     * so a category sync can commit a delete in that window. Without this the
     * next batch would write a product assignment against an ID that is gone,
     * which fails on the catalog_category_product foreign key and rolls the batch
     * back for something that is recoverable.
     *
     * A category this resolver created no longer goes stale by being rolled back
     * with its batch: creation happens before the transaction opens (see
     * {@see resolvePaths()}), so it is committed on its own and outlives a failed
     * batch. It can still be deleted externally like any other, which is why the
     * created/pre-existing distinction survives — in the log message only.
     *
     * One query for every cached ID, once per resolution call.
     */
    private function evictVanishedCategories(): void
    {
        if (!$this->idByPath) {
            return;
        }

        $cachedIds = array_values(array_unique(array_merge(
            ...array_map('array_values', array_values($this->idByPath))
        )));
        if (!$cachedIds) {
            return;
        }

        $existing = $this->categoryResource->getExistingByIds($cachedIds);

        foreach ($this->idByPath as $rootId => $keys) {
            foreach ($keys as $key => $id) {
                if (isset($existing[$id])) {
                    continue;
                }
                $wasCreatedHere = isset($this->createdPaths[$rootId][$key]);
                unset($this->idByPath[$rootId][$key], $this->createdPaths[$rootId][$key]);
                $this->logger->info(sprintf(
                    $wasCreatedHere
                        ? 'Auto-created category "%s" (ID %d) was removed after this import created it;'
                            . ' it will be re-created on demand.'
                        : 'Category "%s" (ID %d) was removed by another request during this import;'
                            . ' its path will be resolved again.',
                    $key,
                    $id
                ));
            }
        }
    }

    /**
     * Cache key of the next unresolved prefix of a walk — the escaped
     * canonical form, so segments containing "/" cannot collide with a
     * deeper path.
     *
     * @param array{segments: string[], depth: int, parentId: int, root_id: int} $walk
     */
    private function prefixKey(array $walk): string
    {
        return PathParser::buildKey(array_slice($walk['segments'], 0, $walk['depth'] + 1));
    }
}
