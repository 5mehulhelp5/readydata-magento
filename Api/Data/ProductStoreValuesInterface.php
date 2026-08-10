<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Api\Data;

/**
 * One store view's worth of a product's attribute values, carried on the
 * product itself so a single request can write the default scope and every
 * localized scope together.
 *
 * The scope a block names is the only thing it changes. Which store rows a
 * value actually lands in is still decided by the attribute's own scope
 * configuration, exactly as it is for the request-level scope: a website-scoped
 * attribute fans out across the named store's website, and a global attribute
 * is refused here rather than written — see {@see getCustomAttributes()}.
 *
 * The fields are exactly the ones that carry attribute values at the default
 * scope; everything else on the product (sku, type, attribute set, websites,
 * categories, links, media, stock, tier prices) has no store dimension and
 * stays on the product itself.
 *
 * A `url_key` here is a real store-scoped slug: that store view's rewrites are
 * generated from it, and the others keep the default one.
 *
 * @api
 */
interface ProductStoreValuesInterface
{
    public const STORE_ID = 'store_id';
    public const STORE_VIEW_CODE = 'store_view_code';
    public const NAME = 'name';
    public const PRICE = 'price';
    public const STATUS = 'status';
    public const VISIBILITY = 'visibility';
    public const WEIGHT = 'weight';
    public const URL_KEY = 'url_key';
    public const CUSTOM_ATTRIBUTES = 'custom_attributes';
    public const CLEAR_ATTRIBUTES = 'clear_attributes';

    /**
     * Target store view ID. Wins over store_view_code when both are given.
     * 0 addresses the default scope, which is useful when the request-level
     * scope is a store view and this block carries the fallback values.
     *
     * A block naming neither an ID nor a code, or naming a store view that
     * does not exist, is skipped with a per-product message — one bad scope
     * must not cost the product its other scopes.
     *
     * @return int|null
     */
    public function getStoreId(): ?int;

    /**
     * @param int $storeId
     * @return $this
     */
    public function setStoreId(int $storeId): self;

    /**
     * Target store view code, for callers that address stores by code.
     * Ignored when store_id is set.
     *
     * @return string|null
     */
    public function getStoreViewCode(): ?string;

    /**
     * @param string $storeViewCode
     * @return $this
     */
    public function setStoreViewCode(string $storeViewCode): self;

    /**
     * @return string|null
     */
    public function getName(): ?string;

    /**
     * @param string $name
     * @return $this
     */
    public function setName(string $name): self;

    /**
     * @return float|null
     */
    public function getPrice(): ?float;

    /**
     * @param float $price
     * @return $this
     */
    public function setPrice(float $price): self;

    /**
     * 1 = enabled, 2 = disabled.
     *
     * @return int|null
     */
    public function getStatus(): ?int;

    /**
     * @param int $status
     * @return $this
     */
    public function setStatus(int $status): self;

    /**
     * 1 = not visible, 2 = catalog, 3 = search, 4 = catalog & search.
     *
     * @return int|null
     */
    public function getVisibility(): ?int;

    /**
     * @param int $visibility
     * @return $this
     */
    public function setVisibility(int $visibility): self;

    /**
     * @return float|null
     */
    public function getWeight(): ?float;

    /**
     * @param float $weight
     * @return $this
     */
    public function setWeight(float $weight): self;

    /**
     * Store-scoped URL key. That store view's rewrites are generated from it,
     * and every other store keeps the default one.
     *
     * Never *generated* here, unlike the product's own: a slug derived from a
     * name is the product's identity on the storefront, and deriving one per
     * store view would invent a different URL for each. Absent means the store
     * view keeps resolving on the default-scope key.
     *
     * @return string|null
     */
    public function getUrlKey(): ?string;

    /**
     * @param string $urlKey
     * @return $this
     */
    public function setUrlKey(string $urlKey): self;

    /**
     * Additional EAV attribute values for this scope, as code/value pairs —
     * the same shape as the product's own custom_attributes.
     *
     * **Global attributes** (`is_global = 1`) are refused here with a
     * per-product message rather than written: they have no store dimension, so
     * the value would land at the default scope and overwrite the product's own
     * default-scope value from inside a block that named one store view.
     *
     * @return \ReadyData\Import\Api\Data\CustomAttributeInterface[]|null
     */
    public function getCustomAttributes(): ?array;

    /**
     * @param \ReadyData\Import\Api\Data\CustomAttributeInterface[] $customAttributes
     * @return $this
     */
    public function setCustomAttributes(array $customAttributes): self;

    /**
     * Attribute codes whose stored value should be DELETED in this block's
     * scope, so the store view falls back to the default value ("Use Default"
     * in the admin). Same guards as the product-level clear_attributes list,
     * evaluated against this scope.
     *
     * @return string[]|null
     */
    public function getClearAttributes(): ?array;

    /**
     * @param string[] $clearAttributes
     * @return $this
     */
    public function setClearAttributes(array $clearAttributes): self;
}
