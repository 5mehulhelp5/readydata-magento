<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Api\Data;

/**
 * The category properties that HAVE a store dimension — everything Magento
 * stores as an EAV value rather than a column.
 *
 * Shared by {@see CategoryDefinitionInterface} (which adds the structural
 * fields: where the category sits, what identifies it, whether it is deleted)
 * and {@see CategoryStoreValuesInterface} (which adds nothing but the store
 * view it applies to). The split is not tidiness — it is the line the endpoint
 * already enforces with `store_scope_structural_change`, expressed in the type
 * system so a store-scoped write cannot be handed a structural field to begin
 * with.
 *
 * `position` is deliberately absent: it is a column on
 * `catalog_category_entity` shared by every store view, which is why it lives
 * on the definition alone.
 *
 * @api
 */
interface CategoryValuesInterface
{
    /**
     * Category name. Omitted means the last segment of the path.
     *
     * @return string|null
     */
    public function getName(): ?string;

    /**
     * @param string|null $name
     * @return $this
     */
    public function setName(?string $name): self;

    /**
     * URL key. Omitted means it is derived from the name on create and on
     * rename, and left untouched otherwise — Magento only auto-derives a
     * url_key when the stored one is empty, so a rename would keep the old
     * slug forever if this were never set.
     *
     * @return string|null
     */
    public function getUrlKey(): ?string;

    /**
     * @param string|null $urlKey
     * @return $this
     */
    public function setUrlKey(?string $urlKey): self;

    /**
     * 0 or 1. Defaults to 1 on create; omitted means unchanged on update.
     *
     * @return int|null
     */
    public function getIsActive(): ?int;

    /**
     * @param int|null $isActive
     * @return $this
     */
    public function setIsActive(?int $isActive): self;

    /**
     * 0 or 1. Defaults to 1 on create; omitted means unchanged on update.
     *
     * @return int|null
     */
    public function getIncludeInMenu(): ?int;

    /**
     * @param int|null $includeInMenu
     * @return $this
     */
    public function setIncludeInMenu(?int $includeInMenu): self;

    /**
     * 0 or 1. Omitted means unchanged (the attribute default applies on create).
     *
     * @return int|null
     */
    public function getIsAnchor(): ?int;

    /**
     * @param int|null $isAnchor
     * @return $this
     */
    public function setIsAnchor(?int $isAnchor): self;

    /**
     * Any other category attribute: description, meta_title, display_mode,
     * available_sort_by (comma-joined), landing_page, page_layout and so on.
     *
     * Values are written verbatim — there is no option-label resolution here,
     * so a select attribute needs its option ID rather than its label.
     *
     * @return \ReadyData\Import\Api\Data\CustomAttributeInterface[]|null
     */
    public function getCustomAttributes(): ?array;

    /**
     * @param \ReadyData\Import\Api\Data\CustomAttributeInterface[]|null $customAttributes
     * @return $this
     */
    public function setCustomAttributes(?array $customAttributes): self;

    /**
     * Attribute codes to revert to their default value. At store scope this
     * drops the store override; at default scope it removes the value.
     * Structural and required attributes cannot be cleared.
     *
     * @return string[]|null
     */
    public function getClearAttributes(): ?array;

    /**
     * @param string[]|null $clearAttributes
     * @return $this
     */
    public function setClearAttributes(?array $clearAttributes): self;
}
