<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Processor;

use ReadyData\Import\Api\Data\ProductInterface;
use ReadyData\Import\Model\BatchContext;
use ReadyData\Import\Model\Cache\AttributeMetadataCache;
use ReadyData\Import\Model\Config;
use ReadyData\Import\Model\ResourceModel\AttributeOption;

/**
 * Warms attribute metadata for every attribute code seen in the batch and
 * ensures select/multiselect options exist (auto-created when configured).
 *
 * Runs first so later processors never touch attribute metadata cold.
 */
class AttributeProcessor implements ProcessorInterface
{
    /**
     * Attribute codes behind the first-class ProductValuesInterface fields,
     * spelled from the interface constants so this list and the payload cannot
     * name different things.
     */
    public const CORE_ATTRIBUTE_CODES = [
        ProductInterface::NAME,
        ProductInterface::PRICE,
        ProductInterface::STATUS,
        ProductInterface::VISIBILITY,
        ProductInterface::WEIGHT,
        ProductInterface::URL_KEY,
    ];

    /**
     * Selects whose options come from a static PHP source, not from
     * eav_attribute_option: their values travel as the ints core defines, so
     * there is no label to resolve and nothing to auto-create.
     */
    public const STATIC_SOURCE_SELECT_CODES = [ProductInterface::STATUS, ProductInterface::VISIBILITY];

    /**
     * Context data bag key: options auto-created this batch, as
     * attribute_code => [lowercased label => option_id].
     */
    public const CONTEXT_CREATED_OPTIONS = 'created_options';

    public function __construct(
        private readonly AttributeMetadataCache $attributeMetadataCache,
        private readonly AttributeOption $attributeOption,
        private readonly Config $config
    ) {
    }

    public function process(BatchContext $context): void
    {
        $labelsByAttributeCode = [];
        $codes = self::CORE_ATTRIBUTE_CODES;

        // Option labels are harvested from the product's own custom attributes
        // ONLY. A `store_values` block's custom attributes are resolved against
        // existing options by EavValueProcessor and never create one, which is
        // why ImportService::batchLocks() does not consult them — extend both
        // together if that ever changes.
        foreach ($context->getValidProducts() as $product) {
            foreach ($product->getCustomAttributes() ?? [] as $customAttribute) {
                $code = $customAttribute->getAttributeCode();
                $codes[] = $code;
                if ($customAttribute->getValue() !== null && $customAttribute->getValue() !== '') {
                    $labelsByAttributeCode[$code][] = $customAttribute->getValue();
                }
            }
        }

        $this->attributeMetadataCache->warm(array_unique($codes));
        $this->ensureOptions($context, $labelsByAttributeCode);
    }

    /**
     * @param array<string, string[]> $labelsByAttributeCode
     */
    private function ensureOptions(BatchContext $context, array $labelsByAttributeCode): void
    {
        $createMissing = $this->config->isCreateMissingOptions();
        $created = [];

        foreach ($labelsByAttributeCode as $code => $labels) {
            $meta = $this->attributeMetadataCache->get($code);
            if ($meta === null || !in_array($meta['frontend_input'], ['select', 'multiselect'], true)) {
                continue;
            }
            if ($meta['backend_type'] === 'static'
                || in_array($code, self::STATIC_SOURCE_SELECT_CODES, true)
            ) {
                continue;
            }

            $labels = $meta['frontend_input'] === 'multiselect'
                ? array_merge(...array_map(static fn (string $v): array => explode(',', $v), $labels))
                : $labels;
            $labels = array_filter(array_map('trim', $labels));

            $this->attributeOption->warm([$meta['attribute_id']]);
            if ($createMissing) {
                $newOptions = $this->attributeOption->createOptions($meta['attribute_id'], $labels);
                if ($newOptions) {
                    $created[$code] = $newOptions;
                }
            }
        }

        if ($created) {
            $context->set(self::CONTEXT_CREATED_OPTIONS, $created);
        }
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function getSortOrder(): int
    {
        return 100;
    }
}
