<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Api\Data;

/**
 * Incoming product payload for bulk import.
 *
 * Core attributes are first-class fields for schema discoverability;
 * everything else travels in custom_attributes as code/value pairs.
 *
 * @api
 */
interface ProductInterface
{
    public const SKU = 'sku';
    public const TYPE_ID = 'type_id';
    public const ATTRIBUTE_SET = 'attribute_set';
    public const NAME = 'name';
    public const PRICE = 'price';
    public const STATUS = 'status';
    public const VISIBILITY = 'visibility';
    public const WEIGHT = 'weight';
    public const URL_KEY = 'url_key';
    public const WEBSITES = 'websites';
    public const STOCK = 'stock';
    public const CUSTOM_ATTRIBUTES = 'custom_attributes';
    public const CLEAR_ATTRIBUTES = 'clear_attributes';

    /**
     * @return string
     */
    public function getSku(): string;

    /**
     * @param string $sku
     * @return $this
     */
    public function setSku(string $sku): self;

    /**
     * Product type: simple, virtual, downloadable, configurable, grouped, bundle. Defaults to simple.
     *
     * @return string|null
     */
    public function getTypeId(): ?string;

    /**
     * @param string $typeId
     * @return $this
     */
    public function setTypeId(string $typeId): self;

    /**
     * Attribute set name or numeric ID. Defaults to the default attribute set.
     *
     * @return string|null
     */
    public function getAttributeSet(): ?string;

    /**
     * @param string $attributeSet
     * @return $this
     */
    public function setAttributeSet(string $attributeSet): self;

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
     * @return string|null
     */
    public function getUrlKey(): ?string;

    /**
     * @param string $urlKey
     * @return $this
     */
    public function setUrlKey(string $urlKey): self;

    /**
     * Website codes the product should be assigned to.
     *
     * @return string[]|null
     */
    public function getWebsites(): ?array;

    /**
     * @param string[] $websites
     * @return $this
     */
    public function setWebsites(array $websites): self;

    /**
     * @return \ReadyData\Import\Api\Data\StockDataInterface|null
     */
    public function getStock(): ?StockDataInterface;

    /**
     * @param \ReadyData\Import\Api\Data\StockDataInterface $stock
     * @return $this
     */
    public function setStock(StockDataInterface $stock): self;

    /**
     * Additional EAV attribute values as code/value pairs.
     * Multiselect values are comma-separated option labels.
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
     * Attribute codes whose stored value should be DELETED in the request's
     * store scope (global attributes always clear the default scope). A
     * cleared store-scoped value falls back to the default value. Static,
     * unknown, and — at default scope — required attributes are skipped with
     * a per-product warning. When the same attribute is also written in this
     * product, the write wins and the clear is skipped.
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
