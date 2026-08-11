<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Api\Data;

/**
 * One tier (group) price row carried by a product.
 *
 * A row is identified by the triple (customer group, quantity, website) — the
 * same tuple Magento's unique key on catalog_product_entity_tier_price uses — and
 * carries either an absolute price or a percentage discount off the product's
 * price, never both.
 *
 * References resolve by human-readable name, like the rest of the module: a
 * customer group by its code (or numeric ID), a website by its code. The two
 * "everything" sentinels are spelled the way Magento's own tier-price REST API
 * spells them, so a feed already speaking that API needs no translation.
 *
 * @api
 */
interface TierPriceInterface
{
    public const CUSTOMER_GROUP = 'customer_group';
    public const QTY = 'qty';
    public const PRICE = 'price';
    public const PERCENTAGE_DISCOUNT = 'percentage_discount';
    public const WEBSITE = 'website';

    /**
     * Sentinel meaning "every customer group", matched case-insensitively.
     * "all" is accepted as a shorthand.
     */
    public const ALL_GROUPS = 'all groups';

    /**
     * Sentinel meaning "every website", matched case-insensitively. Omitting
     * "website" means the same thing; "all" is accepted as a shorthand.
     */
    public const ALL_WEBSITES = 'all websites';

    /**
     * Shorthand accepted for both sentinels above.
     */
    public const ALL = 'all';

    /**
     * Customer group this price applies to: the group's code, its numeric ID,
     * or "all groups" for every group. Codes are matched case-insensitively
     * (as Magento's own tier-price API does), trimmed; when two groups share a
     * code the lowest ID wins. A group whose code is digits-only can only be
     * referenced by ID. Required — an empty value skips the row with a warning.
     *
     * @return string
     */
    public function getCustomerGroup(): string;

    /**
     * @param string $customerGroup
     * @return $this
     */
    public function setCustomerGroup(string $customerGroup): self;

    /**
     * Quantity from which this price applies. Must be greater than zero;
     * stored with 4 decimals. Note that only qty = 1 rows reach Magento's price
     * index — larger quantity breaks price the cart and the product page's tier
     * table, but do not move the indexed final/minimum price.
     *
     * @return float
     */
    public function getQty(): float;

    /**
     * @param float $qty
     * @return $this
     */
    public function setQty(float $qty): self;

    /**
     * Absolute tier price, in the store's base currency, stored with 6
     * decimals. Mutually exclusive with percentage_discount: exactly one of the
     * two must be present, or the row is skipped with a warning.
     *
     * @return float|null
     */
    public function getPrice(): ?float;

    /**
     * @param float $price
     * @return $this
     */
    public function setPrice(float $price): self;

    /**
     * Percentage taken OFF the product's price (25 means "25% off", so a
     * product at 100 costs 75), between 0 and 100, stored with 2 decimals.
     * Mutually exclusive with price.
     *
     * @return float|null
     */
    public function getPercentageDiscount(): ?float;

    /**
     * @param float $percentageDiscount
     * @return $this
     */
    public function setPercentageDiscount(float $percentageDiscount): self;

    /**
     * Website code this price is limited to, or "all websites" / omitted for
     * every website. Only legal when Catalog Price Scope is Website; under the
     * default global scope an entry naming a website is skipped with a warning.
     *
     * @return string|null
     */
    public function getWebsite(): ?string;

    /**
     * @param string $website
     * @return $this
     */
    public function setWebsite(string $website): self;
}
