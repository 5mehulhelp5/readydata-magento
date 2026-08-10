<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Processor;

use ReadyData\Import\Api\Data\ProductInterface;
use ReadyData\Import\Api\Data\ProductStoreValuesInterface;
use ReadyData\Import\Model\BatchContext;
use ReadyData\Import\Model\Cache\AttributeMetadataCache;
use ReadyData\Import\Model\Cache\StoreWebsiteMap;
use ReadyData\Import\Model\ResourceModel\AttributeOption;
use ReadyData\Import\Model\ResourceModel\EavValue;
use ReadyData\Import\Model\ResourceModel\ProductEntity;
use ReadyData\Import\Model\UrlKeyGenerator;

/**
 * Writes all scalar EAV attribute values in bulk, grouped by backend type
 * (one upsert per catalog_product_entity_* table per batch).
 *
 * A product is written once per **scope**: the request's own scope (the base
 * pass, carrying the product's first-class fields and custom attributes), plus
 * one pass for each block in its `store_values`. Which store rows a value
 * actually lands in is decided per attribute after that, by
 * {@see resolveScopeStoreIds()} — the scope only says which store view is
 * being addressed, not which rows a website-scoped attribute fans out to.
 *
 * Publishes to the context data bag:
 *  - "url_keys": array<string sku, string> url_key written in this batch
 *    (provided or generated); consumed by UrlRewriteProcessor.
 */
class EavValueProcessor implements ProcessorInterface
{
    public const CONTEXT_URL_KEYS = 'url_keys';

    private const SCOPE_GLOBAL = 1;
    private const SCOPE_WEBSITE = 2;

    public function __construct(
        private readonly AttributeMetadataCache $attributeMetadataCache,
        private readonly AttributeOption $attributeOption,
        private readonly EavValue $eavValue,
        private readonly ProductEntity $productEntity,
        private readonly UrlKeyGenerator $urlKeyGenerator,
        private readonly StoreWebsiteMap $storeWebsiteMap
    ) {
    }

    public function process(BatchContext $context): void
    {
        $linkIds = $context->get(EntityProcessor::CONTEXT_LINK_IDS, []);
        $linkField = $this->productEntity->getLinkField();
        $urlKeys = [];
        $rowsByType = [];
        $deleteKeysByType = [];

        foreach ($context->getValidProducts() as $sku => $product) {
            $linkId = $linkIds[$sku] ?? null;
            if ($linkId === null) {
                $context->fail($sku, 'Missing entity link ID; entity processor did not resolve this product.');
                continue;
            }

            foreach ($this->collectScopes($context, $product) as $storeId => $scope) {
                if ($scope['is_base'] && isset($scope['values']['url_key'])) {
                    $urlKeys[$sku] = (string)$scope['values']['url_key'];
                }

                $this->collectClearKeys($context, $product, $scope, $storeId, $linkId, $deleteKeysByType);
                $this->collectValueRows(
                    $context,
                    (string)$sku,
                    $scope,
                    $storeId,
                    $linkId,
                    $linkField,
                    $rowsByType
                );
            }
        }

        foreach ($rowsByType as $backendType => $rows) {
            $this->eavValue->upsert((string)$backendType, $rows);
        }
        foreach ($deleteKeysByType as $backendType => $keys) {
            $this->eavValue->delete((string)$backendType, $keys);
        }

        $context->set(self::CONTEXT_URL_KEYS, $urlKeys);
    }

    /**
     * The scopes one product is written in, keyed by store ID and ordered with
     * the base pass first — a `store_values` block cannot be written before the
     * product's own values exist to fall back to.
     *
     * A block addressing a scope that is already present merges into it and
     * wins per attribute, which covers both a block naming the request's own
     * scope and two blocks naming the same store view. Merging rather than
     * writing twice keeps the outcome independent of upsert ordering.
     *
     * @return array<int, array{values: array<string, string|int|float>, clear: string[], is_base: bool}>
     */
    private function collectScopes(BatchContext $context, ProductInterface $product): array
    {
        $sku = $product->getSku();
        $scopes = [
            $context->getStoreId() => [
                'values' => $this->collectValues($context, $product),
                'clear' => $this->normalizeClearCodes($product->getClearAttributes()),
                'is_base' => true,
            ],
        ];

        foreach ($product->getStoreValues() ?? [] as $block) {
            $storeId = $this->storeWebsiteMap->findScopeStoreId(
                $block->getStoreId(),
                $block->getStoreViewCode()
            );
            if ($storeId === null) {
                $context->addMessage(
                    $sku,
                    sprintf(
                        'Store values for %s were skipped: no such store view.',
                        $this->describeScope($block)
                    )
                );
                continue;
            }

            $values = $this->collectScopeValues($block);
            $clear = $this->normalizeClearCodes($block->getClearAttributes());

            if (isset($scopes[$storeId])) {
                $context->addMessage(
                    $sku,
                    'This scope was addressed more than once in the payload; the values were merged, the'
                    . ' later block winning per attribute.',
                    $storeId
                );
                $scopes[$storeId]['values'] = array_merge($scopes[$storeId]['values'], $values);
                $scopes[$storeId]['clear'] = array_values(
                    array_unique(array_merge($scopes[$storeId]['clear'], $clear))
                );
                continue;
            }

            $context->registerScope($sku, $storeId);
            $scopes[$storeId] = ['values' => $values, 'clear' => $clear, 'is_base' => false];
        }

        return $scopes;
    }

    /**
     * Turn one scope's values into upsert rows.
     *
     * @param array{values: array<string, string|int|float>, clear: string[], is_base: bool} $scope
     * @param array<string, array<int, array<string, mixed>>> $rowsByType
     */
    private function collectValueRows(
        BatchContext $context,
        string $sku,
        array $scope,
        int $scopeStoreId,
        int $linkId,
        string $linkField,
        array &$rowsByType
    ): void {
        $tag = $this->tag($scope, $scopeStoreId);

        foreach ($scope['values'] as $code => $value) {
            $meta = $this->attributeMetadataCache->get((string)$code);
            if ($meta === null) {
                $context->addMessage($sku, sprintf('Unknown attribute "%s" skipped.', $code), $tag);
                continue;
            }
            if ($meta['backend_type'] === 'static') {
                continue;
            }
            if (!$scope['is_base'] && !$this->isScopable($context, $sku, $scopeStoreId, $meta)) {
                continue;
            }

            $prepared = $this->prepareValue($meta, (string)$value);
            if ($prepared === null) {
                $context->addMessage(
                    $sku,
                    sprintf('Value "%s" for attribute "%s" could not be resolved; skipped.', $value, $code),
                    $tag
                );
                continue;
            }

            $storeIds = $this->resolveScopeStoreIds($meta, $scopeStoreId);
            // New products always need a default-scope fallback row — but only
            // from the base pass. Copying a scoped value down to store 0 would
            // make one store view's text the value every other view inherits.
            if ($scope['is_base'] && !in_array(0, $storeIds, true) && !$context->isExisting($sku)) {
                $storeIds[] = 0;
            }
            foreach ($storeIds as $storeId) {
                $rowsByType[$meta['backend_type']][] = [
                    $linkField => $linkId,
                    'attribute_id' => $meta['attribute_id'],
                    'store_id' => $storeId,
                    'value' => $prepared,
                ];
            }
            if ($tag !== null) {
                $context->markScopeApplied($sku, $tag);
            }
        }
    }

    /**
     * Collect EAV delete keys for one scope's clear_attributes list, guarded so
     * a clear can never corrupt the product: unknown and static attributes are
     * skipped, required attributes cannot be cleared at the default scope
     * (store-scoped clears fall back to the default value), and an attribute
     * both written and cleared in the same scope keeps the written value.
     *
     * @param array{values: array<string, string|int|float>, clear: string[], is_base: bool} $scope
     * @param array<string, array<int, array{link_id: int, attribute_id: int, store_id: int}>> $deleteKeysByType
     */
    private function collectClearKeys(
        BatchContext $context,
        ProductInterface $product,
        array $scope,
        int $scopeStoreId,
        int $linkId,
        array &$deleteKeysByType
    ): void {
        $sku = $product->getSku();
        $tag = $this->tag($scope, $scopeStoreId);

        foreach ($scope['clear'] as $code) {
            $meta = $this->attributeMetadataCache->get($code);
            if ($meta === null) {
                $context->addMessage($sku, sprintf('Unknown attribute "%s" skipped.', $code), $tag);
                continue;
            }
            if ($meta['backend_type'] === 'static') {
                $context->addMessage($sku, sprintf('Attribute "%s" is static and cannot be cleared.', $code), $tag);
                continue;
            }
            if (isset($scope['values'][$code])) {
                $context->addMessage(
                    $sku,
                    sprintf('Attribute "%s" is both written and cleared; the write wins.', $code),
                    $tag
                );
                continue;
            }
            if (!$scope['is_base'] && !$this->isScopable($context, $sku, $scopeStoreId, $meta)) {
                continue;
            }

            $storeIds = $this->resolveScopeStoreIds($meta, $scopeStoreId);
            if (in_array(0, $storeIds, true) && $meta['is_required'] === 1) {
                $context->addMessage(
                    $sku,
                    sprintf('Attribute "%s" is required and cannot be cleared at default scope.', $code),
                    $tag
                );
                continue;
            }
            foreach ($storeIds as $storeId) {
                $deleteKeysByType[$meta['backend_type']][] = [
                    'link_id' => $linkId,
                    'attribute_id' => $meta['attribute_id'],
                    'store_id' => $storeId,
                ];
            }
            if ($tag !== null) {
                $context->markScopeApplied($sku, $tag);
            }
        }
    }

    /**
     * Whether an attribute may be addressed from a `store_values` block at all.
     *
     * Both refusals exist because writing the value would do something other
     * than what the block says, and both are reported rather than silent:
     *
     *  - a **global** attribute has no store dimension, so the value would land
     *    at the default scope — overwriting the product's own default-scope
     *    value from inside a block that named one store view;
     *  - **url_key** would be stored and then not used: the storefront URL
     *    comes from `url_rewrite`, and those rows are still generated from the
     *    default-scope key (UrlRewriteProcessor), so the store view would keep
     *    resolving on the default key while the EAV row said otherwise.
     */
    private function isScopable(BatchContext $context, string $sku, int $scopeStoreId, array $meta): bool
    {
        if ($meta['attribute_code'] === 'url_key') {
            $context->addMessage(
                $sku,
                'Attribute "url_key" cannot be set per store view: the storefront URL is generated from the'
                . ' default-scope key, so a scoped one would be stored and never used. Send it on the product.',
                $scopeStoreId
            );

            return false;
        }
        if ($meta['is_global'] === self::SCOPE_GLOBAL) {
            $context->addMessage(
                $sku,
                sprintf(
                    'Attribute "%s" is global and has no store dimension; the scoped value was skipped rather'
                    . ' than written at the default scope, where it would overwrite the product\'s own value.'
                    . ' Send it on the product.',
                    $meta['attribute_code']
                ),
                $scopeStoreId
            );

            return false;
        }

        return true;
    }

    /**
     * The store ID a message from this scope is filed under. Base-pass messages
     * belong to the product, not to a scope — the base pass is what the request
     * itself asked for, and tagging it would make every existing message read
     * as if it came from a scoped block.
     *
     * @param array{values: array<string, string|int|float>, clear: string[], is_base: bool} $scope
     */
    private function tag(array $scope, int $scopeStoreId): ?int
    {
        return $scope['is_base'] ? null : $scopeStoreId;
    }

    /** How an unresolvable scope block is named back to the caller. */
    private function describeScope(ProductStoreValuesInterface $block): string
    {
        if ($block->getStoreId() !== null) {
            return sprintf('store view ID %d', $block->getStoreId());
        }
        if ((string)$block->getStoreViewCode() !== '') {
            return sprintf('store view "%s"', $block->getStoreViewCode());
        }

        return 'a block naming no store view';
    }

    /**
     * @param string[]|null $codes
     * @return string[]
     */
    private function normalizeClearCodes(?array $codes): array
    {
        return array_values(array_unique(array_filter(array_map('trim', $codes ?? []))));
    }

    /**
     * Store IDs a value (or clear) applies to under the attribute's scope:
     * global => default row only; website => every store view of the addressed
     * store's website (the value tables have no website dimension, so website
     * scope is emulated by fanning out per view, as core does); store => the
     * addressed store view only.
     *
     * @return int[]
     */
    private function resolveScopeStoreIds(array $meta, int $scopeStoreId): array
    {
        if ($meta['is_global'] === self::SCOPE_GLOBAL || $scopeStoreId === 0) {
            return [0];
        }
        if ($meta['is_global'] === self::SCOPE_WEBSITE) {
            return $this->storeWebsiteMap->getWebsiteStoreIds($scopeStoreId);
        }

        return [$scopeStoreId];
    }

    /**
     * The same flattening for one `store_values` block. Deliberately without
     * the url_key generation {@see collectValues()} does: a generated key is
     * the product's identity on the storefront, not a per-store translation,
     * and generating one here would invent a different slug per store view.
     *
     * @return array<string, string|int|float>
     */
    private function collectScopeValues(ProductStoreValuesInterface $block): array
    {
        $values = array_filter(
            [
                'name' => $block->getName(),
                'price' => $block->getPrice(),
                'status' => $block->getStatus(),
                'visibility' => $block->getVisibility(),
                'weight' => $block->getWeight(),
            ],
            static fn ($value): bool => $value !== null
        );

        foreach ($block->getCustomAttributes() ?? [] as $customAttribute) {
            if ($customAttribute->getValue() !== null) {
                $values[$customAttribute->getAttributeCode()] = $customAttribute->getValue();
            }
        }

        return $values;
    }

    /**
     * Flatten first-class fields + custom attributes into code => raw value.
     *
     * @return array<string, string|int|float>
     */
    private function collectValues(BatchContext $context, ProductInterface $product): array
    {
        $values = array_filter(
            [
                'name' => $product->getName(),
                'price' => $product->getPrice(),
                'status' => $product->getStatus(),
                'visibility' => $product->getVisibility(),
                'weight' => $product->getWeight(),
                'url_key' => $product->getUrlKey(),
            ],
            static fn ($value): bool => $value !== null
        );

        foreach ($product->getCustomAttributes() ?? [] as $customAttribute) {
            if ($customAttribute->getValue() !== null) {
                $values[$customAttribute->getAttributeCode()] = $customAttribute->getValue();
            }
        }

        // Generate a url_key for new products that have none.
        if (!isset($values['url_key'])
            && !$context->isExisting($product->getSku())
            && $product->getName() !== null
        ) {
            $values['url_key'] = $this->urlKeyGenerator->generate($product->getName());
        }

        return $values;
    }

    /**
     * Resolve option labels and cast to the backend type.
     * Returns null when the value cannot be resolved (e.g. unknown option).
     */
    private function prepareValue(array $meta, string $value): string|int|float|null
    {
        if (!in_array($meta['attribute_code'], ['status', 'visibility'], true)) {
            if ($meta['frontend_input'] === 'select') {
                return $this->attributeOption->getOptionId($meta['attribute_id'], $value);
            }
            if ($meta['frontend_input'] === 'multiselect') {
                $optionIds = [];
                foreach (array_filter(array_map('trim', explode(',', $value))) as $label) {
                    $optionId = $this->attributeOption->getOptionId($meta['attribute_id'], $label);
                    if ($optionId === null) {
                        return null;
                    }
                    $optionIds[] = $optionId;
                }

                return implode(',', $optionIds);
            }
        }

        return match ($meta['backend_type']) {
            'int' => match (mb_strtolower($value)) {
                'true', 'yes' => 1,
                'false', 'no' => 0,
                default => (int)$value,
            },
            'decimal' => is_numeric($value) ? (float)$value : null,
            'datetime' => $this->normalizeDatetime($value),
            default => $value,
        };
    }

    /**
     * Normalize any parseable date string to the MySQL DATETIME format in UTC.
     * Offset-less values are taken as already-UTC (never the server timezone,
     * which would shift them). Returns null for unparseable input; an empty
     * string must not fall through to the parser, which would read it as "now".
     */
    private function normalizeDatetime(string $value): ?string
    {
        if (trim($value) === '') {
            return null;
        }

        $utc = new \DateTimeZone('UTC');
        try {
            return (new \DateTimeImmutable($value, $utc))
                ->setTimezone($utc)
                ->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function getSortOrder(): int
    {
        return 300;
    }
}
