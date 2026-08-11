<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Processor;

use Magento\Framework\Stdlib\DateTime\DateTime;
use ReadyData\Import\Model\BatchContext;
use ReadyData\Import\Model\Cache\AttributeMetadataCache;
use ReadyData\Import\Model\ResourceModel\ProductEntity;

/**
 * Creates/updates catalog_product_entity rows and resolves SKU => ID maps
 * for the rest of the pipeline.
 *
 * Publishes to the context data bag:
 *  - "link_ids": array<string sku, int> value of the EAV link field
 *    (entity_id on CE, row_id on EE).
 *  - "type_ids": array<string sku, string> resolved product type per SKU
 *    (payload value or, for existing products, the stored type).
 */
class EntityProcessor implements ProcessorInterface
{
    public const CONTEXT_LINK_IDS = 'link_ids';
    public const CONTEXT_TYPE_IDS = 'type_ids';

    private const ALLOWED_TYPES = ['simple', 'virtual', 'downloadable', 'configurable', 'grouped', 'bundle'];
    private const DEFAULT_TYPE = 'simple';

    /**
     * Types that require an option selection before purchase; they must carry
     * has_options/required_options = 1 or the storefront treats them as
     * directly purchasable (a configurable would add to cart with no variant).
     */
    private const OPTION_TYPES = ['configurable', 'bundle'];

    public function __construct(
        private readonly ProductEntity $productEntity,
        private readonly AttributeMetadataCache $attributeMetadataCache,
        private readonly DateTime $dateTime
    ) {
    }

    public function process(BatchContext $context): void
    {
        $existing = $this->productEntity->getExistingBySkus($context->getSkus());
        foreach (array_keys($existing) as $sku) {
            $context->markExisting((string)$sku);
        }

        $now = $this->dateTime->gmtDate();
        $rows = [];
        $typeIds = [];

        foreach ($context->getValidProducts() as $sku => $product) {
            $isNew = !isset($existing[$sku]);

            if ($isNew && $this->productEntity->isStagingEnvironment()) {
                // Creating rows in a staged (EE) catalog needs sequence + created_in/updated_in
                // handling — planned expansion; updates are safe because rows already exist.
                $context->fail($sku, 'Creating new products is not yet supported on staged (EE) catalogs.');
                continue;
            }

            $typeId = $product->getTypeId() ?: ($existing[$sku]['type_id'] ?? self::DEFAULT_TYPE);
            if (!in_array($typeId, self::ALLOWED_TYPES, true)) {
                $context->fail($sku, sprintf('Unknown product type "%s".', $typeId));
                continue;
            }

            $attributeSetId = $product->getAttributeSet() !== null || $isNew
                ? $this->attributeMetadataCache->resolveAttributeSetId($product->getAttributeSet())
                : $existing[$sku]['attribute_set_id'];
            if ($attributeSetId === null) {
                $context->fail($sku, sprintf('Unknown attribute set "%s".', $product->getAttributeSet()));
                continue;
            }

            if ($isNew && $product->getName() === null) {
                $context->fail($sku, 'New products require a name.');
                continue;
            }

            $requiresOptions = in_array($typeId, self::OPTION_TYPES, true) ? 1 : 0;
            $row = [
                'sku' => $sku,
                'attribute_set_id' => $attributeSetId,
                'type_id' => $typeId,
                'has_options' => $requiresOptions,
                'required_options' => $requiresOptions,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (!$isNew) {
                // sku is not a unique index; existing rows must conflict on the PK.
                $row[$this->productEntity->getLinkField()] = $existing[$sku]['link_id'];
            }
            $rows[] = $row;
            $typeIds[$sku] = $typeId;
        }

        $this->productEntity->upsert($rows);

        // Re-select to pick up freshly generated IDs.
        $linkIds = [];
        $resolved = $this->productEntity->getExistingBySkus(array_keys($context->getValidProducts()));
        foreach ($context->getValidProducts() as $sku => $product) {
            if (!isset($resolved[$sku])) {
                $context->fail($sku, 'Product row could not be resolved after insert.');
                continue;
            }
            $context->setEntityId($sku, $resolved[$sku]['entity_id']);
            $linkIds[$sku] = $resolved[$sku]['link_id'];
        }

        $context->set(self::CONTEXT_LINK_IDS, $linkIds);
        $context->set(self::CONTEXT_TYPE_IDS, $typeIds);
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function getSortOrder(): int
    {
        return 200;
    }
}
