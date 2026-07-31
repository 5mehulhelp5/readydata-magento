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
    public const CATEGORIES = 'categories';
    public const STOCK = 'stock';
    public const CUSTOM_ATTRIBUTES = 'custom_attributes';
    public const CLEAR_ATTRIBUTES = 'clear_attributes';
    public const CONFIGURABLE = 'configurable';
    public const LINKS = 'links';
    public const MEDIA = 'media';

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
     * Category assignments. Each entry is either a full category path from
     * the root category name ("Default Category/Men/Shirts", separator "/")
     * or a numeric category ID. "/" splits only when unescaped: "\/" is a
     * literal slash inside a name ("Default Category/Wo\/Men") and "\\" a
     * literal backslash. When present, REPLACES the product's
     * assignments (an empty array removes them all); null/omitted leaves
     * them unchanged. Missing path segments below an existing root are
     * auto-created. Assignments are global (not store-scoped) — send them
     * on one store pass only.
     *
     * @return string[]|null
     */
    public function getCategories(): ?array;

    /**
     * @param string[] $categories
     * @return $this
     */
    public function setCategories(array $categories): self;

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

    /**
     * Configurable-product structure. Set on a "configurable" parent to
     * declare its variation axes (super attribute codes) and child SKUs:
     * {"super_attributes": ["color", "size"], "children": ["SKU-RED-S", ...]}.
     * Children are ordinary simple/virtual product payloads and should be
     * sent before/with the parent so their rows exist when it is linked.
     * null/omitted leaves an existing configurable's structure untouched.
     *
     * @return \ReadyData\Import\Api\Data\ConfigurableDataInterface|null
     */
    public function getConfigurable(): ?ConfigurableDataInterface;

    /**
     * @param \ReadyData\Import\Api\Data\ConfigurableDataInterface $configurable
     * @return $this
     */
    public function setConfigurable(ConfigurableDataInterface $configurable): self;

    /**
     * Related, up-sell and cross-sell links:
     * {"related": ["BELT-01", "SOCKS-02"], "up_sell": [...], "cross_sell": []}.
     * Each sub-field, when present (including []), REPLACES exactly that link
     * type's set in the given order — the array order becomes the storefront
     * position, so the feed owns the ordering of the types it sends. An omitted
     * sub-field leaves that link type untouched, and null/omitted links leaves
     * all of them untouched. Targets must already exist and are matched
     * case-sensitively against the stored SKU; unknown SKUs are skipped with a
     * per-product warning, so send targets before/with the linking product. A
     * product may not link to itself. Links are global (not store-scoped) —
     * send them on one store pass only.
     *
     * @return \ReadyData\Import\Api\Data\ProductLinksInterface|null
     */
    public function getLinks(): ?ProductLinksInterface;

    /**
     * @param \ReadyData\Import\Api\Data\ProductLinksInterface $links
     * @return $this
     */
    public function setLinks(ProductLinksInterface $links): self;

    /**
     * Media gallery entries in display order. Each entry's "file" is either an
     * http(s) URL the module downloads into pub/media/catalog/product (standard
     * Magento dispersion, /a/b/abc.jpg) or a path relative to
     * pub/media/catalog/product for a file pushed out of band. When present,
     * REPLACES the gallery: it becomes exactly this ordered set and entries not
     * listed are removed (an empty array removes them all); null/omitted leaves
     * the gallery unchanged. Existing entries are matched by their stored file
     * path, so a re-import is idempotent and keeps its rows — and with them any
     * per-store data the admin added. Entries that cannot be resolved (download
     * failure, missing local file, unusable video URL) are skipped with a
     * per-product warning and make that product additive: inserts and updates
     * apply, no existing entry is removed. Media is written at the DEFAULT scope
     * only — store_view_code does not affect it, so send media on one store pass
     * only.
     *
     * @return \ReadyData\Import\Api\Data\MediaEntryInterface[]|null
     */
    public function getMedia(): ?array;

    /**
     * @param \ReadyData\Import\Api\Data\MediaEntryInterface[] $media
     * @return $this
     */
    public function setMedia(array $media): self;
}
