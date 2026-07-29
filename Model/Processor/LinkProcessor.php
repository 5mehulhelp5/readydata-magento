<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Processor;

use ReadyData\Import\Api\Data\ProductLinksInterface;
use ReadyData\Import\Model\BatchContext;
use ReadyData\Import\Model\ResourceModel\ProductEntity;
use ReadyData\Import\Model\ResourceModel\ProductLink;

/**
 * Related / up-sell / cross-sell links. On a product carrying a "links" block,
 * writes catalog_product_link plus the display order in
 * catalog_product_link_attribute_int.
 *
 * Semantics are REPLACE per sub-field: a present "related", "up_sell" or
 * "cross_sell" array (including []) makes that link type become exactly the
 * resolved set, in the given order, while an omitted sub-field leaves that link
 * type untouched. Because the payload owns the whole set for a link type it
 * also owns the order — admin-set positions on link types the feed sends are
 * overwritten; omitted link types keep theirs.
 *
 * Targets are resolved against the DB (one bulk lookup), so a target created
 * earlier in this batch's transaction or in an already-committed earlier batch
 * resolves, but one scheduled in a LATER batch does not — send targets
 * before/with the linking product. Any product type may be a target.
 *
 * Safety valve (mirrors CategoryLinkProcessor): when one of a product's targets
 * fails to resolve, that product is applied additively — inserts happen,
 * removals are withheld — so a typo cannot tear down valid merchandising. The
 * valve is scoped per link type: the three sets are independent, so a bad SKU
 * in "related" does not freeze "cross_sell". A self-link is skipped with a
 * warning but does NOT trip the valve, otherwise a feed that always echoes the
 * linking SKU into its own related list could never remove anything.
 *
 * Linking products are already part of their batch's affected-ID set (they are
 * valid products resolved by EntityProcessor), so their FPC tags are cleaned by
 * the normal post-import invalidation without this processor publishing
 * anything. Link types 1/4/5 feed no catalog indexer, and links are
 * directional, so targets need no invalidation of their own.
 */
class LinkProcessor implements ProcessorInterface
{
    /**
     * Link types this processor owns, with their label for warnings. Grouped
     * (link type 3) is a product-type feature and is deliberately excluded.
     */
    private const TYPES = [
        ProductLink::TYPE_RELATED => 'Related',
        ProductLink::TYPE_UP_SELL => 'Up-sell',
        ProductLink::TYPE_CROSS_SELL => 'Cross-sell',
    ];

    public function __construct(
        private readonly ProductLink $productLink,
        private readonly ProductEntity $productEntity
    ) {
    }

    public function process(BatchContext $context): void
    {
        $linkIds = $context->get(EntityProcessor::CONTEXT_LINK_IDS, []);

        // 1. Collect products carrying at least one link sub-field.
        $sources = [];
        $targetSkus = [];
        foreach ($context->getValidProducts() as $sku => $product) {
            $links = $product->getLinks();
            if ($links === null) {
                continue;
            }

            $byType = [];
            $carriesAny = false;
            foreach (array_keys(self::TYPES) as $typeId) {
                $refs = $this->targetsFor($links, $typeId);
                $byType[$typeId] = $refs;
                $carriesAny = $carriesAny || $refs !== null;
            }
            if (!$carriesAny) {
                // "links": {} — nothing declared, so nothing to read or write.
                continue;
            }

            $sources[$sku] = $byType;
            foreach ($byType as $refs) {
                foreach ($refs ?? [] as $ref) {
                    $targetSku = trim((string)$ref);
                    if ($targetSku !== '') {
                        $targetSkus[$targetSku] = true;
                    }
                }
            }
        }

        if (!$sources) {
            return;
        }

        // 2. Bulk resolve targets and the linking products' current links.
        $targets = $this->productEntity->getExistingBySkus(array_keys($targetSkus));

        $sourceIds = [];
        foreach (array_keys($sources) as $sku) {
            if (!isset($linkIds[$sku]) || $context->getEntityId($sku) === null) {
                // EntityProcessor should have resolved these; defensive skip.
                unset($sources[$sku]);
                continue;
            }
            $sourceIds[$sku] = (int)$linkIds[$sku];
        }

        if (!$sourceIds) {
            return;
        }

        $current = $this->productLink->getLinks(array_values($sourceIds), array_keys(self::TYPES));

        $toInsert = [];
        $toDelete = [];
        $positions = [];
        $hasInserts = false;

        // 3. Diff each product, one link type at a time.
        foreach ($sources as $sku => $byType) {
            $sourceLinkId = $sourceIds[$sku];
            $selfEntityId = $context->getEntityId($sku);

            foreach ($byType as $typeId => $refs) {
                if ($refs === null) {
                    // Omitted sub-field — leave this link type untouched.
                    continue;
                }

                [$desired, $partial] = $this->resolveTargets(
                    $context,
                    (string)$sku,
                    $refs,
                    $targets,
                    $selfEntityId
                );
                $currentLinks = $current[$sourceLinkId][$typeId] ?? [];

                foreach ($desired as $targetId => $position) {
                    if (!isset($currentLinks[$targetId])) {
                        $toInsert[] = [
                            'link_type_id' => $typeId,
                            'product_id' => $sourceLinkId,
                            'linked_product_id' => $targetId,
                        ];
                        $hasInserts = true;
                        $positions[] = [$sourceLinkId, $typeId, $targetId, $position];
                    } elseif ($currentLinks[$targetId]['position'] !== $position) {
                        $positions[] = [$sourceLinkId, $typeId, $targetId, $position];
                    }
                }

                // Removals are withheld when anything in THIS link type failed
                // to resolve; the other link types still apply in full.
                $removals = array_diff_key($currentLinks, $desired);
                if (!$removals) {
                    continue;
                }
                if ($partial) {
                    $context->addMessage(
                        $sku,
                        sprintf(
                            '%s links applied additively: some SKUs could not be resolved,'
                            . ' so no existing links of this type were removed.',
                            self::TYPES[$typeId]
                        )
                    );
                    continue;
                }
                foreach (array_keys($removals) as $targetId) {
                    $toDelete[] = [
                        'link_type_id' => $typeId,
                        'product_id' => $sourceLinkId,
                        'linked_product_id' => $targetId,
                    ];
                }
            }
        }

        // 4. Bulk writes.
        $this->productLink->deleteLinks($toDelete);
        $this->productLink->insertLinks($toInsert);
        $this->writePositions($positions, $current, $hasInserts, array_values($sourceIds));
    }

    /**
     * @return string[]|null null when the sub-field is omitted
     */
    private function targetsFor(ProductLinksInterface $links, int $typeId): ?array
    {
        return match ($typeId) {
            ProductLink::TYPE_RELATED => $links->getRelated(),
            ProductLink::TYPE_UP_SELL => $links->getUpSell(),
            ProductLink::TYPE_CROSS_SELL => $links->getCrossSell(),
            default => null,
        };
    }

    /**
     * @param string[] $refs
     * @param array<string, array{entity_id: int, link_id: int, attribute_set_id: int, type_id: string}> $targets
     * @return array{0: array<int, int>, 1: bool} [target entity_id => position], partial flag
     */
    private function resolveTargets(
        BatchContext $context,
        string $sku,
        array $refs,
        array $targets,
        ?int $selfEntityId
    ): array {
        $desired = [];
        $partial = false;
        $position = 0;

        foreach ($refs as $ref) {
            $targetSku = trim((string)$ref);
            if ($targetSku === '') {
                $context->addMessage($sku, 'Empty linked SKU skipped.');
                $partial = true;
                continue;
            }
            $target = $targets[$targetSku] ?? null;
            if ($target === null) {
                $context->addMessage($sku, sprintf('Linked SKU "%s" not found; skipped.', $targetSku));
                $partial = true;
                continue;
            }
            if ($target['entity_id'] === $selfEntityId) {
                // Compared by ID, not by string: a differently-spelled SKU can
                // resolve to the same row. Deliberately not $partial.
                $context->addMessage(
                    $sku,
                    sprintf('Linked SKU "%s" refers to the product itself; skipped.', $targetSku)
                );
                continue;
            }
            if (isset($desired[$target['entity_id']])) {
                // Duplicate reference — the first occurrence keeps its position.
                continue;
            }
            $desired[$target['entity_id']] = $position++;
        }

        return [$desired, $partial];
    }

    /**
     * @param array<int, array{0: int, 1: int, 2: int, 3: int}> $positions
     *        [source link id, link type id, target entity_id, position]
     * @param array<int, array<int, array<int, array{link_id: int, position: int|null}>>> $current
     * @param int[] $sourceLinkIds
     */
    private function writePositions(array $positions, array $current, bool $hasInserts, array $sourceLinkIds): void
    {
        if (!$positions) {
            return;
        }

        if ($hasInserts) {
            // link_id is auto-increment, so freshly inserted links only have one
            // after the insert. Re-select to pick them up — the same connection
            // sees its own uncommitted rows inside the batch transaction.
            $current = $this->productLink->getLinks($sourceLinkIds, array_keys(self::TYPES));
        }

        $attributeIds = $this->productLink->getPositionAttributeIds();

        $rows = [];
        foreach ($positions as [$sourceLinkId, $typeId, $targetId, $value]) {
            $attributeId = $attributeIds[$typeId] ?? null;
            $linkId = $current[$sourceLinkId][$typeId][$targetId]['link_id'] ?? null;
            if ($attributeId === null || $linkId === null) {
                // No position attribute for this link type, or the link is
                // unexpectedly absent — never write a bogus link_id, the FK
                // would abort the whole batch.
                continue;
            }
            $rows[] = [
                'product_link_attribute_id' => $attributeId,
                'link_id' => $linkId,
                'value' => $value,
            ];
        }

        $this->productLink->savePositions($rows);
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function getSortOrder(): int
    {
        return 720;
    }
}
