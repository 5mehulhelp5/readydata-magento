<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model;

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
 * Structural scope is deliberately narrow. A missing category below an existing
 * root is created; roots are never created; reparenting an existing category is
 * reported, not applied, because a move re-paths an entire subtree and needs
 * cycle detection this endpoint does not yet have.
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
        $continueOnError = $settings?->getContinueOnError() ?? $this->config->isContinueOnError();

        if (!$this->lockManager->lock(self::LOCK_NAME, self::LOCK_TIMEOUT_SEC)) {
            // Wording matches the product endpoint verbatim: callers already
            // recognise it and back off.
            throw new LocalizedException(__('Another import is already running. Try again later.'));
        }

        $results = [];
        $touchedIds = [];
        $aborted = false;
        $renamedPaths = [];
        $connection = $this->resourceConnection->getConnection();

        try {
            foreach ($this->bucketByDepth($entries) as $bucket) {
                $parents = $this->resolveParents($bucket);
                // One children-by-name query for the whole bucket instead of
                // one per entry, dropped again as soon as an entry writes:
                // a rename inside this bucket can move a name from one ID to
                // another, and a stale map would create a duplicate sibling.
                $siblings = null;

                foreach ($bucket as $entry) {
                    if ($aborted) {
                        $results[] = $this->result(
                            $entry,
                            CategorySyncResultInterface::STATUS_SKIPPED,
                            CategorySyncResultInterface::REASON_ABORTED,
                            ['An earlier category failed and continue_on_error is off.']
                        );
                        continue;
                    }
                    if ($this->hasRenamedAncestor($entry, $renamedPaths)) {
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

                    // One transaction per category: a failure leaves no
                    // half-written category, and the rollBack in the catch is
                    // what clears the connection's partial-rollback flag after
                    // a repository save failed inside it.
                    $connection->beginTransaction();
                    try {
                        $result = $this->processOne($entry, $parents, $siblings, $storeId, $renamedPaths);
                        $connection->commit();
                    } catch (\Throwable $e) {
                        $this->rollBackQuietly($connection);
                        $this->logger->error(
                            sprintf('Category "%s" sync failed: %s', $entry['label'], $e->getMessage()),
                            ['exception' => $e]
                        );
                        $result = $this->result(
                            $entry,
                            CategorySyncResultInterface::STATUS_ERROR,
                            null,
                            [sprintf('Sync failed: %s', $e->getMessage())]
                        );
                    }

                    if (in_array(
                        $result->getStatus(),
                        [CategorySyncResultInterface::STATUS_CREATED, CategorySyncResultInterface::STATUS_UPDATED],
                        true
                    )) {
                        $touchedIds[] = (int)$result->getEntityId();
                        $siblings = null;
                    }
                    if ($result->getStatus() === CategorySyncResultInterface::STATUS_ERROR && !$continueOnError) {
                        $aborted = true;
                    }
                    $results[] = $result;
                }
            }
        } finally {
            $this->lockManager->unlock(self::LOCK_NAME);
            // In the finally, not after it: every category here is already
            // committed, so if a later bucket throws, skipping invalidation
            // would leave the storefront serving stale pages for work that
            // did land.
            $this->invalidate($touchedIds);
        }

        $response = $this->buildResponse($received, $results, $startedAt);
        $this->logger->info(sprintf(
            'Category sync finished: %d received, %d created, %d updated, %d unchanged, %d skipped, %d failed in %d ms',
            $response->getReceived(),
            $response->getCreated(),
            $response->getUpdated(),
            $response->getUnchanged(),
            $response->getSkipped(),
            $response->getFailed(),
            $response->getElapsedMs()
        ));

        return $response;
    }

    /**
     * @param array{
     *     definition: ?CategoryDefinitionInterface,
     *     segments: string[],
     *     label: string,
     *     error: ?array{reason: string, message: string}
     * } $entry
     * @param array<string, array{id: ?int, reason: ?string, message: ?string}> $parents
     *        entry label => resolved parent, with the reason when it did not resolve
     * @param array<int, array<string, int[]>> $siblings parent_id => [name => entity_id[]]
     * @param array<string, true> $renamedPaths by reference; records renames for later entries
     */
    private function processOne(
        array $entry,
        array $parents,
        array $siblings,
        int $storeId,
        array &$renamedPaths
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
        $parentId = $parents[$entry['label']]['id'] ?? null;
        $messages = [];

        // position lives in a column on catalog_category_entity, which has no
        // store dimension — writing it at store scope would silently change
        // sibling order for every store.
        if ($storeId !== 0 && $definition->getPosition() !== null) {
            return $this->skip(
                $entry,
                CategorySyncResultInterface::REASON_STORE_SCOPE_STRUCTURAL_CHANGE,
                'Position has no store dimension; omit store_view_code to change it.'
            );
        }

        if ($categoryId !== null && $categoryId > 0) {
            $row = $this->categoryResource->getExistingByIds([$categoryId])[$categoryId] ?? null;
            if ($row === null) {
                return $this->skip(
                    $entry,
                    CategorySyncResultInterface::REASON_UNKNOWN_CATEGORY,
                    sprintf('Category ID %d does not exist.', $categoryId)
                );
            }
            if ($row['level'] <= 1) {
                return $this->skip(
                    $entry,
                    CategorySyncResultInterface::REASON_ROOT_NOT_WRITABLE,
                    'Root categories are not managed by this endpoint.',
                    $categoryId
                );
            }
            if ($parentId !== null && $parentId !== $row['parent_id']) {
                return $this->skip(
                    $entry,
                    CategorySyncResultInterface::REASON_MOVE_NOT_SUPPORTED,
                    sprintf(
                        'The category is under parent %d but the path implies parent %d.'
                        . ' Moving a category is not supported.',
                        $row['parent_id'],
                        $parentId
                    ),
                    $categoryId
                );
            }

            return $this->applyUpdate($entry, $categoryId, $name, $storeId, $messages, $renamedPaths);
        }

        if (count($segments) === 1) {
            return $this->skip(
                $entry,
                CategorySyncResultInterface::REASON_ROOT_NOT_WRITABLE,
                'Root categories are neither created nor modified by this endpoint.'
            );
        }
        if ($parentId === null) {
            return $this->skip(
                $entry,
                $parents[$entry['label']]['reason'] ?? CategorySyncResultInterface::REASON_PARENT_NOT_FOUND,
                $parents[$entry['label']]['message']
                    ?? sprintf('The parent of "%s" could not be resolved.', $entry['label'])
            );
        }

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
                    '%d categories named "%s" share this parent (IDs %s); send category_id to pick one.',
                    count($matches),
                    $leafName,
                    implode(', ', $matches)
                )
            );
        }

        if ($matches === []) {
            if ($storeId !== 0) {
                return $this->skip(
                    $entry,
                    CategorySyncResultInterface::REASON_STORE_SCOPE_STRUCTURAL_CHANGE,
                    'A category can only be created at default scope; omit store_view_code to create it.'
                );
            }

            $entityId = $this->writer->create($parentId, $leafName, $definition, $messages);

            return $this->result(
                $entry,
                CategorySyncResultInterface::STATUS_CREATED,
                null,
                $messages
            )->setEntityId($entityId);
        }

        return $this->applyUpdate($entry, (int)$matches[0], $leafName, $storeId, $messages, $renamedPaths);
    }

    /**
     * @param string[] $messages
     * @param array<string, true> $renamedPaths
     */
    private function applyUpdate(
        array $entry,
        int $entityId,
        ?string $name,
        int $storeId,
        array $messages,
        array &$renamedPaths
    ): CategorySyncResultInterface {
        $changed = $this->writer->update($entityId, $name, $entry['definition'], $storeId, $messages);

        // Only a DEFAULT-scope rename invalidates a path. Path resolution
        // matches store-0 names throughout this module, so a store-scoped
        // rename leaves every path resolving exactly where it did; treating it
        // as a rename would skip the whole subtree for nothing.
        if ($changed && $storeId === 0 && $name !== null && $entry['segments'] !== []) {
            $pathKey = PathParser::buildKey($entry['segments']);
            if ($name !== (string)end($entry['segments'])) {
                // The cached path now points at a differently named category,
                // and any later entry addressing a descendant by the old path
                // would resolve to the wrong node.
                $this->pathResolver->forget($pathKey);
                $renamedPaths[$pathKey] = true;
            }
        }

        return $this->result(
            $entry,
            $changed ? CategorySyncResultInterface::STATUS_UPDATED : CategorySyncResultInterface::STATUS_UNCHANGED,
            null,
            $messages
        )->setEntityId($entityId);
    }

    /**
     * Resolve each entry's parent in bulk: one root lookup plus one
     * level-by-level walk for the whole bucket, never a query per entry.
     *
     * @param array<int, array{segments: string[], label: string, ...}> $bucket
     * @return array<string, array{id: ?int, reason: ?string, message: ?string}> entry label => parent
     */
    private function resolveParents(array $bucket): array
    {
        $parents = [];
        $deepPaths = [];
        $deepKeyByLabel = [];
        $roots = null;

        foreach ($bucket as $entry) {
            $parentSegments = array_slice($entry['segments'], 0, -1);
            if ($parentSegments === []) {
                // Either a root-only path or an entry addressed purely by
                // category_id; neither needs a parent.
                $parents[$entry['label']] = ['id' => null, 'reason' => null, 'message' => null];
                continue;
            }
            if (count($parentSegments) === 1) {
                // The resolver refuses single-segment paths, so level-1 roots
                // are resolved directly here.
                $roots ??= $this->categoryResource->getRootCategories();
                $rootId = $roots[$parentSegments[0]] ?? null;
                $parents[$entry['label']] = $rootId !== null
                    ? ['id' => $rootId, 'reason' => null, 'message' => null]
                    : [
                        'id' => null,
                        'reason' => CategorySyncResultInterface::REASON_UNKNOWN_ROOT,
                        'message' => sprintf(
                            'Unknown root category "%s" — root categories are not created by this endpoint.',
                            $parentSegments[0]
                        ),
                    ];
                continue;
            }

            $key = PathParser::buildKey($parentSegments);
            $deepPaths[$key] = $parentSegments;
            $deepKeyByLabel[$entry['label']] = $key;
        }

        if ($deepPaths) {
            // lookupPaths(), not resolvePaths(): this endpoint never creates a
            // category the caller did not ask for. A missing ancestor is the
            // caller's to send, and creating it silently would produce a
            // category with none of the properties they specified anywhere.
            $resolved = $this->pathResolver->lookupPaths($deepPaths);
            foreach ($deepKeyByLabel as $label => $key) {
                $parents[$label] = isset($resolved[$key])
                    ? ['id' => $resolved[$key], 'reason' => null, 'message' => null]
                    : [
                        'id' => null,
                        'reason' => CategorySyncResultInterface::REASON_PARENT_NOT_FOUND,
                        'message' => sprintf('Parent category "%s" does not exist; send it too.', $key),
                    ];
            }
        }

        return $parents;
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
     */
    private function invalidate(array $touchedIds): void
    {
        try {
            $this->invalidationHandler->execute($touchedIds);
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
        $created = $updated = $unchanged = $skipped = $failed = 0;
        foreach ($results as $result) {
            match ($result->getStatus()) {
                CategorySyncResultInterface::STATUS_CREATED => $created++,
                CategorySyncResultInterface::STATUS_UPDATED => $updated++,
                CategorySyncResultInterface::STATUS_UNCHANGED => $unchanged++,
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
            ->setSkipped($skipped)
            ->setFailed($failed)
            ->setElapsedMs((int)((hrtime(true) - $startedAt) / 1_000_000))
            ->setResults($results);
    }
}
