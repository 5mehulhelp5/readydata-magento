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
use ReadyData\Import\Model\Indexer\InvalidationHandler;
use ReadyData\Import\Model\Processor\CategoryLinkProcessor;
use ReadyData\Import\Model\Processor\PreparableInterface;
use ReadyData\Import\Model\Processor\ProcessorInterface;
use ReadyData\Import\Model\ResourceModel\AttributeOption;
use ReadyData\Import\Model\ResourceModel\ProductEntity;

/**
 * Orchestrates a bulk import: batching, transactions, the processor
 * pipeline, index invalidation and response assembly.
 *
 * Concurrency is scoped as tightly as the races allow. Locks are taken per
 * BATCH rather than per request, from the subset of {@see ImportLocks} that
 * batch's own payload can race on, and held only for its transaction — never
 * across the file downloads that precede it or the reindex that follows it. A
 * competing import therefore waits for one transaction, and only if it can race
 * on the same thing. See {@see processBatch()} and {@see batchLocks()}.
 */
class ImportService
{
    /**
     * @deprecated There is no single import lock any more: a batch takes the
     *             subset of {@see ImportLocks} its own payload can race on.
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
        private readonly ProductEntity $productEntity,
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

                if (!$this->processBatch($context, $batchNumber, $batch) && !$continueOnError) {
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
     * @param ProductInterface[] $batch this batch's slice of the payload
     * @return bool true when the batch committed
     * @throws LocalizedException when the FIRST batch cannot take its locks
     */
    private function processBatch(BatchContext $context, int $batchNumber, array $batch): bool
    {
        if (!$this->prepareBatch($context, $batchNumber)) {
            return false;
        }

        $locks = $this->batchLocks($batch);
        if (!$this->acquireLocks($locks, $batchNumber > 0)) {
            return $this->reportLockRejection($context, $batchNumber, $locks);
        }

        // The option memo is per request and survives batches, but the lock does
        // not: an option another request committed while this batch was
        // downloading is not in it. Dropped for EVERY batch, not only the ones
        // holding ATTRIBUTE_OPTIONS — with the lock, a stale memo is how
        // AttributeProcessor inserts a duplicate label under a lock that was
        // supposed to prevent exactly that; without it, a stale memo reports a
        // label that now exists as an unknown option. One re-read per attribute
        // per batch either way.
        $this->attributeOption->forget();

        $connection = $this->resourceConnection->getConnection();
        $committed = false;
        // Whether there is a transaction to roll back. beginTransaction() is
        // inside the try so a failure there still releases the locks, and calling
        // rollBack() after it would raise a second, misleading error on top of
        // the real one.
        $opened = false;

        try {
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
            $context->failAll(sprintf('Batch %d rolled back: %s', $batchNumber + 1, $e->getMessage()));
            $this->logger->error(
                sprintf('Batch %d failed: %s', $batchNumber + 1, $e->getMessage()),
                ['exception' => $e]
            );
        } finally {
            $this->releaseLocks($locks);
        }

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
     * callers recognise that message and back off.
     *
     * Any later batch has committed work whose results are worth returning, so
     * it fails as a batch instead — a request that threw here would hand back a
     * 400 with no results at all and leave the caller to discover by other means
     * which of its products landed.
     *
     * @param string[] $locks
     * @throws LocalizedException
     */
    private function reportLockRejection(BatchContext $context, int $batchNumber, array $locks): bool
    {
        if ($batchNumber === 0) {
            throw new LocalizedException(__('Another import is already running. Try again later.'));
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
     * Which of {@see ImportLocks} this batch's payload can race on. Empty for a
     * batch that can reach none of them, which then runs lock-free, concurrently
     * with anything else — a price or stock refresh over products that already
     * exist, typically.
     *
     * Decided per BATCH, not per request: one unknown SKU in a 5 000-product
     * payload used to make the whole request serialize against every other
     * import, when only the batch carrying it can create anything. The cost is
     * one indexed query per batch instead of one per request, next to the one
     * EntityProcessor makes anyway.
     *
     * The SKU test is the reason the others are worth making:
     * `catalog_product_entity.sku` is NOT unique, so an insert of an unknown SKU
     * is itself a race, and no payload field reveals it — only the database can
     * say whether the row is already there. A SKU that exists now and is deleted
     * before the batch writes would slip through; a product vanishing mid-import
     * fails the batch on its own terms.
     *
     * The rest are deliberately **conservative** — they ask what the payload
     * *could* reach, not what it would turn out to do:
     *
     * - a `categories` field is the only way into CategoryPathResolver, and
     *   whether any of its entries turns out to need creating is not knowable
     *   without resolving the tree, which is the work the lock is protecting;
     * - a `media` field is the only way into a gallery insert, and whether a
     *   file is already on the product is not knowable without the gallery read
     *   that same insert follows. `[]` counts: it means "remove everything",
     *   which is still work;
     * - a custom attribute value is the only way into option auto-creation, and
     *   telling a `select` from a text attribute needs the metadata cache the
     *   pipeline warms later. `status` and `visibility` are selects too, but
     *   AttributeProcessor skips them, and they are first-class fields here
     *   rather than custom attributes. `store_values` blocks are deliberately
     *   NOT consulted: their custom attributes only ever resolve option labels
     *   they did not create — see AttributeProcessor::ensureOptions().
     *
     * Erring towards taking a lock costs a serialized batch; erring the other way
     * costs a duplicate product, category, image or attribute option, and none of
     * those is cheap to undo.
     *
     * @param ProductInterface[] $products one batch
     * @return string[] in acquisition order
     */
    private function batchLocks(array $products): array
    {
        $locks = [];

        $skus = array_map(static fn (ProductInterface $product): string => $product->getSku(), $products);
        if (count($this->productEntity->getExistingBySkus($skus)) !== count($skus)) {
            $locks[] = ImportLocks::PRODUCT_CREATE;
        }

        $mediaEnabled = $this->config->isMediaEnabled();
        $createsOptions = $this->config->isCreateMissingOptions();
        foreach ($products as $product) {
            if ($product->getCategories() !== null) {
                $locks[] = ImportLocks::CATEGORY_TREE;
            }
            if ($mediaEnabled && $product->getMedia() !== null) {
                $locks[] = ImportLocks::MEDIA_GALLERY;
            }
            if ($createsOptions && $product->getCustomAttributes()) {
                $locks[] = ImportLocks::ATTRIBUTE_OPTIONS;
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
