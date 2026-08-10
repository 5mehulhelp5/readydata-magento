<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Api\Data;

/**
 * The product properties that HAVE a store dimension — everything Magento
 * stores as an EAV value rather than a column or a relation table.
 *
 * Shared by {@see ProductInterface} (which adds everything with no store
 * dimension: the SKU and type that identify it, its attribute set, websites,
 * categories, links, media, stock and tier prices) and
 * {@see ProductStoreValuesInterface} (which adds nothing but the store view it
 * applies to). The split is the same line {@see CategoryValuesInterface} draws
 * on the category side, and it is what lets one flattening pass serve both a
 * product and each of its scoped blocks.
 *
 * Which store rows a value actually lands in is not decided here: that is the
 * attribute's own scope configuration, applied to whichever scope is being
 * addressed — a website-scoped attribute fans out across the addressed store's
 * website, and a global one has no store dimension at all.
 *
 * @api
 */
interface ProductValuesInterface
{
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
     * Any other attribute, as code/value pairs.
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
     * Attribute codes whose stored value should be DELETED in the scope this
     * carries, so the store view falls back to the default value. Static,
     * unknown, and — at default scope — required attributes are skipped with a
     * per-product warning. When the same attribute is also written in the same
     * scope, the write wins and the clear is skipped.
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
