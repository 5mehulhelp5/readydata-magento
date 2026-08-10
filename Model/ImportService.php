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
use ReadyData\Import\Model\ResourceModel\ProductEntity;

/**
 * Orchestrates a bulk import: batching, transactions, the processor
 * pipeline, index invalidation and response assembly.
 */
class ImportService
{
    /**
     * @deprecated Use {@see ImportLocks::PRODUCT_IMPORT}, which documents what
     *             the lock guards and is shared with the category endpoint.
     * @see ImportLocks::PRODUCT_IMPORT
     */
    public const WRITE_LOCK_NAME = ImportLocks::PRODUCT_IMPORT;

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

        $needsLock = $this->needsWriteLock($products);
        if ($needsLock && !$this->lockManager->lock(ImportLocks::PRODUCT_IMPORT, ImportLocks::TIMEOUT_SEC)) {
            throw new LocalizedException(__('Another import is already running. Try again later.'));
        }

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

                if (!$this->processBatch($context, $batchNumber, $needsLock) && !$continueOnError) {
                    break;
                }
            }

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
        } finally {
            $this->importState->leave();
            if ($needsLock) {
                $this->lockManager->unlock(ImportLocks::PRODUCT_IMPORT);
            }
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
     * Run the processor pipeline for one batch inside a transaction.
     *
     * @return bool true when the batch committed
     */
    private function processBatch(BatchContext $context, int $batchNumber, bool $holdsLock): bool
    {
        if (!$this->prepareBatch($context, $batchNumber, $holdsLock)) {
            return false;
        }

        $connection = $this->resourceConnection->getConnection();
        $connection->beginTransaction();

        try {
            foreach ($this->processors as $processor) {
                if ($processor->isEnabled()) {
                    $processor->process($context);
                }
            }
            // Inside the transaction: a throwing save_after observer rolls the batch back.
            $this->eventDispatcher->dispatchBeforeCommit($context);
            $connection->commit();
            // After commit: guarded internally so observer failures don't fail the import.
            $this->eventDispatcher->dispatchAfterCommit($context);

            return true;
        } catch (\Throwable $e) {
            $connection->rollBack();
            $context->failAll(sprintf('Batch %d rolled back: %s', $batchNumber + 1, $e->getMessage()));
            $this->logger->error(
                sprintf('Batch %d failed: %s', $batchNumber + 1, $e->getMessage()),
                ['exception' => $e]
            );

            return false;
        }
    }

    /**
     * Pre-transaction phase: steps implementing PreparableInterface do their
     * network/filesystem work here, OUTSIDE the batch transaction, so no row
     * locks are held across remote I/O — a batch of image downloads would
     * otherwise hold write locks on the gallery and EAV tables for minutes.
     * Same sort order and same isEnabled() gate as the pipeline itself.
     *
     * A throw here is a batch-level failure and is reported WITHOUT rollBack():
     * no transaction is open yet, and calling it would raise a second,
     * misleading error.
     *
     * @return bool true when the batch may proceed to its transaction
     */
    private function prepareBatch(BatchContext $context, int $batchNumber, bool $holdsLock): bool
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
            $this->assertConnectionAlive($holdsLock);

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
     * MySQL's wait_timeout. That matters twice over: the transaction below would
     * fail on a dead connection anyway, and the import lock is a GET_LOCK on
     * this very connection, so a silent reconnect would release it and let a
     * second import run concurrently. Probe both before committing to the batch.
     *
     * The lock half only applies when this request took one: a payload that can
     * create nothing unkeyed never held it, and asserting a lock we never
     * acquired would fail every such batch.
     *
     * @throws \RuntimeException when the connection or the lock was lost
     */
    private function assertConnectionAlive(bool $holdsLock): void
    {
        $this->resourceConnection->getConnection()->fetchOne('SELECT 1');

        if ($holdsLock && !$this->lockManager->isLocked(ImportLocks::PRODUCT_IMPORT)) {
            throw new \RuntimeException(
                'the import lock was lost during preparation, most likely with the database connection'
            );
        }
    }

    /**
     * Whether this payload can reach any of the unkeyed read-then-create
     * sequences {@see ImportLocks::PRODUCT_IMPORT} exists for. A payload that
     * can reach none runs lock-free, concurrently with anything else — a price
     * or stock refresh over products that already exist, typically.
     *
     * The first test costs one indexed query and is the reason the others are
     * worth making: `catalog_product_entity.sku` is NOT unique, so an insert of
     * an unknown SKU is itself a race, and no payload field reveals it — only
     * the database can say whether the row is already there. Reading it here
     * rather than trusting the payload is what keeps the lock-free path narrow
     * enough to be safe. A SKU that exists now and is deleted before the batch
     * writes would slip through; a product vanishing mid-import fails the batch
     * on its own terms.
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
     * Erring towards taking the lock costs a serialized request; erring the
     * other way costs a duplicate product, category, image or attribute option,
     * and none of those is cheap to undo.
     *
     * @param ProductInterface[] $products
     */
    private function needsWriteLock(array $products): bool
    {
        $skus = array_map(static fn (ProductInterface $product): string => $product->getSku(), $products);
        if (count($this->productEntity->getExistingBySkus($skus)) !== count($skus)) {
            return true;
        }

        $mediaEnabled = $this->config->isMediaEnabled();
        foreach ($products as $product) {
            if ($product->getCategories() !== null) {
                return true;
            }
            if ($mediaEnabled && $product->getMedia() !== null) {
                return true;
            }
        }

        if (!$this->config->isCreateMissingOptions()) {
            return false;
        }

        foreach ($products as $product) {
            if ($product->getCustomAttributes()) {
                return true;
            }
        }

        return false;
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
