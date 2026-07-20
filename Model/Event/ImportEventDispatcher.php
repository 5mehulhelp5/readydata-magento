<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Event;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ProductFactory;
use Magento\Framework\Event\ManagerInterface as EventManager;
use ReadyData\Import\Api\Data\ProductInterface;
use ReadyData\Import\Logger\Logger;
use ReadyData\Import\Model\BatchContext;
use ReadyData\Import\Model\Config;
use ReadyData\Import\Model\Processor\AttributeProcessor;
use ReadyData\Import\Model\Processor\CategoryLinkProcessor;
use ReadyData\Import\Model\Processor\EntityProcessor;
use ReadyData\Import\Model\ResourceModel\ProductEntity;

/**
 * Central owner of the module's event policy.
 *
 * The importer writes via direct SQL, so none of the AbstractModel save
 * events fire on their own. This service re-emits the relevant core events
 * (plus complementary custom ones) so downstream observers still react to
 * imported products.
 *
 * Timing mirrors core: {@see catalog_product_save_after} is dispatched inside
 * the batch transaction (before commit); {@see catalog_product_save_commit_after}
 * after it. Per-product `catalog_product_save_after` is off by default and
 * enabled only via the $dispatchSaveAfter constructor toggle (di.xml, no admin
 * UI) — when on, the conflicting native observers are suppressed for the
 * duration of the import (see Plugin/) so they never double-write.
 */
class ImportEventDispatcher
{
    public const EVENT_PRODUCTS_SAVE_AFTER = 'readydata_import_products_save_after';
    public const EVENT_ATTRIBUTE_OPTIONS_CREATED = 'readydata_import_attribute_options_created';
    public const EVENT_CATEGORY_PRODUCTS_CHANGED = 'readydata_import_category_products_changed';

    public function __construct(
        private readonly ProductFactory $productFactory,
        private readonly EventManager $eventManager,
        private readonly ProductEntity $productEntity,
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly bool $dispatchSaveAfter = false
    ) {
    }

    /**
     * Fire the in-transaction `*_save_after` events. No-op unless the in-code
     * toggle is enabled. Runs inside the batch transaction, so a throwing
     * observer must propagate and roll the batch back (core semantics).
     */
    public function dispatchBeforeCommit(BatchContext $context): void
    {
        if (!$this->dispatchSaveAfter || !$this->config->isDispatchProductEvents()) {
            return;
        }

        foreach ($this->buildProducts($context) as $product) {
            $this->eventManager->dispatch('model_save_after', ['object' => $product]);
            $this->eventManager->dispatch(
                'catalog_product_save_after',
                ['data_object' => $product, 'product' => $product]
            );
        }
    }

    /**
     * Fire the post-commit events: per-product `*_save_commit_after` plus the
     * custom batch events. The batch is already committed, so observer
     * failures are logged and swallowed rather than failing the import.
     */
    public function dispatchAfterCommit(BatchContext $context): void
    {
        if (!$this->config->isDispatchProductEvents()) {
            return;
        }

        // Two guarded sections: a throwing third-party save_commit_after observer
        // must neither fail the (already committed) import nor suppress the
        // module's own readydata_import_* notifications.
        try {
            foreach ($this->buildProducts($context) as $product) {
                $this->eventManager->dispatch('model_save_commit_after', ['object' => $product]);
                $this->eventManager->dispatch(
                    'catalog_product_save_commit_after',
                    ['data_object' => $product, 'product' => $product]
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf('Post-commit product event dispatch failed: %s', $e->getMessage()),
                ['exception' => $e]
            );
        }

        try {
            $this->dispatchCustomEvents($context);
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf('Custom import event dispatch failed: %s', $e->getMessage()),
                ['exception' => $e]
            );
        }
    }

    private function dispatchCustomEvents(BatchContext $context): void
    {
        $skuToId = [];
        $createdSkus = [];
        $updatedSkus = [];
        foreach (array_keys($context->getValidProducts()) as $sku) {
            $entityId = $context->getEntityId($sku);
            if ($entityId === null) {
                continue;
            }
            $skuToId[(string)$sku] = $entityId;
            if ($context->isExisting($sku)) {
                $updatedSkus[] = (string)$sku;
            } else {
                $createdSkus[] = (string)$sku;
            }
        }

        if ($skuToId) {
            $this->eventManager->dispatch(self::EVENT_PRODUCTS_SAVE_AFTER, [
                'store_id' => $context->getStoreId(),
                'sku_to_id' => $skuToId,
                'created_skus' => $createdSkus,
                'updated_skus' => $updatedSkus,
                'entity_ids' => array_values($skuToId),
            ]);
        }

        $createdOptions = $context->get(AttributeProcessor::CONTEXT_CREATED_OPTIONS, []);
        if ($createdOptions) {
            $this->eventManager->dispatch(self::EVENT_ATTRIBUTE_OPTIONS_CREATED, [
                'options_by_attribute' => $createdOptions,
            ]);
        }

        $categoryIds = $context->get(CategoryLinkProcessor::CONTEXT_AFFECTED_CATEGORY_IDS, []);
        if ($categoryIds) {
            $this->eventManager->dispatch(self::EVENT_CATEGORY_PRODUCTS_CHANGED, [
                'store_id' => $context->getStoreId(),
                'category_ids' => array_values($categoryIds),
                'product_ids' => array_values(
                    $context->get(CategoryLinkProcessor::CONTEXT_AFFECTED_PRODUCT_IDS, [])
                ),
            ]);
        }
    }

    /**
     * One lightweight product model per valid, resolved product. The object is
     * NOT reloaded from the DB — it carries the imported data plus its id/sku/
     * store. Observers needing more can reload by id (the row is persisted).
     *
     * @return Product[] keyed by SKU
     */
    private function buildProducts(BatchContext $context): array
    {
        $linkIds = $context->get(EntityProcessor::CONTEXT_LINK_IDS, []);
        $linkField = $this->productEntity->getLinkField();
        $products = [];

        foreach ($context->getValidProducts() as $sku => $dto) {
            $entityId = $context->getEntityId($sku);
            if ($entityId === null) {
                continue;
            }
            $products[$sku] = $this->buildProduct(
                $context,
                (string)$sku,
                $dto,
                $entityId,
                $linkField,
                isset($linkIds[$sku]) ? (int)$linkIds[$sku] : $entityId
            );
        }

        return $products;
    }

    private function buildProduct(
        BatchContext $context,
        string $sku,
        ProductInterface $dto,
        int $entityId,
        string $linkField,
        int $linkId
    ): Product {
        /** @var Product $product */
        $product = $this->productFactory->create();
        $product->setData('entity_id', $entityId);
        $product->setId($entityId);
        if ($linkField !== 'entity_id') {
            $product->setData($linkField, $linkId);
        }
        $product->setData('sku', $sku);
        $product->setStoreId($context->getStoreId());
        $product->setData('type_id', $dto->getTypeId() ?: 'simple');

        $scalars = [
            'name' => $dto->getName(),
            'price' => $dto->getPrice(),
            'status' => $dto->getStatus(),
            'visibility' => $dto->getVisibility(),
            'weight' => $dto->getWeight(),
            'url_key' => $dto->getUrlKey(),
        ];
        foreach ($scalars as $code => $value) {
            if ($value !== null) {
                $product->setData($code, $value);
            }
        }
        foreach ($dto->getCustomAttributes() ?? [] as $customAttribute) {
            $product->setData($customAttribute->getAttributeCode(), $customAttribute->getValue());
        }

        $product->setIsObjectNew(!$context->isExisting($sku));

        return $product;
    }
}
