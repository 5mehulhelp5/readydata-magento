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
use ReadyData\Import\Model\Processor\MediaProcessor;
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
 * enabled via the "Also Dispatch catalog_product_save_after" admin setting
 * ({@see Config::isDispatchSaveAfter()}) — when on, the conflicting native
 * observers are suppressed for the duration of the import (see Plugin/) so they
 * never double-write.
 *
 * The dispatched product carries its media gallery and image roles when
 * {@see Config::isHydrateEventMedia()} is on ({@see ProductMediaHydrator}), and
 * otherwise the payload's scalars only. It carries **no origData** either way:
 * the importer never reads pre-image state, so `getOrigData()` is empty and
 * `dataHasChangedFor()` reports every field as changed. That is deliberate — a
 * partial origData would answer "unchanged" for fields nobody snapshotted, and
 * populating it at all makes core skip the protective reload it does for an
 * entity with no original data. What this import changed is reported by the
 * custom events instead, per dimension.
 *
 * The object is a notification carrier, not a persistable entity: it has no
 * attribute set, so saving it through the EAV resource would schedule value-row
 * deletions.
 */
class ImportEventDispatcher
{
    public const EVENT_PRODUCTS_SAVE_AFTER = 'readydata_import_products_save_after';
    public const EVENT_ATTRIBUTE_OPTIONS_CREATED = 'readydata_import_attribute_options_created';
    public const EVENT_CATEGORY_PRODUCTS_CHANGED = 'readydata_import_category_products_changed';
    public const EVENT_PRODUCT_MEDIA_CHANGED = 'readydata_import_product_media_changed';

    /**
     * The batch the memo below belongs to. One slot, not a map: this service is
     * a request-lifetime singleton and an import runs many batches, so keying by
     * context would pin every batch's products for the whole request.
     */
    private ?BatchContext $builtFor = null;

    /**
     * @var Product[] keyed by SKU
     */
    private array $builtProducts = [];

    public function __construct(
        private readonly ProductFactory $productFactory,
        private readonly EventManager $eventManager,
        private readonly ProductEntity $productEntity,
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly ProductMediaHydrator $mediaHydrator
    ) {
    }

    /**
     * Fire the in-transaction `*_save_after` events per product. No-op unless
     * both the master event switch and the "Also Dispatch
     * catalog_product_save_after" setting are enabled. Runs inside the batch
     * transaction, so a throwing observer must propagate and roll the batch
     * back (core semantics).
     */
    public function dispatchBeforeCommit(BatchContext $context): void
    {
        if (!$this->config->isDispatchProductEvents() || !$this->config->isDispatchSaveAfter()) {
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
     * custom batch events. The batch is already committed, so nothing in here
     * may throw: ImportService calls this from inside processBatch()'s try, and
     * an escaping exception would take that catch's rollBack() to a transaction
     * that no longer exists and then failAll() products that are persisted and
     * correct. Every failure is logged and swallowed instead — a missed
     * notification is recoverable, a batch reported as failed after it
     * committed is not.
     */
    public function dispatchAfterCommit(BatchContext $context): void
    {
        if (!$this->config->isDispatchProductEvents()) {
            return;
        }

        // Guard per product so a throwing third-party save_commit_after observer
        // neither fails the (already committed) import nor suppresses the
        // remaining products' events; the custom events are guarded separately.
        try {
            foreach ($this->buildProducts($context) as $product) {
                try {
                    $this->eventManager->dispatch('model_save_commit_after', ['object' => $product]);
                    $this->eventManager->dispatch(
                        'catalog_product_save_commit_after',
                        ['data_object' => $product, 'product' => $product]
                    );
                } catch (\Throwable $e) {
                    $this->logger->error(
                        sprintf(
                            'Post-commit product event dispatch failed for "%s": %s',
                            // Not getSku(): that routes through the type instance,
                            // and a second failure inside the error handler would
                            // mask the one being reported.
                            (string)$product->getData('sku'),
                            $e->getMessage()
                        ),
                        ['exception' => $e]
                    );
                }
            }

            try {
                $this->dispatchCustomEvents($context);
            } catch (\Throwable $e) {
                $this->logger->error(
                    sprintf('Custom import event dispatch failed: %s', $e->getMessage()),
                    ['exception' => $e]
                );
            }
        } catch (\Throwable $e) {
            // Everything above has its own handler, so reaching this one means
            // building the batch's product objects failed — no product was
            // dispatched and none can be. The alternative is letting it reach
            // ImportService and turn a committed batch into a reported failure.
            $this->logger->error(
                sprintf('Post-commit event dispatch abandoned for the batch: %s', $e->getMessage()),
                ['exception' => $e]
            );
        } finally {
            // This batch's dispatch is over. ImportService holds every
            // BatchContext until it builds the response, so releasing here is
            // what keeps a long import from accumulating hydrated products.
            $this->builtFor = null;
            $this->builtProducts = [];
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

        $this->dispatchMediaChanged($context);
    }

    /**
     * The gallery on a dispatched product is the product's whole gallery, which
     * tells an integration nothing about what this import touched. This event
     * carries that delta, so an image CDN or optimiser can act on the files that
     * actually changed instead of reprocessing every product it hears about.
     */
    private function dispatchMediaChanged(BatchContext $context): void
    {
        $changes = $context->get(MediaProcessor::CONTEXT_CHANGES, []);
        if (!$changes) {
            return;
        }

        $skuToId = [];
        $created = [];
        $removed = [];
        foreach ($changes as $sku => $change) {
            $skuToId[(string)$sku] = $change['entity_id'];
            $created[] = $change['created'];
            $removed[] = $change['removed'];
        }

        // The flat unions are for consumers working per file rather than per
        // product, so they are deduplicated — several products can share one
        // file on disk. For the same reason a file one product dropped and
        // another kept or gained is withheld from the removal union: it moved,
        // it is not gone, and purging it would break the product that has it.
        // The per-SKU "removed" stays exact — that one is per-product truth.
        $retained = $context->get(MediaProcessor::CONTEXT_RETAINED_FILES, []);

        $this->eventManager->dispatch(self::EVENT_PRODUCT_MEDIA_CHANGED, [
            'store_id' => $context->getStoreId(),
            'changes' => $changes,
            'sku_to_id' => $skuToId,
            'created_files' => array_values(array_unique(array_merge(...$created))),
            'removed_files' => array_values(
                array_diff(array_unique(array_merge(...$removed)), $retained)
            ),
        ]);
    }

    /**
     * One lightweight product model per valid, resolved product. Apart from the
     * media gallery the object is NOT reloaded from the DB — it carries the
     * imported data plus its id/sku/store. Observers needing more can reload by
     * id (the row is persisted).
     *
     * Built once per batch and memoised, so the media read happens once and both
     * `*_save_after` and `*_save_commit_after` receive the same instance for a
     * product — as they do on a real save.
     *
     * @return Product[] keyed by SKU
     */
    private function buildProducts(BatchContext $context): array
    {
        if ($this->builtFor === $context) {
            return $this->builtProducts;
        }

        $linkIds = $context->get(EntityProcessor::CONTEXT_LINK_IDS, []);
        $typeIds = $context->get(EntityProcessor::CONTEXT_TYPE_IDS, []);
        $linkField = $this->productEntity->getLinkField();
        $products = [];
        $productsByLinkId = [];
        $skuByLinkId = [];
        $ambiguous = [];
        $unresolved = [];

        foreach ($context->getValidProducts() as $sku => $dto) {
            $entityId = $context->getEntityId($sku);
            if ($entityId === null) {
                continue;
            }
            // On a link_field-is-entity_id install the two are the same number
            // by definition. Everywhere else an absent link ID is unknown, not
            // guessable: EntityProcessor should have resolved it, and an
            // entity_id used as a row_id can collide with a DIFFERENT product's
            // row, which would hydrate someone else's gallery onto this event.
            // MediaProcessor skips the same condition; so does this.
            $linkId = isset($linkIds[$sku]) ? (int)$linkIds[$sku] : ($linkField === 'entity_id' ? $entityId : null);
            $product = $this->buildProduct(
                $context,
                (string)$sku,
                $dto,
                $entityId,
                $linkField,
                $linkId,
                $typeIds[$sku] ?? ($dto->getTypeId() ?: 'simple')
            );
            $products[$sku] = $product;

            if ($linkId === null) {
                $unresolved[] = (string)$sku;
                continue;
            }
            if (isset($productsByLinkId[$linkId]) || isset($ambiguous[$linkId])) {
                // Two SKUs claiming one link ID means at least one of them is
                // wrong and there is no telling which, so neither is hydrated —
                // including the incumbent, which was added before the clash
                // was visible.
                if (!isset($ambiguous[$linkId])) {
                    $unresolved[] = $skuByLinkId[$linkId];
                }
                $ambiguous[$linkId] = true;
                unset($productsByLinkId[$linkId]);
                $unresolved[] = (string)$sku;
                continue;
            }
            $productsByLinkId[$linkId] = $product;
            $skuByLinkId[$linkId] = (string)$sku;
        }

        if ($this->config->isHydrateEventMedia()) {
            if ($unresolved) {
                // Once per batch, not per product: a systematic failure upstream
                // would otherwise fill the log with one line per SKU.
                $this->logger->error(sprintf(
                    'No usable "%s" for %d product(s), so their dispatched events carry no media: %s',
                    $linkField,
                    count($unresolved),
                    implode(', ', $unresolved)
                ));
            }
            $this->mediaHydrator->hydrate($productsByLinkId, $context->getStoreId());
        }

        // Assigning replaces the previous batch's slot, so retention is capped
        // at one batch even if that batch rolled back before its dispatch.
        $this->builtFor = $context;
        $this->builtProducts = $products;

        return $products;
    }

    private function buildProduct(
        BatchContext $context,
        string $sku,
        ProductInterface $dto,
        int $entityId,
        string $linkField,
        ?int $linkId,
        string $typeId
    ): Product {
        /** @var Product $product */
        $product = $this->productFactory->create();
        $product->setData('entity_id', $entityId);
        $product->setId($entityId);
        // No link ID rather than a fabricated one: an observer reading row_id
        // off this object should get nothing before it gets someone else's row.
        if ($linkField !== 'entity_id' && $linkId !== null) {
            $product->setData($linkField, $linkId);
        }
        $product->setData('sku', $sku);
        $product->setStoreId($context->getStoreId());
        $product->setData('type_id', $typeId);

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
