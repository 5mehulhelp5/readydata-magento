<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Processor;

use ReadyData\Import\Api\Data\ConfigurableDataInterface;
use ReadyData\Import\Model\BatchContext;
use ReadyData\Import\Model\Cache\AttributeMetadataCache;
use ReadyData\Import\Model\ResourceModel\Configurable;
use ReadyData\Import\Model\ResourceModel\ProductEntity;

/**
 * Configurable-product structure. On a "configurable" parent carrying a
 * "configurable" block, declares its variation axes
 * (catalog_product_super_attribute + _label) and links its children
 * (catalog_product_super_link / catalog_product_relation).
 *
 * Super attributes must be global-scope select attributes; children must be
 * existing simple/virtual products. Children are resolved against the DB (one
 * bulk lookup), so a child created earlier in this batch's transaction or in
 * an already-committed earlier batch resolves, but a child scheduled in a
 * LATER batch does not — send children before/with the parent.
 *
 * Safety valve (mirrors CategoryLinkProcessor): when any of a parent's
 * references fails to resolve, that parent is applied additively — inserts
 * happen, removals are withheld — so a typo cannot tear down valid variations.
 *
 * Parents are already part of their batch's affected-ID set (they are valid
 * products resolved by EntityProcessor), so their price index is refreshed by
 * the normal post-import reindex without this processor publishing anything.
 */
class ConfigurableProcessor implements ProcessorInterface
{
    private const CHILD_TYPES = ['simple', 'virtual'];

    public function __construct(
        private readonly Configurable $configurable,
        private readonly AttributeMetadataCache $attributeMetadataCache,
        private readonly ProductEntity $productEntity
    ) {
    }

    public function process(BatchContext $context): void
    {
        $typeIds = $context->get(EntityProcessor::CONTEXT_TYPE_IDS, []);
        $linkIds = $context->get(EntityProcessor::CONTEXT_LINK_IDS, []);

        // 1. Collect configurable parents that carry a configurable block.
        $parents = [];
        $codes = [];
        $childSkus = [];
        foreach ($context->getValidProducts() as $sku => $product) {
            if (($typeIds[$sku] ?? null) !== 'configurable') {
                continue;
            }
            $configurableData = $product->getConfigurable();
            if ($configurableData === null) {
                continue;
            }
            $parents[$sku] = $configurableData;
            foreach ($configurableData->getSuperAttributes() ?? [] as $code) {
                $codes[$code] = true;
            }
            foreach ($configurableData->getChildren() ?? [] as $childSku) {
                $childSkus[$childSku] = true;
            }
        }

        if (!$parents) {
            return;
        }

        // 2. Bulk resolve attribute metadata and child rows.
        $this->attributeMetadataCache->warm(array_keys($codes));
        $children = $this->productEntity->getExistingBySkus(array_keys($childSkus));

        $parentLinkIds = [];
        foreach (array_keys($parents) as $sku) {
            if (isset($linkIds[$sku])) {
                $parentLinkIds[$sku] = (int)$linkIds[$sku];
            }
        }
        $currentSuperAttributes = $this->configurable->getSuperAttributes(array_values($parentLinkIds));
        $currentChildLinks = $this->configurable->getChildLinks(array_values($parentLinkIds));

        $toLink = [];
        $toUnlink = [];

        foreach ($parents as $sku => $configurableData) {
            $parentLinkId = $parentLinkIds[$sku] ?? null;
            $entityId = $context->getEntityId($sku);
            if ($parentLinkId === null || $entityId === null) {
                // EntityProcessor should have resolved these; defensive skip.
                continue;
            }

            // A null (omitted) sub-field leaves that dimension untouched; a
            // present array — including [] — replaces it. Resolving both before
            // any removal lets a failure in either withhold removals in both.
            [$desiredAttributes, $partial] = $this->resolveSuperAttributes($context, $sku, $configurableData);
            [$desiredChildIds, $partial] = $this->resolveChildren(
                $context,
                $sku,
                $configurableData,
                $children,
                $partial
            );

            // --- super attributes (inserts are always safe) ---
            $attributesToRemove = [];
            if ($desiredAttributes !== null) {
                $currentAttributes = $currentSuperAttributes[$parentLinkId] ?? [];
                foreach ($desiredAttributes as $attributeId => $info) {
                    if (!isset($currentAttributes[$attributeId])) {
                        $this->configurable->addSuperAttribute(
                            $parentLinkId,
                            $attributeId,
                            $info['position'],
                            $info['label']
                        );
                    }
                }
                $attributesToRemove = array_values(array_diff_key($currentAttributes, $desiredAttributes));
            }

            // --- children (links are always safe) ---
            $childrenToUnlink = [];
            if ($desiredChildIds !== null) {
                $currentChildren = array_fill_keys($currentChildLinks[$parentLinkId] ?? [], true);
                foreach (array_keys($desiredChildIds) as $childId) {
                    if (!isset($currentChildren[$childId])) {
                        $toLink[] = ['parent_id' => $parentLinkId, 'child_id' => $childId];
                    }
                }
                $childrenToUnlink = array_keys(array_diff_key($currentChildren, $desiredChildIds));
            }

            // --- removals (withheld as a whole when anything failed to resolve) ---
            if (!$attributesToRemove && !$childrenToUnlink) {
                continue;
            }
            if ($partial) {
                $context->addMessage(
                    $sku,
                    'Configurable structure applied additively: some references could not be'
                    . ' resolved, so no existing children or attributes were removed.'
                );
                continue;
            }
            if ($attributesToRemove) {
                $this->configurable->removeSuperAttributes($attributesToRemove);
            }
            foreach ($childrenToUnlink as $childId) {
                $toUnlink[] = ['parent_id' => $parentLinkId, 'child_id' => $childId];
            }
        }

        $this->configurable->linkChildren($toLink);
        $this->configurable->unlinkChildren($toUnlink);
    }

    /**
     * @return array{0: array<int, array{position: int, label: string}>|null, 1: bool}
     *         [attribute_id => {position, label}] (null when the field is omitted), partial flag
     */
    private function resolveSuperAttributes(
        BatchContext $context,
        string $sku,
        ConfigurableDataInterface $configurableData
    ): array {
        $superAttributes = $configurableData->getSuperAttributes();
        if ($superAttributes === null) {
            return [null, false];
        }

        $desired = [];
        $partial = false;
        $position = 0;

        foreach ($superAttributes as $code) {
            $meta = $this->attributeMetadataCache->get($code);
            if ($meta === null) {
                $context->addMessage($sku, sprintf('Unknown super attribute "%s" skipped.', $code));
                $partial = true;
                continue;
            }
            if ($meta['frontend_input'] !== 'select' || $meta['is_global'] !== 1) {
                $context->addMessage(
                    $sku,
                    sprintf('Super attribute "%s" must be a global-scope select attribute; skipped.', $code)
                );
                $partial = true;
                continue;
            }
            $desired[$meta['attribute_id']] = [
                'position' => $position++,
                'label' => $meta['frontend_label'] !== '' ? $meta['frontend_label'] : $code,
            ];
        }

        return [$desired, $partial];
    }

    /**
     * @param array<string, array{entity_id: int, link_id: int, attribute_set_id: int, type_id: string}> $children
     * @return array{0: array<int, bool>|null, 1: bool}
     *         [child entity_id => true] (null when the field is omitted), partial flag
     */
    private function resolveChildren(
        BatchContext $context,
        string $sku,
        ConfigurableDataInterface $configurableData,
        array $children,
        bool $partial
    ): array {
        $childSkus = $configurableData->getChildren();
        if ($childSkus === null) {
            return [null, $partial];
        }

        $desired = [];

        foreach ($childSkus as $childSku) {
            $child = $children[$childSku] ?? null;
            if ($child === null) {
                $context->addMessage($sku, sprintf('Child SKU "%s" not found; skipped.', $childSku));
                $partial = true;
                continue;
            }
            if (!in_array($child['type_id'], self::CHILD_TYPES, true)) {
                $context->addMessage(
                    $sku,
                    sprintf('Child SKU "%s" is not a simple/virtual product; skipped.', $childSku)
                );
                $partial = true;
                continue;
            }
            $desired[$child['entity_id']] = true;
        }

        return [$desired, $partial];
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function getSortOrder(): int
    {
        return 730;
    }
}
