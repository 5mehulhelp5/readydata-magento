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

/**
 * Orchestrates a bulk import: batching, transactions, the processor
 * pipeline, index invalidation and response assembly.
 */
class ImportService
{
    /**
     * Held by every endpoint that mutates the category tree.
     *
     * The product import creates categories on demand (see
     * CategoryPathResolver), and so does the category sync endpoint. There is
     * no unique key on (parent_id, name) or on a category url_key, so two
     * concurrent runs resolve the same missing path, both miss, and both
     * insert — leaving a duplicate sibling, or a url_rewrite unique-key
     * violation that fails whichever request loses. One lock for both is the
     * only thing that makes the read-then-create sequence safe.
     */
    public const TREE_WRITE_LOCK_NAME = 'readydata_product_import';

    private const LOCK_TIMEOUT_SEC = 10;

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

        if (!$this->lockManager->lock(self::TREE_WRITE_LOCK_NAME, self::LOCK_TIMEOUT_SEC)) {
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

                if (!$this->processBatch($context, $batchNumber) && !$continueOnError) {
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
            $this->lockManager->unlock(self::TREE_WRITE_LOCK_NAME);
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
    private function processBatch(BatchContext $context, int $batchNumber): bool
    {
        if (!$this->prepareBatch($context, $batchNumber)) {
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
     * MySQL's wait_timeout. That matters twice over: the transaction below would
     * fail on a dead connection anyway, and the import lock is a GET_LOCK on
     * this very connection, so a silent reconnect would release it and let a
     * second import run concurrently. Probe both before committing to the batch.
     *
     * @throws \RuntimeException when the connection or the lock was lost
     */
    private function assertConnectionAlive(): void
    {
        $this->resourceConnection->getConnection()->fetchOne('SELECT 1');

        if (!$this->lockManager->isLocked(self::TREE_WRITE_LOCK_NAME)) {
            throw new \RuntimeException(
                'the import lock was lost during preparation, most likely with the database connection'
            );
        }
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
        foreach ($context->getScopeStoreIds($sku) as $scopeStoreId) {
            /** @var StoreResultInterface $storeResult */
            $storeResult = $this->storeResultFactory->create();
            $storeResults[] = $storeResult
                ->setStoreId($scopeStoreId)
                ->setStatus($context->getScopeStatus($sku, $scopeStoreId))
                ->setMessages($context->getScopeMessages($sku, $scopeStoreId));
        }

        return $storeResults;
    }
}
