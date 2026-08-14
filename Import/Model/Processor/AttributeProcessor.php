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
use ReadyData\Import\Model\ImportLocks;
use ReadyData\Import\Model\ResourceModel\AttributeOption;

/**
 * Warms attribute metadata for every attribute code seen in the batch and
 * ensures select/multiselect options exist (auto-created when configured).
 *
 * Runs first so later processors never touch attribute metadata cold.
 */
class AttributeProcessor implements ProcessorInterface, LockAwareInterface
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

    /**
     * The option lock, and only when a label in this batch does not exist yet.
     *
     * The old test was "any custom attribute at all, with auto-creation on",
     * which is true of nearly every product in a real feed and was false about
     * nearly all of them: a feed sends `color: Red` on every push, and `Red` was
     * created the first time. Resolving what is already there is not a race —
     * only the insert is — so a batch that creates no option takes no lock and
     * runs concurrently with everything else.
     *
     * Costs one indexed read per option-bearing attribute, which
     * {@see process()} would make anyway; it warms the same memo.
     */
    public function requiredLocks(BatchContext $context): array
    {
        if (!$this->config->isCreateMissingOptions()) {
            // Nothing is ever created, so there is nothing to serialize on.
            return [];
        }

        foreach ($this->optionTargets($this->harvestLabels($context)) as $attributeId => $labels) {
            foreach ($labels as $label) {
                if ($this->attributeOption->getOptionId($attributeId, $label) === null) {
                    return [ImportLocks::ATTRIBUTE_OPTIONS];
                }
            }
        }

        return [];
    }

    public function process(BatchContext $context): void
    {
        $codes = self::CORE_ATTRIBUTE_CODES;
        foreach ($context->getValidProducts() as $product) {
            foreach ($product->getCustomAttributes() ?? [] as $customAttribute) {
                // Every code, INCLUDING the ones carrying an empty value: the
                // rest of the pipeline needs the metadata to write or refuse
                // them, and only the option lock cares about the values.
                $codes[] = $customAttribute->getAttributeCode();
            }
        }

        $this->attributeMetadataCache->warm(array_unique($codes));
        $this->ensureOptions($context, $this->harvestLabels($context));
    }

    /**
     * Option labels are harvested from the product's own custom attributes
     * ONLY. A `store_values` block's custom attributes are resolved against
     * existing options by EavValueProcessor and never create one, which is why
     * {@see requiredLocks()} does not consult them either — extend both together
     * if that ever changes.
     *
     * @return array<string, string[]> attribute code => raw values
     */
    private function harvestLabels(BatchContext $context): array
    {
        $labelsByAttributeCode = [];
        foreach ($context->getValidProducts() as $product) {
            foreach ($product->getCustomAttributes() ?? [] as $customAttribute) {
                $value = $customAttribute->getValue();
                if ($value !== null && $value !== '') {
                    $labelsByAttributeCode[$customAttribute->getAttributeCode()][] = $value;
                }
            }
        }

        return $labelsByAttributeCode;
    }

    /**
     * Narrow the harvest to attributes whose options actually live in
     * `eav_attribute_option`, with their labels in the form the option table
     * holds them — multiselect values split, everything trimmed.
     *
     * Shared by the lock predicate and the create so the two cannot disagree
     * about what a label is: a predicate that trimmed differently would answer
     * "nothing to create" for a label the create then creates, unlocked.
     *
     * @param array<string, string[]> $labelsByAttributeCode
     * @return array<int, string[]> attribute_id => labels
     */
    private function optionTargets(array $labelsByAttributeCode): array
    {
        if (!$labelsByAttributeCode) {
            return [];
        }

        $this->attributeMetadataCache->warm(array_keys($labelsByAttributeCode));

        $targets = [];
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
            $labels = array_values(array_filter(array_map('trim', $labels)));
            if ($labels) {
                $targets[(int)$meta['attribute_id']] = $labels;
            }
        }

        return $targets;
    }

    /**
     * @param array<string, string[]> $labelsByAttributeCode
     */
    private function ensureOptions(BatchContext $context, array $labelsByAttributeCode): void
    {
        $targets = $this->optionTargets($labelsByAttributeCode);
        // Warmed even when nothing is created: EavValueProcessor resolves every
        // label through this memo, including the ones that already existed.
        $this->attributeOption->warm(array_keys($targets));

        if (!$this->config->isCreateMissingOptions() || !$context->holdsLock(ImportLocks::ATTRIBUTE_OPTIONS)) {
            // Either creation is off, or the predicate found every label present
            // and this batch reserved nothing. A label missing now was deleted
            // after that read; creating it here would be the unguarded
            // read-then-create the lock exists to prevent. EavValueProcessor
            // reports it as an unknown option, and the retry — whose probe sees
            // the gap — takes the lock and creates it.
            return;
        }

        $created = [];
        $codeByAttributeId = [];
        foreach ($labelsByAttributeCode as $code => $labels) {
            $meta = $this->attributeMetadataCache->get($code);
            if ($meta !== null) {
                $codeByAttributeId[(int)$meta['attribute_id']] = $code;
            }
        }

        foreach ($targets as $attributeId => $labels) {
            $newOptions = $this->attributeOption->createOptions($attributeId, $labels);
            if ($newOptions) {
                $created[$codeByAttributeId[$attributeId]] = $newOptions;
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
