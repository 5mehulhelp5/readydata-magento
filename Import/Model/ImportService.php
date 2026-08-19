<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;
use ReadyData\Import\Api\Data\ImportResponseInterface;
use ReadyData\Import\Api\Data\ImportResponseInterfaceFactory;
use ReadyData\Import\Api\Data\ImportResultInterface;
use ReadyData\Import\Api\Data\ImportResultInterfaceFactory;
use ReadyData\Import\Api\Data\ImportSettingsInterface;
use ReadyData\Import\Api\Data\ProductInterface;
use ReadyData\Import\Api\Data\StoreResultInterface;
use ReadyData\Import\Api\Data\StoreResultInterfaceFactory;
use ReadyData\Import\Logger\Logger;
use ReadyData\Import\Model\Cache\StoreWebsiteMap;
use ReadyData\Import\Model\Event\ImportEventDispatcher;
use ReadyData\Import\Model\Exception\ImportLockedException;
use ReadyData\Import\Model\Indexer\InvalidationHandler;
use ReadyData\Import\Model\Processor\CategoryLinkProcessor;
use ReadyData\Import\Model\Processor\LockAwareInterface;
use ReadyData\Import\Model\Processor\BatchCleanupInterface;
use ReadyData\Import\Model\Processor\LockedPreparableInterface;
use ReadyData\Import\Model\Processor\PreparableInterface;
use ReadyData\Import\Model\Processor\ProcessorInterface;
use ReadyData\Import\Model\ResourceModel\AttributeOption;

/**
 * Orchestrates a bulk import: batching, transactions, the processor
 * pipeline, index invalidation and response assembly.
 *
 * Concurrency is scoped as tightly as the races allow. Locks are taken per
 * BATCH rather than per request, and only the ones the batch's own steps say
 * they will actually create something under — not the ones its payload could in
 * principle reach. They are held only for its transaction, never across the file
 * downloads that precede it or the reindex that follows it. A competing import
 * therefore waits for one transaction, and only if it is going to create the
 * same kind of thing. See {@see processBatch()}, {@see batchLocks()} and
 * {@see \ReadyData\Import\Model\Processor\LockAwareInterface}.
 *
 * A batch runs in four phases, and which of them a step takes part in is decided
 * by the interfaces it implements:
 *
 * 1. {@see prepareBatch()} — PreparableInterface, outside the locks and the
 *    transaction. Network and filesystem work.
 * 2. {@see batchLocks()} then {@see acquireLocks()} — LockAwareInterface says
 *    what this batch will actually create; the set is taken all-or-nothing.
 * 3. {@see runLockedPreparation()} — LockedPreparableInterface, under the locks
 *    but still outside the transaction. Writes that go through a repository
 *    opening a transaction of its own, which cannot nest inside ours.
 * 4. the transaction — ProcessorInterface::process() for every step, then
 *    commit or rollback.
 */
class ImportService
{
    /**
     * @deprecated There is no single import lock any more: a batch takes only
     *             the {@see ImportLocks} its own steps will create under.
     * @see ImportLocks::CATEGORY_TREE
     * @see ImportService::batchLocks()
     */
    public const WRITE_LOCK_NAME = ImportLocks::CATEGORY_TREE;

    /**
     * @var ProcessorInterface[]
     */
    private readonly array $processors;

    /**
     * @param ProcessorInterface[] $processors pipeline steps, see di.xml
     */
    public function __construct(
        private readonly Config $config,
        private readonly ResourceConnection $resourceConnection,
        private readonly LockManagerInterface $lockManager,
        private readonly BatchContextFactory $batchContextFactory,
        private readonly AttributeOption $attributeOption,
        private readonly StoreWebsiteMap $storeWebsiteMap,
        private readonly InvalidationHandler $invalidationHandler,
        private readonly ImportEventDispatcher $eventDispatcher,
        private readonly ImportState $importState,
        private readonly ImportResponseInterfaceFactory $responseFactory,
        private readonly ImportResultInterfaceFactory $resultFactory,
        private readonly StoreResultInterfaceFactory $storeResultFactory,
        private readonly Logger $logger,
        array $processors = []
    ) {
        usort(
            $processors,
            static fn (ProcessorInterface $a, ProcessorInterface $b): int =>
                $a->getSortOrder() <=> $b->getSortOrder()
        );
        $this->processors = $processors;
    }

    /**
     * @param ProductInterface[] $products
     * @throws LocalizedException
     */
    public function import(array $products, ?ImportSettingsInterface $settings = null): ImportResponseInterface
    {
        if (!$this->config->isEnabled()) {
            throw new LocalizedException(__('ReadyData import is disabled in configuration.'));
        }

        $startedAt = hrtime(true);
        $received = count($products);
        $products = $this->prepareProducts($products);

        if (!$products) {
            throw new LocalizedException(__('The request contains no importable products.'));
        }

        $batchSize = $settings?->getBatchSize() ?: $this->config->getBatchSize();
        $continueOnError = $settings?->getContinueOnError() ?? $this->config->isContinueOnError();
        $storeId = $this->storeWebsiteMap->resolveScopeStoreId(
            $settings?->getStoreId(),
            $settings?->getStoreViewCode()
        );

        /** @var BatchContext[] $contexts */
        $contexts = [];

        $this->importState->enter();
        try {
            foreach (array_chunk($products, $batchSize) as $batchNumber => $batch) {
                $context = $this->batchContextFactory->create([
                    'products' => $batch,
                    'storeId' => $storeId,
                    'rootCategoryId' => $settings?->getRootCategoryId(),
                ]);
                $contexts[] = $context;

                if (!$this->processBatch($context, $batchNumber) && !$continueOnError) {
                    break;
                }
            }
        } finally {
            // In the finally, and AFTER the batch loop rather than inside it:
            // every batch that committed is already visible to the storefront,
            // so a later batch throwing must not leave that work un-indexed with
            // stale FPC entries. This is also the reason it sits outside the
            // locks — reindexList() over a large payload is easily the longest
            // thing this request does, and it races with nothing.
            $this->invalidate($contexts);
            $this->importState->leave();
        }

        $response = $this->buildResponse($received, $contexts, $storeId, $startedAt);
        $this->logger->info(sprintf(
            'Import finished: %d received, %d created, %d updated, %d failed in %d ms',
            $response->getReceived(),
            $response->getCreated(),
            $response->getUpdated(),
            $response->getFailed(),
            $response->getElapsedMs()
        ));

        return $response;
    }

    /**
     * Run the processor pipeline for one batch inside a transaction, holding
     * only the locks this batch's own payload can race on and only for as long
     * as the transaction.
     *
     * The order is what keeps the rejection window short. File acquisition
     * happens FIRST, unlocked: downloads are the longest thing a batch does and
     * they race with nothing. The locks are taken once the bytes are on disk,
     * and released the moment the transaction resolves — so a competing import
     * waits for one transaction, never for a feed's worth of image downloads.
     *
     * The cost of that order is that a request which is ultimately rejected has
     * already downloaded its first batch's files. Those files are not wasted:
     * FileResolver maps a URL to a deterministic path and skips what is already
     * there, so the caller's retry re-uses every one of them.
     *
     * @return bool true when the batch committed
     * @throws LocalizedException when the FIRST batch cannot take its locks
     */
    private function processBatch(BatchContext $context, int $batchNumber): bool
    {
        if (!$this->prepareBatch($context, $batchNumber)) {
            return false;
        }

        $locks = $this->batchLocks($context);
        if (!$this->acquireLocks($locks, $batchNumber > 0)) {
            return $this->reportLockRejection($context, $batchNumber, $locks);
        }
        // What was taken, for the steps that may only create what they declared.
        $context->setHeldLocks($locks);

        if (in_array(ImportLocks::ATTRIBUTE_OPTIONS, $locks, true)) {
            // The option memo is per request and survives batches, but the lock
            // does not: an option another request committed while this batch was
            // downloading is not in it, and createOptions() trusts the memo to
            // decide what is missing — a stale entry is how a duplicate label
            // gets written under the very lock meant to prevent it.
            //
            // Only when the lock was taken. A batch without it creates nothing,
            // so a stale memo cannot cause a duplicate there, and the memo it
            // keeps is the one its own lock predicate just warmed — the read
            // would otherwise be made twice per batch to no end.
            $this->attributeOption->forget();
        }

        $connection = $this->resourceConnection->getConnection();
        $committed = false;
        // Whether there is a transaction to roll back. beginTransaction() is
        // inside the try so a failure there still releases the locks, and calling
        // rollBack() after it would raise a second, misleading error on top of
        // the real one. The locked-preparation phase below is inside the try for
        // the same reason, and relies on the same flag.
        $opened = false;

        try {
            // Under the locks, before the transaction: steps whose writes go
            // through a repository that opens its own transaction, which cannot
            // nest inside ours. See LockedPreparableInterface.
            $this->runLockedPreparation($context);

            $connection->beginTransaction();
            $opened = true;

            foreach ($this->processors as $processor) {
                if ($processor->isEnabled()) {
                    $processor->process($context);
                }
            }
            // Inside the transaction: a throwing save_after observer rolls the batch back.
            $this->eventDispatcher->dispatchBeforeCommit($context);
            $connection->commit();
            $committed = true;
        } catch (\Throwable $e) {
            if ($opened) {
                $connection->rollBack();
            }
            $context->failAll(sprintf(
                // Only say "rolled back" when there was something to roll back:
                // a failure in the locked-preparation phase, or in
                // beginTransaction() itself, happens before any transaction
                // exists, and reporting a rollback that never occurred sends
                // whoever reads this looking for the wrong thing.
                $opened
                    ? 'Batch %d rolled back: %s'
                    : 'Batch %d failed before its transaction opened: %s',
                $batchNumber + 1,
                $e->getMessage()
            ));
            $this->logger->error(
                sprintf('Batch %d failed: %s', $batchNumber + 1, $e->getMessage()),
                ['exception' => $e]
            );
        } finally {
            $this->releaseLocks($locks);
        }

        // Deliberately NOT inside dispatchAfterCommit(): that method returns
        // early when product events are switched off, which is a setting about
        // third-party observers. Releasing resources this batch acquired is our
        // own business and must not vanish with the event layer. It also has no
        // place behind the observer-failure guard, which exists for other
        // people's code.
        $this->runBatchCleanup($context, $committed);

        if ($committed) {
            // After the commit AND after the locks: an observer is someone
            // else's code doing an unknown amount of work, and everything this
            // batch inserted is committed and visible by now, so there is
            // nothing left for the locks to protect. Guarded internally so
            // observer failures don't fail the import.
            $this->eventDispatcher->dispatchAfterCommit($context);
        }

        return $committed;
    }

    /**
     * Take a batch's locks, in {@see ImportLocks::inAcquisitionOrder()}.
     *
     * All or nothing: a set half-acquired is released before returning, so a
     * request that gives up never leaves a name held while it waits for its
     * caller to retry.
     *
     * @param string[] $locks in acquisition order
     * @param bool $isContinuation whether earlier batches have already committed
     */
    private function acquireLocks(array $locks, bool $isContinuation): bool
    {
        $timeout = $isContinuation ? ImportLocks::CONTINUATION_TIMEOUT_SEC : ImportLocks::TIMEOUT_SEC;

        $acquired = [];
        foreach ($locks as $lock) {
            if (!$this->lockManager->lock($lock, $timeout)) {
                $this->releaseLocks($acquired);

                return false;
            }
            $acquired[] = $lock;
        }

        return true;
    }

    /**
     * @param string[] $locks
     */
    private function releaseLocks(array $locks): void
    {
        foreach (array_reverse($locks) as $lock) {
            $this->lockManager->unlock($lock);
        }
    }

    /**
     * A rejected batch, reported the way the caller can act on.
     *
     * The FIRST batch throws, with the wording it has always thrown: nothing is
     * committed, so the honest answer is that the request did not happen, and
     * callers recognise that message and back off. It is an
     * {@see ImportLockedException} rather than a plain LocalizedException so the
     * refusal carries its own status code (429) and a machine-readable reason,
     * and a caller no longer has to match the message to know it is retryable.
     *
     * Any later batch has committed work whose results are worth returning, so
     * it fails as a batch instead — a request that threw here would hand back an
     * error with no results at all and leave the caller to discover by other
     * means which of its products landed.
     *
     * @param string[] $locks
     * @throws ImportLockedException
     */
    private function reportLockRejection(BatchContext $context, int $batchNumber, array $locks): bool
    {
        if ($batchNumber === 0) {
            throw new ImportLockedException(
                __('Another import is already running. Try again later.'),
                $locks,
                ImportLocks::TIMEOUT_SEC
            );
        }

        $message = sprintf(
            'Batch %d was not started: another import is holding %s.',
            $batchNumber + 1,
            implode(', ', $locks)
        );
        $context->failAll($message);
        $this->logger->error($message);

        return false;
    }

    /**
     * Which of {@see ImportLocks} this batch needs: the union of what its
     * {@see LockAwareInterface} steps declare. Empty for a batch that can create
     * nothing unkeyed, which then runs lock-free, concurrently with anything
     * else — a price or stock refresh over products that already exist, but also
     * any push whose categories, options and products are all already there,
     * which is what a steady-state feed mostly is.
     *
     * Decided per BATCH, not per request: one unknown SKU in a 5 000-product
     * payload used to make the whole request serialize against every other
     * import, when only the batch carrying it can create anything.
     *
     * Asked of the STEPS rather than decided here, because the question is
     * "will this create something", and only the code that does the creating can
     * answer it without drifting from itself. Each step probes what already
     * exists — see the implementations — so the answer is about what is actually
     * missing rather than about which payload fields are present. That
     * distinction is the whole value: measured on prelive, a batch whose
     * categories all existed still held the tree lock for 322 ms and cost a
     * competing import 572 ms, to guard a create that never happened.
     *
     * The probes read before the locks are taken, so what they saw can be gone
     * by the time the transaction runs. That is not a hole: a step may only
     * create what it declared, checks {@see BatchContext::holdsLock()} before
     * doing so, and reports the product instead of creating unguarded.
     *
     * @return string[] in acquisition order
     */
    private function batchLocks(BatchContext $context): array
    {
        $locks = [];
        foreach ($this->processors as $processor) {
            if ($processor instanceof LockAwareInterface && $processor->isEnabled()) {
                $locks = array_merge($locks, $processor->requiredLocks($context));
            }
        }

        return ImportLocks::inAcquisitionOrder($locks);
    }

    /**
     * Index and cache maintenance for everything the batches committed.
     *
     * Swallows its own failures, because it runs in a finally: a throwing
     * indexer or cache-tag observer would otherwise REPLACE the exception the
     * import is already failing with, and a caller told "cache backend
     * unreachable" instead of "another import is already running" stops
     * retrying. Same reasoning, same shape as the category endpoint's.
     *
     * @param BatchContext[] $contexts
     */
    private function invalidate(array $contexts): void
    {
        if (!$contexts) {
            return;
        }

        try {
            $affectedIds = array_merge(...array_map(
                static fn (BatchContext $c): array => $c->getValidEntityIds(),
                $contexts
            ));
            // Rolled-back batches may leave category IDs in the data bag;
            // harmless — at worst an extra cache/index refresh.
            $affectedCategoryIds = array_merge(...array_map(
                static fn (BatchContext $c): array =>
                    $c->get(CategoryLinkProcessor::CONTEXT_AFFECTED_CATEGORY_IDS, []),
                $contexts
            ));

            $this->invalidationHandler->execute($affectedIds, $affectedCategoryIds);
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf('Post-import invalidation failed: %s', $e->getMessage()),
                ['exception' => $e]
            );
        }
    }

    /**
     * Let every BatchCleanupInterface step release what the batch acquired
     * outside the transaction — today, media files that a commit orphaned or a
     * rollback stranded.
     *
     * Runs after the locks are released and whichever way the batch went, since
     * a filesystem has no rollback and both outcomes leave something behind.
     *
     * Failures are swallowed on purpose. After a commit the products are saved
     * and visible, and turning a tidying-up problem into a failed batch would
     * misreport work that succeeded. After a rollback there is already a real
     * error on its way to the caller, and replacing it with this one would hide
     * the reason the batch failed.
     */
    private function runBatchCleanup(BatchContext $context, bool $committed): void
    {
        foreach ($this->processors as $processor) {
            if (!$processor instanceof BatchCleanupInterface || !$processor->isEnabled()) {
                continue;
            }
            try {
                if ($committed) {
                    $processor->cleanUpAfterCommit($context);
                } else {
                    $processor->cleanUpAfterRollback($context);
                }
            } catch (\Throwable $e) {
                $this->logger->error(
                    sprintf(
                        'Batch cleanup after a %s batch failed: %s',
                        $committed ? 'committed' : 'rolled-back',
                        $e->getMessage()
                    ),
                    ['exception' => $e]
                );
            }
        }
    }

    /**
     * Pre-transaction phase: steps implementing PreparableInterface do their
     * network/filesystem work here, OUTSIDE the batch transaction AND outside
     * the batch's locks, so neither row locks nor named locks are held across
     * remote I/O — a batch of image downloads would otherwise hold write locks
     * on the gallery and EAV tables, and the import locks with them, for
     * minutes. Same sort order and same isEnabled() gate as the pipeline itself.
     *
     * A throw here is a batch-level failure and is reported WITHOUT rollBack():
     * no transaction is open yet, and calling it would raise a second,
     * misleading error.
     *
     * @return bool true when the batch may proceed to its locks and transaction
     */
    private function prepareBatch(BatchContext $context, int $batchNumber): bool
    {
        $preparables = array_filter(
            $this->processors,
            static fn (ProcessorInterface $p): bool => $p instanceof PreparableInterface && $p->isEnabled()
        );
        if (!$preparables) {
            return true;
        }

        try {
            foreach ($preparables as $processor) {
                /** @var PreparableInterface $processor */
                $processor->prepare($context);
            }
            $this->assertConnectionAlive();

            return true;
        } catch (\Throwable $e) {
            $message = sprintf('Batch %d preparation failed: %s', $batchNumber + 1, $e->getMessage());
            $context->failAll($message);
            $this->logger->error($message, ['exception' => $e]);

            return false;
        }
    }

    /**
     * The phase between the locks and the transaction: steps implementing
     * LockedPreparableInterface write through code that opens a transaction of
     * its own, which cannot nest inside the batch's.
     *
     * Deliberately does NOT catch. It runs inside {@see processBatch()}'s try, so
     * a throw lands in the one handler that already knows whether a transaction
     * was opened and releases the locks in its finally — catching here would
     * duplicate that logic and lose the distinction.
     *
     * No assertConnectionAlive() either: {@see prepareBatch()} already probed,
     * the locks were then taken on that live connection, and nothing idle has
     * happened in between.
     */
    private function runLockedPreparation(BatchContext $context): void
    {
        foreach ($this->processors as $processor) {
            if ($processor instanceof LockedPreparableInterface && $processor->isEnabled()) {
                $processor->prepareUnderLocks($context);
            }
        }
    }

    /**
     * A long preparation phase leaves the database connection idle and can trip
     * MySQL's wait_timeout, which the transaction below would then fail on.
     * Probe it, so a reconnect happens here rather than mid-batch.
     *
     * This used to also assert that the import lock had survived preparation,
     * because the lock is a GET_LOCK on this very connection and a silent
     * reconnect drops it. That hazard is gone by construction: the locks are now
     * taken AFTER this point, so no lock is ever held across the idle phase, and
     * this probe is what makes sure they are taken on a live connection.
     *
     * @throws \RuntimeException when the connection was lost
     */
    private function assertConnectionAlive(): void
    {
        $this->resourceConnection->getConnection()->fetchOne('SELECT 1');
    }

    /**
     * Validate SKUs and de-duplicate the payload (last occurrence wins).
     *
     * @param ProductInterface[] $products
     * @return ProductInterface[]
     */
    private function prepareProducts(array $products): array
    {
        $bySku = [];
        foreach ($products as $product) {
            $sku = trim($product->getSku());
            if ($sku === '') {
                continue;
            }
            $product->setSku($sku);
            $bySku[$sku] = $product;
        }

        return array_values($bySku);
    }

    /**
     * The counters count PRODUCTS, not product-scopes: a product created with
     * three localized value sets is one creation. Counting scopes would make
     * every existing dashboard read four times too high the day a caller starts
     * sending `store_values`.
     *
     * @param BatchContext[] $contexts
     */
    private function buildResponse(
        int $received,
        array $contexts,
        int $storeId,
        int $startedAt
    ): ImportResponseInterface {
        $results = [];
        $created = $updated = $failed = 0;

        foreach ($contexts as $context) {
            foreach ($context->getSkus() as $sku) {
                $status = $context->getStatus($sku);
                match ($status) {
                    ImportResultInterface::STATUS_CREATED => $created++,
                    ImportResultInterface::STATUS_UPDATED => $updated++,
                    default => $failed++,
                };

                /** @var ImportResultInterface $result */
                $result = $this->resultFactory->create();
                // Only the product's own messages: a message raised writing one
                // of its scopes rides that scope's result instead, so nothing is
                // reported twice.
                $result->setSku((string)$sku)
                    ->setStatus($status)
                    ->setMessages($context->getScopeMessages($sku, null));
                if (($entityId = $context->getEntityId($sku)) !== null) {
                    $result->setEntityId($entityId);
                }
                if (($storeResults = $this->buildStoreResults($context, $sku)) !== []) {
                    $result->setStoreResults($storeResults);
                }
                $results[] = $result;
            }
        }

        /** @var ImportResponseInterface $response */
        $response = $this->responseFactory->create();

        return $response->setReceived($received)
            ->setCreated($created)
            ->setUpdated($updated)
            ->setFailed($failed)
            ->setElapsedMs((int)((hrtime(true) - $startedAt) / 1_000_000))
            ->setStoreId($storeId)
            ->setResults($results);
    }

    /**
     * One result per store scope the product's payload named beyond the
     * request's own. Empty for a payload without `store_values`, which leaves
     * the field off the response entirely.
     *
     * @return StoreResultInterface[]
     */
    private function buildStoreResults(BatchContext $context, string|int $sku): array
    {
        $storeResults = [];
        foreach ($context->getScopeResults($sku) as $scope) {
            /** @var StoreResultInterface $storeResult */
            $storeResult = $this->storeResultFactory->create();
            $storeResults[] = $storeResult
                ->setStoreId($scope['store_id'])
                ->setStatus($scope['status'])
                ->setReason($scope['reason'])
                ->setMessages($scope['messages']);
        }

        return $storeResults;
    }
}
