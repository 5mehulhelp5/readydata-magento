<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Processor;

use ReadyData\Import\Api\Data\TierPriceInterface;
use ReadyData\Import\Logger\Logger;
use ReadyData\Import\Model\BatchContext;
use ReadyData\Import\Model\Cache\AttributeMetadataCache;
use ReadyData\Import\Model\Cache\CustomerGroupMap;
use ReadyData\Import\Model\Cache\StoreWebsiteMap;
use ReadyData\Import\Model\ResourceModel\TierPrice;

/**
 * Tier (group) prices: rows in catalog_product_entity_tier_price for a product
 * carrying a "tier_prices" block.
 *
 * Semantics are REPLACE: a present array (including []) makes the product's tier
 * prices become exactly the resolved set, while null/omitted leaves them
 * untouched. Rows are identified by the (website, all-groups, customer group,
 * quantity) tuple — the DB's own unique key — so a re-import matches its stored
 * rows, keeps their value_id, and writes nothing at all when nothing changed.
 *
 * Unlike LinkProcessor the safety valve is per PRODUCT, not per sub-dimension:
 * a product's tier prices are one set whose dimensions are discovered from the
 * data, with no independent named sub-field to isolate. So when any entry fails
 * to resolve or validate, that product is applied additively — inserts and price
 * updates happen, removals are withheld — so a typo cannot tear down valid
 * pricing. One deliberate exception: a duplicate tuple within one product does
 * NOT trip the valve, because one of the two conflicting rows IS written and the
 * set is therefore complete. That is the same reasoning MediaProcessor commits
 * to for duplicate files, and LinkProcessor for self-links.
 *
 * Tier prices have no store dimension, so store_view_code does not affect them;
 * the website dimension lives in the row itself and is governed by Catalog Price
 * Scope. Nothing is published to the context data bag: the affected products are
 * already in their batch's affected-ID set, so the normal post-import
 * invalidation refreshes catalog_product_price and their FPC tags.
 */
class TierPriceProcessor implements ProcessorInterface
{
    public const TIER_PRICE_CODE = 'tier_price';

    /**
     * Bundle prices are computed from their selections, so a bundle's tier price
     * can only be a percentage — core's own validator, admin form and price
     * model all refuse an absolute one.
     */
    private const PERCENTAGE_ONLY_TYPES = ['bundle'];

    public function __construct(
        private readonly TierPrice $tierPrice,
        private readonly CustomerGroupMap $customerGroupMap,
        private readonly StoreWebsiteMap $storeWebsiteMap,
        private readonly AttributeMetadataCache $attributeMetadataCache,
        private readonly Logger $logger
    ) {
    }

    public function process(BatchContext $context): void
    {
        $linkIds = $context->get(EntityProcessor::CONTEXT_LINK_IDS, []);
        $typeIds = $context->get(EntityProcessor::CONTEXT_TYPE_IDS, []);

        // 1. Collect the products carrying a tier_prices block, with their link IDs.
        $sources = [];
        foreach ($context->getValidProducts() as $sku => $product) {
            $entries = $product->getTierPrices();
            if ($entries === null) {
                continue;
            }
            if (!isset($linkIds[$sku]) || $context->getEntityId($sku) === null) {
                // EntityProcessor should have resolved these; defensive skip.
                continue;
            }
            $sources[$sku] = [
                'link_id' => (int)$linkIds[$sku],
                'entries' => $entries,
                'type_id' => (string)($typeIds[$sku] ?? ($product->getTypeId() ?: 'simple')),
            ];
        }

        if (!$sources) {
            return;
        }

        // 2. The tier_price attribute decides which product types may carry one.
        $this->attributeMetadataCache->warm([self::TIER_PRICE_CODE]);
        $meta = $this->attributeMetadataCache->get(self::TIER_PRICE_CODE);
        if ($meta === null) {
            foreach (array_keys($sources) as $sku) {
                $context->addMessage($sku, 'The tier_price attribute is missing; tier prices were not imported.');
            }
            $this->logger->error('The tier_price product attribute does not exist; tier price import skipped.');

            return;
        }
        $applicableTypes = $this->parseApplyTo($meta['apply_to'] ?? '');

        // A product type the attribute does not apply to is skipped WHOLE: core
        // discards the attribute for such types and the admin never shows the
        // field, so existing rows there are merchant history or an old
        // mis-configuration — leaving them inert beats destroying them silently.
        foreach ($sources as $sku => $source) {
            if ($applicableTypes !== [] && !in_array($source['type_id'], $applicableTypes, true)) {
                $context->addMessage(
                    $sku,
                    sprintf(
                        'Tier prices do not apply to "%s" products and were skipped;'
                        . ' existing tier prices were left unchanged.',
                        $source['type_id']
                    )
                );
                unset($sources[$sku]);
            }
        }

        if (!$sources) {
            return;
        }

        // 3. One config read and one bulk read for the whole batch.
        $isPriceScopeGlobal = $this->tierPrice->isPriceScopeGlobal();
        $current = $this->tierPrice->getPrices(array_column($sources, 'link_id'));

        $toSave = [];
        $toDelete = [];

        // 4. Diff each product's desired set against its stored rows.
        foreach ($sources as $sku => $source) {
            $linkId = $source['link_id'];
            [$desired, $partial] = $this->buildDesired(
                $context,
                (string)$sku,
                $source['entries'],
                $source['type_id'],
                $isPriceScopeGlobal
            );
            $currentRows = $current[$linkId] ?? [];

            foreach ($desired as $key => $row) {
                $stored = $currentRows[$key] ?? null;
                if ($stored !== null
                    && $stored['value'] === $row['value']
                    && $stored['percentage_value'] === $row['percentage_value']
                ) {
                    // Identical down to the stored decimal scale — writing it
                    // would be pure churn.
                    continue;
                }
                $toSave[] = ['link_id' => $linkId] + $row;
            }

            $removals = array_diff_key($currentRows, $desired);
            if (!$removals) {
                continue;
            }
            if ($partial) {
                $context->addMessage(
                    $sku,
                    'Tier prices applied additively: some entries could not be applied,'
                    . ' so no existing tier prices were removed.'
                );
                continue;
            }
            foreach ($removals as $stale) {
                $toDelete[] = $stale['value_id'];
            }
        }

        // 5. Bulk writes. Removals first, so a payload that moves a price
        // between quantity breaks never transiently collides on the unique key.
        $this->tierPrice->deletePrices($toDelete);
        $this->tierPrice->savePrices($toSave);
    }

    /**
     * Validate and resolve one product's entries into rows keyed by their
     * identity tuple.
     *
     * @param TierPriceInterface[] $entries
     * @return array{0: array<string, array{all_groups: int, customer_group_id: int, qty: string,
     *      value: string, website_id: int, percentage_value: string|null}>, 1: bool}
     *      [desired rows, partial flag]
     */
    private function buildDesired(
        BatchContext $context,
        string $sku,
        array $entries,
        string $typeId,
        bool $isPriceScopeGlobal
    ): array {
        $desired = [];
        $partial = false;

        foreach ($entries as $entry) {
            $group = $this->resolveGroup($context, $sku, $entry);
            if ($group === null) {
                $partial = true;
                continue;
            }

            $websiteId = $this->resolveWebsiteId($context, $sku, $entry, $isPriceScopeGlobal);
            if ($websiteId === null) {
                $partial = true;
                continue;
            }

            $qty = $entry->getQty();
            if ($qty <= 0) {
                $context->addMessage(
                    $sku,
                    sprintf('Tier price quantity "%s" must be greater than zero; entry skipped.', $qty)
                );
                $partial = true;
                continue;
            }

            $amount = $this->resolveAmount($context, $sku, $entry, $typeId);
            if ($amount === null) {
                $partial = true;
                continue;
            }

            $scaledQty = TierPrice::scaleQty($qty);
            $key = TierPrice::buildKey(
                $websiteId,
                $group['all_groups'],
                $group['customer_group_id'],
                $scaledQty
            );
            if (isset($desired[$key])) {
                // One of the two rows is written, so the set stays complete and
                // the valve deliberately does not trip.
                $context->addMessage(
                    $sku,
                    sprintf(
                        'Duplicate tier price for customer group "%s", quantity %s and website %s;'
                        . ' the first entry was kept.',
                        $entry->getCustomerGroup(),
                        $scaledQty,
                        $websiteId === TierPrice::ALL_WEBSITES ? 'All Websites' : $websiteId
                    )
                );
                continue;
            }

            $desired[$key] = [
                'all_groups' => $group['all_groups'],
                'customer_group_id' => $group['customer_group_id'],
                'qty' => $scaledQty,
                'value' => $amount['value'],
                'website_id' => $websiteId,
                'percentage_value' => $amount['percentage_value'],
            ];
        }

        return [$desired, $partial];
    }

    /**
     * @return array{all_groups: int, customer_group_id: int}|null null when unresolvable
     */
    private function resolveGroup(BatchContext $context, string $sku, TierPriceInterface $entry): ?array
    {
        $reference = trim($entry->getCustomerGroup());
        if ($reference === '') {
            $context->addMessage($sku, 'Tier price entry without a customer group skipped.');

            return null;
        }

        if ($this->isAllSentinel($reference, TierPriceInterface::ALL_GROUPS)) {
            // "Every group" is the all_groups flag with customer_group_id 0 —
            // never Magento's in-memory CUST_GROUP_ALL (32000), which has no
            // customer_group row and would violate the foreign key.
            return ['all_groups' => TierPrice::ALL_GROUPS, 'customer_group_id' => 0];
        }

        $groupId = $this->customerGroupMap->getGroupId($reference);
        if ($groupId === null) {
            $context->addMessage($sku, sprintf('Unknown customer group "%s"; tier price skipped.', $reference));

            return null;
        }

        return ['all_groups' => TierPrice::SPECIFIC_GROUP, 'customer_group_id' => $groupId];
    }

    private function resolveWebsiteId(
        BatchContext $context,
        string $sku,
        TierPriceInterface $entry,
        bool $isPriceScopeGlobal
    ): ?int {
        $reference = trim((string)$entry->getWebsite());
        if ($reference === '' || $this->isAllSentinel($reference, TierPriceInterface::ALL_WEBSITES)) {
            return TierPrice::ALL_WEBSITES;
        }

        if ($isPriceScopeGlobal) {
            // Deliberately not widened to All Websites: quietly applying a
            // website's price everywhere is a pricing error, not a
            // normalisation. Nor written as-is — such a row is invisible in the
            // admin and the next admin save deletes it.
            $context->addMessage(
                $sku,
                sprintf(
                    'Catalog Price Scope is global, so the tier price for website "%s" was skipped;'
                    . ' omit "website" to price for all websites.',
                    $reference
                )
            );

            return null;
        }

        $websiteId = $this->storeWebsiteMap->getWebsiteId($reference);
        if ($websiteId === null) {
            $context->addMessage($sku, sprintf('Unknown website code "%s"; tier price skipped.', $reference));

            return null;
        }

        return $websiteId;
    }

    /**
     * Exactly one of price / percentage_discount, in core's stored shape:
     * an absolute price keeps percentage_value NULL, a percentage stores
     * value = 0 (the column is NOT NULL, and 0 is what makes core's legacy
     * IF(value, ...) index expression take the percentage branch).
     *
     * @return array{value: string, percentage_value: string|null}|null null when unusable
     */
    private function resolveAmount(
        BatchContext $context,
        string $sku,
        TierPriceInterface $entry,
        string $typeId
    ): ?array {
        $price = $entry->getPrice();
        $percentage = $entry->getPercentageDiscount();

        if ($price !== null && $percentage !== null) {
            $context->addMessage(
                $sku,
                'Tier price entry carries both "price" and "percentage_discount"; entry skipped.'
            );

            return null;
        }
        if ($price === null && $percentage === null) {
            $context->addMessage(
                $sku,
                'Tier price entry carries neither "price" nor "percentage_discount"; entry skipped.'
            );

            return null;
        }

        if ($percentage !== null) {
            if ($percentage < 0 || $percentage > 100) {
                $context->addMessage(
                    $sku,
                    sprintf(
                        'Tier price percentage_discount "%s" must be between 0 and 100; entry skipped.',
                        $percentage
                    )
                );

                return null;
            }

            return [
                'value' => TierPrice::scaleValue(0.0),
                'percentage_value' => TierPrice::scalePercentage($percentage),
            ];
        }

        if ($price < 0) {
            $context->addMessage(
                $sku,
                sprintf('Tier price "%s" cannot be negative; entry skipped.', $price)
            );

            return null;
        }
        if (in_array($typeId, self::PERCENTAGE_ONLY_TYPES, true)) {
            $context->addMessage(
                $sku,
                sprintf(
                    '"%s" products accept "percentage_discount" tier prices only; the absolute price was skipped.',
                    $typeId
                )
            );

            return null;
        }

        return ['value' => TierPrice::scaleValue($price), 'percentage_value' => null];
    }

    /**
     * Both sentinels accept their full spelling ("all groups" / "all websites")
     * and the shorthand "all", case-insensitively — the full spellings are the
     * ones Magento's own tier-price API uses.
     */
    private function isAllSentinel(string $reference, string $sentinel): bool
    {
        return strcasecmp($reference, $sentinel) === 0
            || strcasecmp($reference, TierPriceInterface::ALL) === 0;
    }

    /**
     * @return string[] product types the attribute applies to; [] means all of them
     */
    private function parseApplyTo(string $applyTo): array
    {
        $types = array_map('trim', explode(',', $applyTo));

        return array_values(array_filter($types, static fn (string $type): bool => $type !== ''));
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function getSortOrder(): int
    {
        return 740;
    }
}
