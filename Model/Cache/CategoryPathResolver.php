<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Cache;

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
 * roots, on explicit request; it tells this resolver through
 * {@see forgetRoots()}.) Missing segments below a root are created through
 * CategoryWriter, i.e. through the category repository: the model save
 * maintains path/level/children_count, generates the url_key and category URL
 * rewrites, and handles EE row_id — a deliberate exception to the module's
 * direct-SQL rule, bounded by the low cardinality of distinct new paths per
 * request.
 *
 * Categories created inside a batch transaction vanish if that batch rolls
 * back; entries this resolver created itself are therefore re-verified on
 * every call and evicted (and re-created on demand) when gone.
 */
class CategoryPathResolver
{
    /**
     * @var array<string, int> normalized path cache key => entity_id
     */
    private array $idByPath = [];

    /**
     * @var array<string, true> cache keys created by this resolver
     */
    private array $createdPaths = [];

    /**
     * @var array<string, int>|null store-0 root name => entity_id
     */
    private ?array $roots = null;

    public function __construct(
        private readonly CategoryResource $categoryResource,
        private readonly CategoryWriter $categoryWriter,
        private readonly Logger $logger
    ) {
    }

    /**
     * Bulk-resolve normalized paths, creating missing subtrees below
     * existing roots. One tree query per depth level, never per path.
     *
     * A path that cannot be resolved (unknown root, root-only path) is reported
     * per path. A path that could not be CREATED throws — see
     * {@see createChain()} for why continuing is not safe.
     *
     * @param array<string, string[]> $paths cache key => trimmed segments
     * @return array<string, array{id: ?int, message: ?string}> keyed like
     *         $paths; id is null when unresolved and message explains why
     * @throws LocalizedException when a category could not be created
     */
    public function resolvePaths(array $paths): array
    {
        return $this->walk($paths, true);
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
     * @return array<string, int> cache key => entity_id, for resolvable paths only
     */
    public function lookupPaths(array $paths): array
    {
        $resolved = [];
        foreach ($this->walk($paths, false) as $key => $result) {
            if ($result['id'] !== null) {
                $resolved[$key] = $result['id'];
            }
        }

        return $resolved;
    }

    /**
     * Drop a cached path => ID mapping.
     *
     * {@see evictRolledBackCreations()} only notices categories whose row
     * disappeared. A rename leaves the row in place under a different name, so
     * the cached entry for the OLD path would keep resolving and a later write
     * would land on the wrong category. Whoever renames a category is
     * responsible for forgetting its path here.
     */
    public function forget(string $cacheKey): void
    {
        unset($this->idByPath[$cacheKey], $this->createdPaths[$cacheKey]);
    }

    /**
     * Drop the memoized root map, and optionally everything cached below a root
     * that was just renamed.
     *
     * The root map is not covered by {@see forget()}: a root's own name is never
     * a cache key here (single-segment paths are refused outright), so a root
     * created or renamed by the category sync endpoint would otherwise stay
     * invisible — or keep resolving under its old name — for the rest of the
     * request. Deeper keys have to go too: "OldRoot/Men" points at a category
     * whose path no longer starts with that name.
     */
    public function forgetRoots(?string $renamedRootName = null): void
    {
        $this->roots = null;

        if ($renamedRootName === null) {
            return;
        }

        // The escaped canonical form, so a root whose name contains "/" cannot
        // match a deeper path's prefix — same reasoning as prefixKey().
        $prefix = PathParser::buildKey([$renamedRootName]) . '/';
        foreach (array_keys($this->idByPath) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->idByPath[$key], $this->createdPaths[$key]);
            }
        }
    }

    /**
     * @param array<string, string[]> $paths cache key => trimmed segments
     * @param bool $create whether to create the missing tail of a stalled walk
     * @return array<string, array{id: ?int, message: ?string}>
     */
    private function walk(array $paths, bool $create): array
    {
        $this->evictRolledBackCreations();

        $results = [];
        $walks = [];

        foreach ($paths as $key => $segments) {
            if (isset($this->idByPath[$key])) {
                $results[$key] = ['id' => $this->idByPath[$key], 'message' => null];
                continue;
            }

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

            $rootId = $this->getRoots()[$segments[0]] ?? null;
            if ($rootId === null) {
                $results[$key] = [
                    'id' => null,
                    'message' => sprintf(
                        'Unknown root category "%s" — root categories are not auto-created.',
                        $segments[0]
                    ),
                ];
                continue;
            }

            // depth = number of segments already resolved; parentId = ID of
            // the last resolved segment.
            $walks[$key] = ['segments' => $segments, 'depth' => 1, 'parentId' => $rootId];
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

                $this->idByPath[$this->prefixKey($walk)] = $childId;
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
        $this->evictRolledBackCreations();

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
     * Advance a walk through prefixes already resolved in the cache.
     *
     * @param array{segments: string[], depth: int, parentId: int} $walk
     */
    private function advanceThroughCache(array &$walk): void
    {
        while ($walk['depth'] < count($walk['segments'])) {
            $cachedId = $this->idByPath[$this->prefixKey($walk)] ?? null;
            if ($cachedId === null) {
                return;
            }
            $walk['parentId'] = $cachedId;
            $walk['depth']++;
        }
    }

    /**
     * Create the missing tail of a stalled walk, parent-first.
     *
     * A creation failure is re-thrown, NOT reported per path. The repository
     * save runs its own transaction, so when it fails inside a caller's open
     * transaction the nested rollBack marks the connection as partially rolled
     * back: every later write, and the caller's own commit, would throw
     * "Partial rollback is not supported" instead of the real cause. Continuing
     * on that connection is not an option, so the failure has to reach the
     * caller's rollback handler while it can still report the actual reason.
     *
     * @param array{segments: string[], depth: int, parentId: int} $walk
     * @return array{id: ?int, message: ?string}
     * @throws LocalizedException
     */
    private function createChain(array $walk): array
    {
        $parentId = $walk['parentId'];

        for ($i = $walk['depth'], $count = count($walk['segments']); $i < $count; $i++) {
            $prefixKey = PathParser::buildKey(array_slice($walk['segments'], 0, $i + 1));

            // A chain created moments ago by another path may already cover
            // this prefix.
            if (isset($this->idByPath[$prefixKey])) {
                $parentId = $this->idByPath[$prefixKey];
                continue;
            }

            $segment = $walk['segments'][$i];
            try {
                // CategoryWriter owns the defaults and the store-0 emulation
                // the repository needs; see createBare().
                $parentId = $this->categoryWriter->createBare($parentId, $segment);
            } catch (\Throwable $e) {
                $this->logger->error(
                    sprintf('Failed to create category "%s": %s', $prefixKey, $e->getMessage()),
                    ['exception' => $e]
                );

                throw new LocalizedException(
                    __('Failed to create category "%1": %2', $prefixKey, $e->getMessage()),
                    $e
                );
            }

            $this->idByPath[$prefixKey] = $parentId;
            $this->createdPaths[$prefixKey] = true;
        }

        return ['id' => $parentId, 'message' => null];
    }

    /**
     * Drop cached entries for categories this resolver created that no
     * longer exist — their creating batch was rolled back. They are
     * re-created on the next resolution that needs them.
     */
    private function evictRolledBackCreations(): void
    {
        if (!$this->createdPaths) {
            return;
        }

        $createdIds = array_intersect_key($this->idByPath, $this->createdPaths);
        $existing = $this->categoryResource->getExistingByIds(array_values($createdIds));

        foreach ($createdIds as $key => $id) {
            if (!isset($existing[$id])) {
                unset($this->idByPath[$key], $this->createdPaths[$key]);
                $this->logger->info(sprintf(
                    'Auto-created category "%s" (ID %d) was rolled back with its batch; it will be re-created on demand.',
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
     * @param array{segments: string[], depth: int, parentId: int} $walk
     */
    private function prefixKey(array $walk): string
    {
        return PathParser::buildKey(array_slice($walk['segments'], 0, $walk['depth'] + 1));
    }

    /**
     * @return array<string, int>
     */
    private function getRoots(): array
    {
        // This resolver never creates a root, so a rollback of its own work
        // cannot make this stale. A caller that creates or renames one has to
        // say so through forgetRoots().
        return $this->roots ??= $this->categoryResource->getRootCategories();
    }
}
