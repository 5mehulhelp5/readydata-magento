<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model;

use Magento\Catalog\Model\Category as CategoryModel;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;
use ReadyData\Import\Api\Data\CategoryDefinitionInterface;
use ReadyData\Import\Api\Data\CategorySyncResponseInterface;
use ReadyData\Import\Api\Data\CategorySyncResponseInterfaceFactory;
use ReadyData\Import\Api\Data\CategorySyncResultInterface;
use ReadyData\Import\Api\Data\CategorySyncResultInterfaceFactory;
use ReadyData\Import\Api\Data\ImportSettingsInterface;
use ReadyData\Import\Logger\Logger;
use ReadyData\Import\Model\Cache\CategoryPathResolver;
use ReadyData\Import\Model\Cache\StoreWebsiteMap;
use ReadyData\Import\Model\Category\CategoryWriter;
use ReadyData\Import\Model\Category\PathParser;
use ReadyData\Import\Model\Exception\CategoryValidationException;
use ReadyData\Import\Model\Indexer\CategoryInvalidationHandler;
use ReadyData\Import\Model\ResourceModel\Category as CategoryResource;

/**
 * Creates/updates categories to match the caller (the system of record).
 * Standalone: no product import required.
 *
 * Same shape as AttributeSyncService rather than the product pipeline — the
 * pipeline's BatchContext is keyed by SKU throughout — with one difference the
 * hierarchy forces: entries are processed shallowest-path-first, so a parent
 * sent in the same request is committed before the child that needs it.
 *
 * Structural changes are all here, each gated on the caller being explicit about
 * it. A missing category is created (including a level-1 root, which is simply a
 * child of the catalog tree root here). A category is reparented only when the
 * payload names a destination through parent_path/parent_category_id — never
 * because its `path` disagrees with where it is, since a caller replaying a path
 * they stored before an earlier rename or move would then undo it. And a category
 * is deleted only on an explicit `delete` flag, with a second flag required
 * before its descendants go with it. Moves and deletes each have their own config
 * switch, both off by default.
 *
 * Deletes run after every other entry: a payload that creates something under a
 * parent it also removes only reads one way round.
 */
class CategorySyncService
{
    /**
     * Shared with the product import: both mutate the category tree, through
     * the same non-transactional relative-update code, with no unique key on
     * (parent_id, name) to fall back on. Two lock names would let them run
     * concurrently and duplicate siblings.
     */
    public const LOCK_NAME = ImportService::TREE_WRITE_LOCK_NAME;
    private const LOCK_TIMEOUT_SEC = 10;

    public function __construct(
        private readonly Config $config,
        private readonly LockManagerInterface $lockManager,
        private readonly ResourceConnection $resourceConnection,
        private readonly CategoryValidator $validator,
        private readonly CategoryPathResolver $pathResolver,
        private readonly CategoryResource $categoryResource,
        private readonly CategoryWriter $writer,
        private readonly StoreWebsiteMap $storeWebsiteMap,
        private readonly CategoryInvalidationHandler $invalidationHandler,
        private readonly CategorySyncResponseInterfaceFactory $responseFactory,
        private readonly CategorySyncResultInterfaceFactory $resultFactory,
        private readonly Logger $logger
    ) {
    }

    /**
     * @param CategoryDefinitionInterface[] $categories
     * @throws LocalizedException
     */
    public function sync(array $categories, ?ImportSettingsInterface $settings = null): CategorySyncResponseInterface
    {
        $startedAt = hrtime(true);
        $received = count($categories);
        $entries = $this->prepareEntries($categories);

        if (!$entries) {
            throw new LocalizedException(__('The request contains no category definitions.'));
        }

        if (!$this->config->isEnabled() || !$this->config->isCategorySyncEnabled()) {
            return $this->buildResponse($received, $this->disabledResults($entries), $startedAt);
        }

        $storeId = $this->storeWebsiteMap->resolveStoreId($settings?->getStoreViewCode());
        // The tree the selected store view actually shows. Resolved once, up
        // front: a store view whose group has no root category makes every
        // store-scoped write unverifiable, which is a request-level problem the
        // caller cannot fix per category.
        $storeRootId = $storeId === 0 ? null : $this->storeWebsiteMap->getRootCategoryId($storeId);
        $continueOnError = $settings?->getContinueOnError() ?? $this->config->isContinueOnError();

        if (!$this->lockManager->lock(self::LOCK_NAME, self::LOCK_TIMEOUT_SEC)) {
            // Wording matches the product endpoint verbatim: callers already
            // recognise it and back off.
            throw new LocalizedException(__('Another import is already running. Try again later.'));
        }

        $results = [];
        $aborted = false;
        $state = self::newState();
        // Deletes are pulled out and run last: a request that creates a child
        // under a parent it also deletes only makes sense in that order, and a
        // caller reorganizing a subtree wants their moves and updates to land
        // before the old nodes go.
        [$writeEntries, $deleteEntries] = self::partitionDeletes($entries);

        try {
            foreach ($this->bucketByDepth($writeEntries) as $bucket) {
                $parents = $this->resolveParents($bucket);
                // Where each entry wants its parent to BE, as opposed to where
                // its path says it currently is. Resolved per bucket for the
                // same reason parents are: a destination created in a shallower
                // bucket is already committed and must be visible.
                $destinations = $this->resolveDestinations($bucket);
                // One children-by-name query for the whole bucket instead of
                // one per entry, dropped again as soon as an entry writes:
                // a rename inside this bucket can move a name from one ID to
                // another, and a stale map would create a duplicate sibling.
                $siblings = null;

                foreach ($bucket as $entry) {
                    if ($aborted) {
                        $results[] = $this->abortedResult($entry);
                        continue;
                    }
                    if ($this->hasRenamedAncestor($entry, $state['renamedPaths'])) {
                        $results[] = $this->result(
                            $entry,
                            CategorySyncResultInterface::STATUS_SKIPPED,
                            CategorySyncResultInterface::REASON_STALE_PARENT_PATH,
                            ['An ancestor was renamed earlier in this request; re-send this category'
                                . ' under its new path.']
                        );
                        continue;
                    }

                    $siblings ??= $this->loadSiblings($parents);

                    // `use (&$state)`, not an arrow function: an arrow function
                    // captures by value, which would hand processOne() a copy and
                    // silently drop every move's subtree bookkeeping.
                    $result = $this->inTransaction(
                        $entry,
                        function () use (
                            $entry,
                            $parents,
                            $destinations,
                            $siblings,
                            $storeId,
                            $storeRootId,
                            &$state
                        ): CategorySyncResultInterface {
                            return $this->processOne(
                                $entry,
                                $parents,
                                $destinations,
                                $siblings,
                                $storeId,
                                $storeRootId,
                                $state
                            );
                        }
                    );

                    if (in_array(
                        $result->getStatus(),
                        [CategorySyncResultInterface::STATUS_CREATED, CategorySyncResultInterface::STATUS_UPDATED],
                        true
                    )) {
                        $state['touched'][] = (int)$result->getEntityId();
                        $siblings = null;
                    }
                    if ($result->getStatus() === CategorySyncResultInterface::STATUS_ERROR && !$continueOnError) {
                        $aborted = true;
                    }
                    $results[] = $result;
                }
            }

            foreach (
                $this->processDeletes(
                    $deleteEntries,
                    $storeId,
                    $storeRootId,
                    $continueOnError,
                    $aborted,
                    $state
                ) as $result
            ) {
                $results[] = $result;
            }
        } finally {
            $this->lockManager->unlock(self::LOCK_NAME);
            // In the finally, not after it: every category here is already
            // committed, so if a later bucket throws, skipping invalidation
            // would leave the storefront serving stale pages for work that
            // did land.
            $this->invalidate($state['touched'], $state['removed']);
        }

        $response = $this->buildResponse($received, $results, $startedAt);
        $this->logger->info(sprintf(
            'Category sync finished: %d received, %d created, %d updated, %d unchanged, %d deleted,'
            . ' %d skipped, %d failed in %d ms',
            $response->getReceived(),
            $response->getCreated(),
            $response->getUpdated(),
            $response->getUnchanged(),
            $response->getDeleted(),
            $response->getSkipped(),
            $response->getFailed(),
            $response->getElapsedMs()
        ));

        return $response;
    }

    /**
     * Mutable per-request bookkeeping, threaded by reference rather than held on
     * the (shared) service instance.
     *
     * - `touched`: categories written, for indexer/FPC invalidation.
     * - `removed`: categories deleted, already expanded to their pre-delete
     *   subtree — the rows are gone afterwards, so nothing can rebuild it.
     * - `movedSubtrees`: every id that was inside a subtree this request moved.
     * - `renamedPaths`: canonical paths whose meaning changed, for
     *   {@see hasRenamedAncestor()}.
     *
     * @return array{touched: int[], removed: int[], movedSubtrees: array<int, true>,
     *     renamedPaths: array<string, true>}
     */
    private static function newState(): array
    {
        return ['touched' => [], 'removed' => [], 'movedSubtrees' => [], 'renamedPaths' => []];
    }

    /**
     * Split the entries into the ones that write values and the ones that delete.
     *
     * A rejected entry stays on the write side whatever its `delete` flag says:
     * its payload never passed validation, so the flag is not to be trusted, and
     * the write loop is what already reports validation failures.
     *
     * @param array<int, array{definition: ?CategoryDefinitionInterface, error: ?array, ...}> $entries
     * @return array{0: array<int, array{...}>, 1: array<int, array{...}>}
     */
    private static function partitionDeletes(array $entries): array
    {
        $writes = [];
        $deletes = [];
        foreach ($entries as $entry) {
            if ($entry['error'] === null && $entry['definition']->getDelete() === 1) {
                $deletes[] = $entry;
                continue;
            }
            $writes[] = $entry;
        }

        return [$writes, $deletes];
    }

    /**
     * One transaction per category: a failure leaves no half-written category,
     * and the rollBack in the catch is what clears the connection's
     * partial-rollback flag after a repository save failed inside it.
     *
     * @param callable():CategorySyncResultInterface $work
     */
    private function inTransaction(array $entry, callable $work): CategorySyncResultInterface
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->beginTransaction();
        try {
            $result = $work();
            $connection->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->rollBackQuietly($connection);
            $this->logger->error(
                sprintf('Category "%s" sync failed: %s', $entry['label'], $e->getMessage()),
                ['exception' => $e]
            );

            return $this->result(
                $entry,
                CategorySyncResultInterface::STATUS_ERROR,
                null,
                [sprintf('Sync failed: %s', $e->getMessage())]
            );
        }
    }

    private function abortedResult(array $entry): CategorySyncResultInterface
    {
        return $this->result(
            $entry,
            CategorySyncResultInterface::STATUS_SKIPPED,
            CategorySyncResultInterface::REASON_ABORTED,
            ['An earlier category failed and continue_on_error is off.']
        );
    }

    /**
     * @param array{
     *     definition: ?CategoryDefinitionInterface,
     *     segments: string[],
     *     key: string,
     *     label: string,
     *     error: ?array{reason: string, message: string}
     * } $entry
     * @param array<string, array{id: ?int, root_id: ?int, reason: ?string, message: ?string}> $parents
     *        entry key => resolved parent, with the reason when it did not resolve
     * @param array<int, array<string, int[]>> $siblings parent_id => [name => entity_id[]]
     * @param array<string, array{id: ?int, reason: ?string, message: ?string, label: string}> $destinations
     *        entry key => requested new parent, absent when the entry asked for none
     * @param int|null $storeRootId root category of the target store view, null at default scope
     * @param array{touched: int[], removed: int[], movedSubtrees: array<int, true>,
     *     renamedPaths: array<string, true>} $state by reference
     */
    private function processOne(
        array $entry,
        array $parents,
        array $destinations,
        array $siblings,
        int $storeId,
        ?int $storeRootId,
        array &$state
    ): CategorySyncResultInterface {
        if ($entry['error'] !== null) {
            return $this->result(
                $entry,
                CategorySyncResultInterface::STATUS_SKIPPED,
                $entry['error']['reason'],
                [$entry['error']['message']]
            );
        }

        $definition = $entry['definition'];
        $segments = $entry['segments'];
        $categoryId = $definition->getCategoryId();
        // Only an explicit name renames. The path's last segment must NOT
        // stand in for it: with a category_id the path is informational and may
        // well be the pre-rename one, so deriving the name from it would rename
        // the category back on the next sync.
        $name = $definition->getName() !== null ? trim($definition->getName()) : null;
        $parent = $parents[$entry['key']] ?? [];
        $parentId = $parent['id'] ?? null;
        $messages = [];

        if ($categoryId !== null && $categoryId > 0) {
            $row = $this->categoryResource->getExistingByIds([$categoryId])[$categoryId] ?? null;
            if ($row === null) {
                return $this->skip(
                    $entry,
                    CategorySyncResultInterface::REASON_UNKNOWN_CATEGORY,
                    sprintf('Category ID %d does not exist.', $categoryId)
                );
            }
            // Level 1 is a root and is writable; level 0 is the catalog tree
            // root itself, which owns no catalog and must never be touched.
            if ($row['level'] < 1) {
                return $this->skip(
                    $entry,
                    CategorySyncResultInterface::REASON_ROOT_NOT_WRITABLE,
                    'The catalog tree root is not a category and cannot be written.',
                    $categoryId
                );
            }
            $destination = $destinations[$entry['key']] ?? null;
            // With no destination in the payload the path is still a cross-check,
            // and a mismatch is the same refusal it always was: nobody asked us
            // to reparent anything, so a path that disagrees is a discrepancy to
            // report rather than an instruction to act on.
            if ($destination === null && $parentId !== null && $parentId !== $row['parent_id']) {
                return $this->skip(
                    $entry,
                    CategorySyncResultInterface::REASON_MOVE_NOT_SUPPORTED,
                    sprintf(
                        'The category is under parent %d but the path implies parent %d.'
                        . ' Send parent_path or parent_category_id to move it.',
                        $row['parent_id'],
                        $parentId
                    ),
                    $categoryId
                );
            }
            $rowRootId = $this->rootIdOfPath($row['path']);
            if ($storeRootId !== null && $rowRootId !== $storeRootId) {
                return $this->wrongStoreRoot($entry, $rowRootId, $storeRootId, $categoryId);
            }
            $refusal = $this->refusePositionAtStoreScope($entry, $storeId, $categoryId);
            if ($refusal !== null) {
                return $refusal;
            }

            $moved = false;
            if ($destination !== null) {
                $refusal = $this->applyMove(
                    $entry,
                    $row,
                    $destination,
                    $storeId,
                    $messages,
                    $moved,
                    $state
                );
                if ($refusal !== null) {
                    return $refusal;
                }
            }

            // A rename can collide with a sibling exactly as a move can. Skipped
            // when the move above already checked this parent, and at store
            // scope, where the store-0 names path resolution uses are untouched.
            if (!$moved && $storeId === 0) {
                $refusal = $this->refuseSiblingConflict($entry, $categoryId, $row['parent_id'], false);
                if ($refusal !== null) {
                    return $refusal;
                }
            }

            return $this->applyUpdate(
                $entry,
                $categoryId,
                $name,
                $storeId,
                // Re-read from the row, which applyMove() has updated: a move to
                // or from level 1 changes whether this is a root, and
                // applyUpdate() uses that to decide whether a rename has to
                // invalidate the resolver's root map.
                $row['level'] === 1,
                $messages,
                $state,
                $moved
            );
        }

        $destination = $destinations[$entry['key']] ?? null;
        if ($destination !== null) {
            // A move needs a stable identity, and a path is not one across a
            // move: the moment the category lands somewhere else, the path that
            // addressed it resolves to nothing (or worse, to a different
            // category someone created in the meantime). Same reasoning as
            // rename_requires_category_id.
            return $this->skip(
                $entry,
                CategorySyncResultInterface::REASON_MOVE_REQUIRES_CATEGORY_ID,
                sprintf(
                    'Moving a category to "%s" requires category_id: after the move its old path no longer'
                    . ' identifies it.',
                    $destination['label']
                )
            );
        }

        if ($parentId === null) {
            return $this->skip(
                $entry,
                $parent['reason'] ?? CategorySyncResultInterface::REASON_PARENT_NOT_FOUND,
                $parent['message'] ?? sprintf('The parent of "%s" could not be resolved.', $entry['label'])
            );
        }

        $isRoot = count($segments) === 1;
        $leafName = (string)end($segments);
        if ($name !== null && $name !== $leafName) {
            return $this->skip(
                $entry,
                CategorySyncResultInterface::REASON_RENAME_REQUIRES_CATEGORY_ID,
                sprintf(
                    'Name "%s" differs from the path segment "%s"; send category_id to rename a category.',
                    $name,
                    $leafName
                )
            );
        }

        $matches = $siblings[$parentId][$leafName] ?? [];
        if (count($matches) > 1) {
            return $this->skip(
                $entry,
                CategorySyncResultInterface::REASON_AMBIGUOUS_PATH,
                sprintf(
                    $isRoot
                        ? '%d root categories are named "%s" (IDs %s); send category_id to pick one.'
                        : '%d categories named "%s" share this parent (IDs %s); send category_id to pick one.',
                    count($matches),
                    $leafName,
                    implode(', ', $matches)
                )
            );
        }

        $matchedId = isset($matches[0]) ? (int)$matches[0] : null;
        // Which tree this entry lives in: its own ID when it names a root,
        // otherwise the root its path starts from. Checked before the
        // create/update split, so a caller pointed at another store's tree is
        // told that rather than "omit store_view_code to create it".
        $entryRootId = $isRoot ? $matchedId : ($parent['root_id'] ?? null);
        if ($storeRootId !== null && $entryRootId !== null && $entryRootId !== $storeRootId) {
            return $this->wrongStoreRoot($entry, $entryRootId, $storeRootId, $matchedId);
        }

        if ($matches === []) {
            if ($storeId !== 0) {
                return $this->skip(
                    $entry,
                    CategorySyncResultInterface::REASON_STORE_SCOPE_STRUCTURAL_CHANGE,
                    $isRoot
                        ? 'A root category can only be created at default scope;'
                            . ' omit store_view_code to create it.'
                        : 'A category can only be created at default scope; omit store_view_code to create it.'
                );
            }

            // The name is free here by construction — a sibling carrying it would
            // have been updated instead of reaching this branch — but the slug is
            // not: it is derived from the name, so two differently named siblings
            // can want the same one. Left to the save that is an opaque
            // "URL key for specified store already exists" with the other
            // category unnamed.
            $conflict = $this->writer->findNewChildConflict($parentId, $leafName, $definition);
            if ($conflict !== null) {
                return $this->conflictRefusal($entry, $conflict, $parentId, null);
            }

            $entityId = $this->writer->create($parentId, $leafName, $definition, $messages);
            if ($isRoot) {
                // A new root changes the name => ID map the resolver memoizes
                // for the whole request; without this a deeper path sent in the
                // same request would not find it.
                $this->pathResolver->forgetRoots();
            }

            return $this->result(
                $entry,
                CategorySyncResultInterface::STATUS_CREATED,
                null,
                $messages
            )->setEntityId($entityId);
        }

        $refusal = $this->refusePositionAtStoreScope($entry, $storeId, $matchedId);
        if ($refusal !== null) {
            return $refusal;
        }

        // A path-identified entry cannot rename (that needs category_id), but it
        // can still hand over a url_key a sibling already uses.
        if ($storeId === 0) {
            $refusal = $this->refuseSiblingConflict($entry, (int)$matchedId, $parentId, false);
            if ($refusal !== null) {
                return $refusal;
            }
        }

        return $this->applyUpdate(
            $entry,
            (int)$matchedId,
            $leafName,
            $storeId,
            $isRoot,
            $messages,
            $state
        );
    }

    /**
     * The level-1 root an id-path belongs to ("1/4/12" => 4), or null for the
     * tree root itself. A root's own path ("1/4") yields the root.
     */
    private function rootIdOfPath(string $path): ?int
    {
        $ids = explode('/', $path);

        return isset($ids[1]) ? (int)$ids[1] : null;
    }

    private function wrongStoreRoot(
        array $entry,
        ?int $entryRootId,
        int $storeRootId,
        ?int $entityId
    ): CategorySyncResultInterface {
        return $this->skip(
            $entry,
            CategorySyncResultInterface::REASON_WRONG_STORE_ROOT,
            sprintf(
                'The category belongs to root category %s, but the selected store view shows root category %d;'
                . ' send it at default scope, or with a store view of its own root.',
                $entryRootId !== null ? (string)$entryRootId : 'none',
                $storeRootId
            ),
            $entityId
        );
    }

    /**
     * position lives in a column on catalog_category_entity, which has no store
     * dimension — writing it at store scope would silently change sibling order
     * for every store. Checked once the category has been identified, so the
     * refusal can carry its entity_id like every other post-identity refusal.
     */
    private function refusePositionAtStoreScope(
        array $entry,
        int $storeId,
        ?int $entityId
    ): ?CategorySyncResultInterface {
        if ($storeId === 0 || $entry['definition']->getPosition() === null) {
            return null;
        }

        return $this->skip(
            $entry,
            CategorySyncResultInterface::REASON_STORE_SCOPE_STRUCTURAL_CHANGE,
            'Position has no store dimension; omit store_view_code to change it.',
            $entityId
        );
    }

    /**
     * Reparent a category, or refuse to.
     *
     * Returns null when the move was applied (or was a no-op because the
     * category is already there), so the caller can go on to apply the entry's
     * attribute values; returns the refusal otherwise.
     *
     * Ordered so the refusals a caller can act on come before the ones they
     * cannot: what they asked for is impossible (cycle), then not permitted
     * (scope, switch, store root), then not resolvable.
     *
     * @param array{entity_id: int, parent_id: int, level: int, path: string} $row stored category,
     *        by reference: a move changes parent_id and level, and the caller
     *        decides from the updated level whether this is still a root
     * @param array{id: ?int, reason: ?string, message: ?string, label: string} $destination
     * @param string[] $messages by reference
     * @param bool $moved by reference; set when a move was actually applied, so the
     *        entry reports "updated" even when no attribute value differed
     * @param array{touched: int[], removed: int[], movedSubtrees: array<int, true>,
     *     renamedPaths: array<string, true>} $state by reference
     */
    private function applyMove(
        array $entry,
        array &$row,
        array $destination,
        int $storeId,
        array &$messages,
        bool &$moved,
        array &$state
    ): ?CategorySyncResultInterface {
        $categoryId = $row['entity_id'];
        $newParentId = $destination['id'];

        // Already where it should be. Not a move, not a message — this is the
        // replayed-payload case and it has to stay free.
        if ($newParentId !== null && $newParentId === $row['parent_id']) {
            return null;
        }

        if ($storeId !== 0) {
            return $this->skip(
                $entry,
                CategorySyncResultInterface::REASON_STORE_SCOPE_STRUCTURAL_CHANGE,
                'A category can only be moved at default scope: parent_id, path and level are columns with no'
                . ' store dimension. Omit store_view_code to move it.',
                $categoryId
            );
        }
        if (!$this->config->isCategoryMoveAllowed()) {
            return $this->skip(
                $entry,
                CategorySyncResultInterface::REASON_MOVE_DISABLED,
                'Category moves are disabled in configuration.',
                $categoryId
            );
        }
        if ($newParentId === null) {
            return $this->skip(
                $entry,
                $destination['reason'] ?? CategorySyncResultInterface::REASON_PARENT_NOT_FOUND,
                $destination['message']
                    ?? sprintf('The destination parent "%s" could not be resolved.', $destination['label']),
                $categoryId
            );
        }

        $destinationRow = $this->categoryResource->getExistingByIds([$newParentId])[$newParentId] ?? null;
        if ($destinationRow === null) {
            return $this->skip(
                $entry,
                CategorySyncResultInterface::REASON_PARENT_NOT_FOUND,
                sprintf('Destination parent ID %d does not exist.', $newParentId),
                $categoryId
            );
        }
        // Moving a category under itself or under one of its own descendants
        // would detach the subtree from the tree entirely — the path REPLACE()
        // would build a cycle no walk can leave. Core's Category::move() catches
        // only the equal-IDs case, and CategoryManagement::move() (which does
        // check this) reports every failure as "Could not move category".
        if ($newParentId === $categoryId || str_starts_with($destinationRow['path'], $row['path'] . '/')) {
            return $this->skip(
                $entry,
                CategorySyncResultInterface::REASON_MOVE_INTO_DESCENDANT,
                $newParentId === $categoryId
                    ? 'A category cannot be moved under itself.'
                    : sprintf(
                        'Destination parent %d is a descendant of category %d; a category cannot be moved'
                        . ' into its own subtree.',
                        $newParentId,
                        $categoryId
                    ),
                $categoryId
            );
        }
        if (isset($this->categoryResource->getStoreGroupRootCategoryIds()[$categoryId])) {
            return $this->skip(
                $entry,
                CategorySyncResultInterface::REASON_ROOT_IN_USE,
                sprintf(
                    'Category %d is the root category of a store group; moving it would leave that storefront'
                    . ' pointing at a category that is no longer a root.',
                    $categoryId
                ),
                $categoryId
            );
        }
        if (isset($state['movedSubtrees'][$categoryId])) {
            return $this->staleAfterMove($entry, $categoryId);
        }
        // The destination may already hold a category of this name or slug. Left
        // to the write, the name case succeeds and quietly makes the path
        // ambiguous forever, and the slug case throws from deep inside the save.
        $refusal = $this->refuseSiblingConflict($entry, $categoryId, $newParentId, true);
        if ($refusal !== null) {
            return $refusal;
        }

        // Captured before the write: afterwards the subtree hangs off a different
        // path, and these are the pages whose url_path just changed.
        $subtreeIds = array_merge(
            [$categoryId],
            $this->categoryResource->getDescendantIds([$categoryId])
        );

        $this->writer->move($categoryId, $newParentId);

        $messages[] = sprintf('Moved from parent %d to parent %d.', $row['parent_id'], $newParentId);
        if ($destinationRow['level'] === 0) {
            $messages[] = 'The category is now a level-1 root and has no storefront presence until a store'
                . ' group points at it.';
        }
        if ($row['level'] === 1) {
            $messages[] = 'The category is no longer a root.';
        }

        // Both parents' children lists changed, and the whole subtree was
        // re-pathed.
        $state['touched'] = array_merge($state['touched'], $subtreeIds, [$row['parent_id'], $newParentId]);
        foreach ($subtreeIds as $subtreeId) {
            $state['movedSubtrees'][$subtreeId] = true;
        }
        // Every cached path under the old location, and every cached path under
        // the new one, now resolves to the wrong node.
        $this->pathResolver->forgetAllPaths();
        if ($row['level'] === 1 || $destinationRow['level'] === 0) {
            $this->pathResolver->forgetRoots();
        }
        if ($entry['segments'] !== []) {
            $state['renamedPaths'][PathParser::buildKey($entry['segments'])] = true;
        }
        // The row the caller goes on to update now sits somewhere else.
        $row['parent_id'] = $newParentId;
        $row['level'] = $destinationRow['level'] + 1;
        $moved = true;

        return null;
    }

    /**
     * Refuse a write that would land a category on a name or slug a sibling under
     * $parentId already holds.
     *
     * Covers both ways of arriving at one: a **move** (the name it already has,
     * under a new parent) and a **rename or explicit url_key** (a new name, under
     * the parent it is already in). Default scope only — see
     * {@see CategoryWriter::findSiblingConflict()} for what that does and does not
     * catch.
     *
     * @param bool $moved whether the category is arriving from another parent
     */
    private function refuseSiblingConflict(
        array $entry,
        int $entityId,
        int $parentId,
        bool $moved
    ): ?CategorySyncResultInterface {
        $name = $entry['definition']->getName() !== null
            ? trim((string)$entry['definition']->getName())
            : null;

        $conflict = $this->writer->findSiblingConflict(
            $entityId,
            $parentId,
            $name,
            $entry['definition'],
            $moved
        );

        return $conflict !== null
            ? $this->conflictRefusal($entry, $conflict, $parentId, $entityId)
            : null;
    }

    /**
     * Turn a sibling conflict into its refusal. Shared by the create, move and
     * rename paths, so the same collision reads the same way whichever of them
     * ran into it.
     *
     * @param array{kind: string, value: string, category_id: int} $conflict
     * @param int|null $entityId null when the category does not exist yet
     */
    private function conflictRefusal(
        array $entry,
        array $conflict,
        int $parentId,
        ?int $entityId
    ): CategorySyncResultInterface {
        if ($conflict['kind'] === 'name') {
            return $this->skip(
                $entry,
                CategorySyncResultInterface::REASON_DESTINATION_NAME_TAKEN,
                sprintf(
                    'Category %d under parent %d is already named "%s"; a second category with that name there'
                    . ' would make the path ambiguous for every later write and for product assignment.'
                    . ' Rename or remove one of them first.',
                    $conflict['category_id'],
                    $parentId,
                    $conflict['value']
                ),
                $entityId
            );
        }

        return $this->skip(
            $entry,
            CategorySyncResultInterface::REASON_DESTINATION_URL_KEY_TAKEN,
            sprintf(
                'url_key "%s" is already used by category %d under parent %d; the two would generate the same'
                . ' URL. Send a different url_key, or rename or remove the other category first.',
                $conflict['value'],
                $conflict['category_id'],
                $parentId
            ),
            $entityId
        );
    }

    /**
     * Core's ChildrenCategoriesProvider memoizes a category's children for the
     * whole request, and both the move plugin and the URL rewrite observer read
     * through it. So a second structural change inside a subtree this request
     * already moved would regenerate rewrites from the pre-move children — wrong
     * URLs, silently, with no error anywhere. There is no reset to call, so the
     * only honest answer is to refuse and let the caller send it separately.
     */
    private function staleAfterMove(array $entry, int $entityId): CategorySyncResultInterface
    {
        return $this->skip(
            $entry,
            CategorySyncResultInterface::REASON_STALE_PARENT_PATH,
            'This category is inside a subtree that was moved earlier in this request; send it in a separate'
            . ' request so its URL rewrites are generated from the new tree.',
            $entityId
        );
    }

    /**
     * @param bool $isRoot whether the target is a level-1 root
     * @param string[] $messages
     * @param array{touched: int[], removed: int[], movedSubtrees: array<int, true>,
     *     renamedPaths: array<string, true>} $state by reference
     * @param bool $moved whether this entry already moved the category, which is a
     *        change in its own right even when no attribute value differs
     */
    private function applyUpdate(
        array $entry,
        int $entityId,
        ?string $name,
        int $storeId,
        bool $isRoot,
        array $messages,
        array &$state,
        bool $moved = false
    ): CategorySyncResultInterface {
        $changed = $this->writer->update($entityId, $name, $entry['definition'], $storeId, $messages);

        // Only a DEFAULT-scope rename invalidates a path. Path resolution
        // matches store-0 names throughout this module, so a store-scoped
        // rename leaves every path resolving exactly where it did; treating it
        // as a rename would skip the whole subtree for nothing.
        if ($changed && $storeId === 0) {
            $renamedFrom = $name !== null && $entry['segments'] !== [] && $name !== (string)end($entry['segments'])
                ? (string)end($entry['segments'])
                : null;

            if ($renamedFrom !== null) {
                // The cached path now points at a differently named category,
                // and any later entry addressing a descendant by the old path
                // would resolve to the wrong node.
                $pathKey = PathParser::buildKey($entry['segments']);
                $this->pathResolver->forget($pathKey);
                $state['renamedPaths'][$pathKey] = true;
            }
            if ($isRoot) {
                // forget() above cannot reach a root: single-segment paths are
                // refused by the resolver, so a root only ever lives in its
                // separately memoized name => ID map. The old name is passed
                // when we know it, which also drops the paths cached below it;
                // an entry addressed by category_id has no path in the payload
                // and needs none, since those run in the last bucket, after
                // every path has already been resolved.
                $this->pathResolver->forgetRoots($renamedFrom);
            }
        }

        return $this->result(
            $entry,
            $changed || $moved
                ? CategorySyncResultInterface::STATUS_UPDATED
                : CategorySyncResultInterface::STATUS_UNCHANGED,
            null,
            $messages
        )->setEntityId($entityId);
    }

    /**
     * Resolve each entry's parent, and the root of the tree it lives in, in
     * bulk: one root lookup plus one level-by-level walk for the whole bucket,
     * never a query per entry.
     *
     * @param array<int, array{segments: string[], key: string, ...}> $bucket
     * @return array<string, array{id: ?int, root_id: ?int, reason: ?string, message: ?string}>
     *         entry key => parent, with the reason when it did not resolve
     */
    private function resolveParents(array $bucket): array
    {
        $parents = [];
        $deepPaths = [];
        $deepByEntryKey = [];
        // Deliberately per call, and this call is per bucket: a root created in
        // a shallower bucket is already committed and MUST be visible here.
        // Hoisting this to a property would break same-request root creation.
        $roots = null;

        foreach ($bucket as $entry) {
            $segments = $entry['segments'];
            if ($segments === []) {
                // Addressed purely by category_id: there is no path to resolve a
                // parent from, and the stored row is what gets cross-checked.
                $parents[$entry['key']] = self::noParent();
                continue;
            }
            if (count($segments) === 1) {
                // A root: its parent is the catalog tree root, which always
                // exists. root_id stays null — the entry IS the root, and which
                // one it is only becomes known once the name has been matched.
                $parents[$entry['key']] = [
                    'id' => CategoryModel::TREE_ROOT_ID,
                    'root_id' => null,
                    'reason' => null,
                    'message' => null,
                ];
                continue;
            }

            // The resolver collapses duplicate root names to the lowest ID,
            // which is fine for a read but not for a write, so the first
            // segment is resolved here with every candidate ID in hand.
            $roots ??= $this->categoryResource->getRootCategoryIds();
            $rootIds = $roots[$segments[0]] ?? [];
            if (count($rootIds) > 1) {
                $parents[$entry['key']] = self::noParent(
                    CategorySyncResultInterface::REASON_AMBIGUOUS_PATH,
                    sprintf(
                        '%d root categories are named "%s" (IDs %s); send category_id to address a category'
                        . ' under one of them.',
                        count($rootIds),
                        $segments[0],
                        implode(', ', $rootIds)
                    )
                );
                continue;
            }
            if ($rootIds === []) {
                $parents[$entry['key']] = self::noParent(
                    CategorySyncResultInterface::REASON_UNKNOWN_ROOT,
                    sprintf(
                        'Unknown root category "%s" — send it as a single-segment path to create it.',
                        $segments[0]
                    )
                );
                continue;
            }

            $rootId = $rootIds[0];
            if (count($segments) === 2) {
                $parents[$entry['key']] = [
                    'id' => $rootId,
                    'root_id' => $rootId,
                    'reason' => null,
                    'message' => null,
                ];
                continue;
            }

            $parentSegments = array_slice($segments, 0, -1);
            $pathKey = PathParser::buildKey($parentSegments);
            $deepPaths[$pathKey] = $parentSegments;
            $deepByEntryKey[$entry['key']] = ['path_key' => $pathKey, 'root_id' => $rootId];
        }

        if ($deepPaths) {
            // lookupPaths(), not resolvePaths(): this endpoint never creates a
            // category the caller did not ask for. A missing ancestor is the
            // caller's to send, and creating it silently would produce a
            // category with none of the properties they specified anywhere.
            $resolved = $this->pathResolver->lookupPaths($deepPaths);
            foreach ($deepByEntryKey as $entryKey => $deep) {
                $parentId = $resolved[$deep['path_key']] ?? null;
                $parents[$entryKey] = [
                    'id' => $parentId,
                    'root_id' => $deep['root_id'],
                    'reason' => $parentId !== null
                        ? null
                        : CategorySyncResultInterface::REASON_PARENT_NOT_FOUND,
                    'message' => $parentId !== null
                        ? null
                        : sprintf('Parent category "%s" does not exist; send it too.', $deep['path_key']),
                ];
            }
        }

        return $parents;
    }

    /**
     * Run the delete entries, deepest first.
     *
     * Ordering matters only for a payload that names both a parent and something
     * beneath it: Magento's delete is recursive, so taking the parent first would
     * leave the child's own entry reporting `already_absent` — true, but less
     * useful than telling the caller each category they asked about was removed.
     *
     * @param array<int, array{...}> $deleteEntries
     * @param bool $aborted by reference; shared with the write phase
     * @param array{touched: int[], removed: int[], movedSubtrees: array<int, true>,
     *     renamedPaths: array<string, true>} $state by reference
     * @return CategorySyncResultInterface[]
     */
    private function processDeletes(
        array $deleteEntries,
        int $storeId,
        ?int $storeRootId,
        bool $continueOnError,
        bool &$aborted,
        array &$state
    ): array {
        if (!$deleteEntries) {
            return [];
        }

        // Checked before anything is resolved, so a payload sent against an
        // instance that does not allow deletion reports one uniform reason
        // instead of a per-entry mix of tree findings. Mirrors how the endpoint's
        // own master switch behaves.
        if (!$this->config->isCategoryDeleteAllowed()) {
            return array_map(
                fn (array $entry): CategorySyncResultInterface => $this->result(
                    $entry,
                    CategorySyncResultInterface::STATUS_SKIPPED,
                    CategorySyncResultInterface::REASON_DELETE_DISABLED,
                    ['Category deletion is disabled in configuration.']
                ),
                $deleteEntries
            );
        }

        $targets = $this->resolveDeleteTargets($deleteEntries, $storeId, $storeRootId);
        // Deepest first. Entries that resolved to nothing keep their place — they
        // do no work and their result is already decided.
        usort(
            $targets,
            static fn (array $a, array $b): int => ($b['row']['level'] ?? -1) <=> ($a['row']['level'] ?? -1)
        );

        $results = [];
        foreach ($targets as $target) {
            $entry = $target['entry'];

            if ($target['result'] !== null) {
                $results[] = $target['result'];
                continue;
            }
            if ($aborted) {
                $results[] = $this->abortedResult($entry);
                continue;
            }

            // By reference, for the same reason the write loop's closure is.
            $result = $this->inTransaction(
                $entry,
                function () use ($entry, $target, &$state): CategorySyncResultInterface {
                    return $this->deleteOne($entry, $target['row'], $state);
                }
            );

            if ($result->getStatus() === CategorySyncResultInterface::STATUS_ERROR && !$continueOnError) {
                $aborted = true;
            }
            $results[] = $result;
        }

        return $results;
    }

    /**
     * Match each delete entry to the stored row it names, or to the result that
     * settles it without a write.
     *
     * An entry whose path cannot be resolved is `already_absent` rather than
     * `parent_not_found`: if the parent is not there, neither is the category, and
     * the caller's desired state already holds. Ambiguity is the exception — two
     * candidates mean we do not know which one to remove, and removing a subtree
     * on a guess is not recoverable.
     *
     * @param array<int, array{...}> $deleteEntries
     * @return array<int, array{entry: array, row: ?array, result: ?CategorySyncResultInterface}>
     */
    private function resolveDeleteTargets(array $deleteEntries, int $storeId, ?int $storeRootId): array
    {
        $parents = $this->resolveParents($deleteEntries);
        $siblings = $this->loadSiblings($parents);

        $pending = [];
        $idsToLoad = [];
        foreach ($deleteEntries as $entry) {
            $definition = $entry['definition'];
            $categoryId = $definition->getCategoryId();

            if ($categoryId !== null && $categoryId > 0) {
                $pending[] = ['entry' => $entry, 'id' => $categoryId, 'result' => null];
                $idsToLoad[] = $categoryId;
                continue;
            }

            $segments = $entry['segments'];
            $parent = $parents[$entry['key']] ?? [];
            $parentId = $parent['id'] ?? null;
            if ($parentId === null) {
                $pending[] = [
                    'entry' => $entry,
                    'id' => null,
                    'result' => $this->alreadyAbsent($entry, $entry['label']),
                ];
                continue;
            }

            $leafName = (string)end($segments);
            $matches = $siblings[$parentId][$leafName] ?? [];
            if (count($matches) > 1) {
                $pending[] = [
                    'entry' => $entry,
                    'id' => null,
                    'result' => $this->skip(
                        $entry,
                        CategorySyncResultInterface::REASON_AMBIGUOUS_PATH,
                        sprintf(
                            '%d categories named "%s" share this parent (IDs %s); send category_id to say which'
                            . ' one to delete.',
                            count($matches),
                            $leafName,
                            implode(', ', $matches)
                        )
                    ),
                ];
                continue;
            }
            if ($matches === []) {
                $pending[] = [
                    'entry' => $entry,
                    'id' => null,
                    'result' => $this->alreadyAbsent($entry, $entry['label']),
                ];
                continue;
            }

            $pending[] = ['entry' => $entry, 'id' => (int)$matches[0], 'result' => null];
            $idsToLoad[] = (int)$matches[0];
        }

        $rows = $this->categoryResource->getExistingByIds(array_values(array_unique($idsToLoad)));

        $targets = [];
        foreach ($pending as $item) {
            if ($item['result'] !== null) {
                $targets[] = ['entry' => $item['entry'], 'row' => null, 'result' => $item['result']];
                continue;
            }

            $row = $rows[$item['id']] ?? null;
            if ($row === null) {
                $targets[] = [
                    'entry' => $item['entry'],
                    'row' => null,
                    'result' => $this->alreadyAbsent($item['entry'], sprintf('#%d', $item['id'])),
                ];
                continue;
            }

            // Scope and tree refusals are decided here so they keep the ordering
            // the write path uses: wrong_store_root ahead of
            // store_scope_structural_change, both after the category is known.
            $result = null;
            if ($row['level'] < 1) {
                $result = $this->skip(
                    $item['entry'],
                    CategorySyncResultInterface::REASON_ROOT_NOT_WRITABLE,
                    'The catalog tree root is not a category and cannot be deleted.',
                    $item['id']
                );
            } elseif ($storeRootId !== null && $this->rootIdOfPath($row['path']) !== $storeRootId) {
                $result = $this->wrongStoreRoot(
                    $item['entry'],
                    $this->rootIdOfPath($row['path']),
                    $storeRootId,
                    $item['id']
                );
            } elseif ($storeId !== 0) {
                $result = $this->skip(
                    $item['entry'],
                    CategorySyncResultInterface::REASON_STORE_SCOPE_STRUCTURAL_CHANGE,
                    'A category can only be deleted at default scope: the row is shared by every store.'
                    . ' Omit store_view_code to delete it.',
                    $item['id']
                );
            }

            $targets[] = ['entry' => $item['entry'], 'row' => $row, 'result' => $result];
        }

        return $targets;
    }

    /**
     * Delete one already-identified category, or refuse.
     *
     * @param array{entity_id: int, parent_id: int, level: int, path: string} $row
     * @param array{touched: int[], removed: int[], movedSubtrees: array<int, true>,
     *     renamedPaths: array<string, true>} $state by reference
     */
    private function deleteOne(array $entry, array $row, array &$state): CategorySyncResultInterface
    {
        $entityId = $row['entity_id'];

        if (isset($this->categoryResource->getStoreGroupRootCategoryIds()[$entityId])) {
            return $this->skip(
                $entry,
                CategorySyncResultInterface::REASON_ROOT_IN_USE,
                sprintf(
                    'Category %d is the root category of a store group; deleting it would leave that storefront'
                    . ' with no catalog. Repoint the store group first.',
                    $entityId
                ),
                $entityId
            );
        }
        if (isset($state['movedSubtrees'][$entityId])) {
            return $this->staleAfterMove($entry, $entityId);
        }

        // Captured before the delete for two reasons: the guard below needs the
        // count, and afterwards there is nothing left to derive the stale cache
        // entries from.
        $descendantIds = $this->categoryResource->getDescendantIds([$entityId]);
        if ($descendantIds !== [] && $entry['definition']->getDeleteChildren() !== 1) {
            return $this->skip(
                $entry,
                CategorySyncResultInterface::REASON_HAS_CHILDREN,
                sprintf(
                    'Category %d has %d categories beneath it, which a delete would remove too;'
                    . ' send delete_children: 1 to confirm.',
                    $entityId,
                    count($descendantIds)
                ),
                $entityId
            );
        }

        $this->writer->delete($entityId);

        $messages = [];
        if ($descendantIds !== []) {
            $messages[] = sprintf('Deleted with %d categories beneath it.', count($descendantIds));
        }
        $messages[] = 'Product assignments to the deleted categories were removed; the products themselves'
            . ' were not.';

        $state['removed'] = array_merge($state['removed'], [$entityId], $descendantIds);
        // The parent's children list changed.
        $state['touched'][] = $row['parent_id'];
        $this->pathResolver->forgetAllPaths();
        if ($row['level'] === 1) {
            $this->pathResolver->forgetRoots();
        }
        if ($entry['segments'] !== []) {
            $state['renamedPaths'][PathParser::buildKey($entry['segments'])] = true;
        }

        return $this->result($entry, CategorySyncResultInterface::STATUS_DELETED, null, $messages)
            ->setEntityId($entityId);
    }

    /**
     * A delete with nothing to delete. Reported as `unchanged` rather than
     * `skipped`: the caller asked for the category to be gone and it is, which is
     * what keeps a replayed delete free.
     */
    private function alreadyAbsent(array $entry, string $label): CategorySyncResultInterface
    {
        return $this->result(
            $entry,
            CategorySyncResultInterface::STATUS_UNCHANGED,
            CategorySyncResultInterface::REASON_ALREADY_ABSENT,
            [sprintf('Category "%s" does not exist; nothing to delete.', $label)]
        );
    }

    /**
     * Resolve the parent each entry wants to END UP under, for the entries that
     * asked for one. Entries with no `parent_path`/`parent_category_id` are absent
     * from the result, which is what "leave the parent alone" looks like — an
     * entry present with a null id asked for a destination we could not find.
     *
     * `parent_category_id` wins over `parent_path`: it is the way to name a
     * destination whose path is ambiguous, so consulting the path as well would
     * defeat the point.
     *
     * @param array<int, array{definition: ?CategoryDefinitionInterface, key: string, ...}> $bucket
     * @return array<string, array{id: ?int, reason: ?string, message: ?string, label: string}>
     */
    private function resolveDestinations(array $bucket): array
    {
        $destinations = [];
        $deepPaths = [];
        $deepByEntryKey = [];
        $roots = null;

        foreach ($bucket as $entry) {
            if ($entry['error'] !== null) {
                continue;
            }
            $definition = $entry['definition'];

            $parentCategoryId = $definition->getParentCategoryId();
            if ($parentCategoryId !== null && $parentCategoryId > 0) {
                $destinations[$entry['key']] = [
                    'id' => $parentCategoryId,
                    'reason' => null,
                    'message' => null,
                    'label' => sprintf('#%d', $parentCategoryId),
                ];
                continue;
            }

            try {
                $segments = $this->validator->validateParent($definition);
            } catch (CategoryValidationException $e) {
                $destinations[$entry['key']] = [
                    'id' => null,
                    'reason' => $e->getReason(),
                    'message' => $e->getMessage(),
                    'label' => trim((string)$definition->getParentPath()),
                ];
                continue;
            }
            if ($segments === []) {
                continue;
            }

            $label = PathParser::buildKey($segments);

            // Same treatment the entry's own first segment gets: duplicate root
            // names are two distinct catalogs, and picking the lowest ID for a
            // write is exactly the guess this endpoint refuses to make.
            $roots ??= $this->categoryResource->getRootCategoryIds();
            $rootIds = $roots[$segments[0]] ?? [];
            if (count($rootIds) > 1) {
                $destinations[$entry['key']] = [
                    'id' => null,
                    'reason' => CategorySyncResultInterface::REASON_AMBIGUOUS_PATH,
                    'message' => sprintf(
                        '%d root categories are named "%s" (IDs %s); send parent_category_id to pick the'
                        . ' destination.',
                        count($rootIds),
                        $segments[0],
                        implode(', ', $rootIds)
                    ),
                    'label' => $label,
                ];
                continue;
            }
            if ($rootIds === []) {
                $destinations[$entry['key']] = [
                    'id' => null,
                    'reason' => CategorySyncResultInterface::REASON_UNKNOWN_ROOT,
                    'message' => sprintf(
                        'Unknown root category "%s" in the destination parent path.',
                        $segments[0]
                    ),
                    'label' => $label,
                ];
                continue;
            }

            if (count($segments) === 1) {
                // A single-segment destination names a root: the category becomes
                // one of that root's direct children.
                $destinations[$entry['key']] = [
                    'id' => $rootIds[0],
                    'reason' => null,
                    'message' => null,
                    'label' => $label,
                ];
                continue;
            }

            $deepPaths[$label] = $segments;
            $deepByEntryKey[$entry['key']] = $label;
        }

        if ($deepPaths) {
            // lookupPaths(), never resolvePaths(): a destination the caller
            // mistyped must be reported, not conjured into existence.
            $resolved = $this->pathResolver->lookupPaths($deepPaths);
            foreach ($deepByEntryKey as $entryKey => $label) {
                $parentId = $resolved[$label] ?? null;
                $destinations[$entryKey] = [
                    'id' => $parentId,
                    'reason' => $parentId !== null
                        ? null
                        : CategorySyncResultInterface::REASON_PARENT_NOT_FOUND,
                    'message' => $parentId !== null
                        ? null
                        : sprintf('Destination parent category "%s" does not exist; send it too.', $label),
                    'label' => $label,
                ];
            }
        }

        return $destinations;
    }

    /**
     * No parent, either because the entry needs none or because it could not be
     * resolved — in which case the reason is what the entry is skipped with.
     *
     * @return array{id: null, root_id: null, reason: ?string, message: ?string}
     */
    private static function noParent(?string $reason = null, ?string $message = null): array
    {
        return ['id' => null, 'root_id' => null, 'reason' => $reason, 'message' => $message];
    }

    /**
     * Normalize, validate and de-duplicate the payload. Validation failures are
     * carried on the entry rather than thrown, so they are reported alongside
     * the categories that did succeed.
     *
     * @param CategoryDefinitionInterface[] $categories
     * @return array<int, array{
     *     definition: ?CategoryDefinitionInterface,
     *     segments: string[],
     *     key: string,
     *     label: string,
     *     error: ?array{reason: string, message: string}
     * }>
     */
    private function prepareEntries(array $categories): array
    {
        $entries = [];
        foreach ($categories as $index => $definition) {
            $position = (int)$index;

            if (!$definition instanceof CategoryDefinitionInterface) {
                // Unreachable through the webapi, which deserializes into the
                // interface. A direct caller still gets a row rather than a
                // results array quietly shorter than "received".
                $entries['invalid:' . $position] = [
                    'definition' => null,
                    'segments' => [],
                    'key' => 'invalid:' . $position,
                    'label' => sprintf('#%d', $position),
                    'error' => [
                        'reason' => CategorySyncResultInterface::REASON_INVALID_DEFINITION,
                        'message' => 'Entry is not a category definition.',
                    ],
                ];
                continue;
            }

            $segments = [];
            $error = null;
            try {
                $segments = $this->validator->validate($definition);
            } catch (CategoryValidationException $e) {
                $error = ['reason' => $e->getReason(), 'message' => $e->getMessage()];
            }

            $categoryId = $definition->getCategoryId();
            $hasId = $categoryId !== null && $categoryId > 0;

            // De-duplicate on the identity actually used: an ID when one is
            // given, the canonical path otherwise. Last wins, as the product
            // endpoint does for SKUs.
            //
            // A REJECTED entry has no usable identity — validation failed
            // before the path was parsed, so every one of them would otherwise
            // collapse onto the same key and only the last would be reported,
            // while "received" still counted them all.
            $key = match (true) {
                $error !== null => 'invalid:' . $position,
                $hasId => 'id:' . $categoryId,
                default => 'path:' . PathParser::buildKey($segments),
            };

            $entries[$key] = [
                'definition' => $definition,
                'segments' => $segments,
                // Carried onto the entry because resolveParents() keys by it:
                // the label is not unique — a category literally named "#42"
                // shares its label with the entry addressing ID 42 — and two
                // entries sharing a parents key would inherit each other's.
                'key' => $key,
                'label' => $this->labelFor($definition, $segments, $position),
                'error' => $error,
            ];
        }

        return array_values($entries);
    }

    /**
     * How this entry is named in the response. The canonical path when there is
     * one; otherwise the ID, the raw path the caller sent (so a rejected path is
     * echoed back as typed), or failing all of those the payload position.
     *
     * @param string[] $segments
     */
    private function labelFor(CategoryDefinitionInterface $definition, array $segments, int $position): string
    {
        if ($segments !== []) {
            return PathParser::buildKey($segments);
        }

        $categoryId = $definition->getCategoryId();
        if ($categoryId !== null && $categoryId > 0) {
            return sprintf('#%d', $categoryId);
        }

        $rawPath = trim((string)$definition->getPath());

        return $rawPath !== '' ? $rawPath : sprintf('#%d', $position);
    }

    /**
     * Group entries by path depth, shallowest first, so a parent sent in the
     * same request exists before its children are resolved. Entries addressed
     * only by category_id have no depth and go last, since they may target a
     * category created earlier in this same run.
     *
     * Depth comes from the parsed segment count, never from counting "/" in the
     * raw string — an escaped separator would make that count wrong.
     *
     * @param array<int, array{segments: string[], ...}> $entries
     * @return array<int, array<int, array{segments: string[], ...}>>
     */
    private function bucketByDepth(array $entries): array
    {
        $buckets = [];
        foreach ($entries as $entry) {
            $depth = $entry['segments'] === [] ? PHP_INT_MAX : count($entry['segments']);
            $buckets[$depth][] = $entry;
        }
        ksort($buckets);

        return array_values($buckets);
    }

    /**
     * Whether an ancestor of this entry was renamed earlier in the request,
     * which makes the entry's stored path stale.
     *
     * @param array<string, true> $renamedPaths
     */
    private function hasRenamedAncestor(array $entry, array $renamedPaths): bool
    {
        if (!$renamedPaths || $entry['segments'] === []) {
            return false;
        }

        for ($i = 1, $count = count($entry['segments']); $i < $count; $i++) {
            if (isset($renamedPaths[PathParser::buildKey(array_slice($entry['segments'], 0, $i))])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array{...}> $entries
     * @return CategorySyncResultInterface[]
     */
    private function disabledResults(array $entries): array
    {
        return array_map(
            fn (array $entry): CategorySyncResultInterface => $this->result(
                $entry,
                CategorySyncResultInterface::STATUS_SKIPPED,
                CategorySyncResultInterface::REASON_DISABLED,
                ['Category sync is disabled in configuration.']
            ),
            $entries
        );
    }

    /**
     * @param int|null $entityId the category the entry resolved to, when the
     *        skip happened after it was identified — a caller that is the
     *        system of record wants the ID even for a refusal.
     */
    private function skip(
        array $entry,
        string $reason,
        string $message,
        ?int $entityId = null
    ): CategorySyncResultInterface {
        return $this->result($entry, CategorySyncResultInterface::STATUS_SKIPPED, $reason, [$message])
            ->setEntityId($entityId);
    }

    /**
     * Direct children of every parent resolved for a bucket, in one query.
     *
     * @param array<string, array{id: ?int, reason: ?string, message: ?string}> $parents
     * @return array<int, array<string, int[]>> parent_id => [name => entity_id[]]
     */
    private function loadSiblings(array $parents): array
    {
        $parentIds = [];
        foreach ($parents as $parent) {
            if ($parent['id'] !== null) {
                $parentIds[$parent['id']] = true;
            }
        }

        return $parentIds === []
            ? []
            : $this->categoryResource->getChildIdsByParentIds(array_keys($parentIds));
    }

    /**
     * A rollback that cannot mask the failure that triggered it.
     *
     * The expected case is a repository save that failed inside our
     * transaction, where rolling back is precisely what clears the
     * connection's partial-rollback flag. But when the COMMIT is what failed,
     * the driver may already have unwound the transaction, and letting the
     * rollback's own exception escape would replace the real cause with a
     * confusing "no active transaction" and abandon the remaining categories.
     */
    private function rollBackQuietly(AdapterInterface $connection): void
    {
        try {
            $connection->rollBack();
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('Category sync: rollback after a failed write also failed: %s', $e->getMessage())
            );
        }
    }

    /**
     * Reindex and purge for the categories that were written.
     *
     * Never allowed to throw: it runs in a finally, where an exception would
     * replace the sync's real outcome (or its real error) with a cache-layer
     * one, and the categories are committed either way.
     *
     * @param int[] $touchedIds
     * @param int[] $removedIds deleted categories, already expanded to the subtree
     *        that went with them
     */
    private function invalidate(array $touchedIds, array $removedIds = []): void
    {
        try {
            $this->invalidationHandler->execute($touchedIds, $removedIds);
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf('Category sync: post-sync invalidation failed: %s', $e->getMessage()),
                ['exception' => $e]
            );
        }
    }

    /**
     * @param string[] $messages
     */
    private function result(
        array $entry,
        string $status,
        ?string $reason,
        array $messages
    ): CategorySyncResultInterface {
        /** @var CategorySyncResultInterface $result */
        $result = $this->resultFactory->create();

        return $result->setPath($entry['label'])
            ->setStatus($status)
            ->setReason($reason)
            ->setMessages($messages);
    }

    /**
     * @param CategorySyncResultInterface[] $results
     */
    private function buildResponse(int $received, array $results, int $startedAt): CategorySyncResponseInterface
    {
        $created = $updated = $unchanged = $deleted = $skipped = $failed = 0;
        foreach ($results as $result) {
            match ($result->getStatus()) {
                CategorySyncResultInterface::STATUS_CREATED => $created++,
                CategorySyncResultInterface::STATUS_UPDATED => $updated++,
                CategorySyncResultInterface::STATUS_UNCHANGED => $unchanged++,
                CategorySyncResultInterface::STATUS_DELETED => $deleted++,
                CategorySyncResultInterface::STATUS_SKIPPED => $skipped++,
                default => $failed++,
            };
        }

        /** @var CategorySyncResponseInterface $response */
        $response = $this->responseFactory->create();

        return $response->setReceived($received)
            ->setCreated($created)
            ->setUpdated($updated)
            ->setUnchanged($unchanged)
            ->setDeleted($deleted)
            ->setSkipped($skipped)
            ->setFailed($failed)
            ->setElapsedMs((int)((hrtime(true) - $startedAt) / 1_000_000))
            ->setResults($results);
    }
}
