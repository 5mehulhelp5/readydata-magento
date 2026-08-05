<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Api\Data;

/**
 * One category the caller wants to exist with the given properties.
 *
 * Identity is the full category path from a level-1 root name, using the same
 * grammar as the product payload's "categories" field (see
 * {@see \ReadyData\Import\Model\Category\PathParser}): "/" separates segments
 * only when unescaped, so "\/" is a literal slash inside a name. An optional
 * category_id overrides that and identifies the row directly, which is what
 * makes a rename expressible — a path alone cannot say "this category, under a
 * new name".
 *
 * Yes/no properties are transported as int, never bool. Magento's EAV layer
 * treats a false value as "empty" and deletes the value row instead of storing
 * a 0, so a bool false over the wire would silently clear the attribute rather
 * than set it. The same reasoning applies to AttributeDefinitionInterface.
 *
 * @api
 */
interface CategoryDefinitionInterface
{
    public const PATH = 'path';
    public const CATEGORY_ID = 'category_id';
    public const NAME = 'name';
    public const URL_KEY = 'url_key';
    public const IS_ACTIVE = 'is_active';
    public const INCLUDE_IN_MENU = 'include_in_menu';
    public const IS_ANCHOR = 'is_anchor';
    public const POSITION = 'position';
    public const CUSTOM_ATTRIBUTES = 'custom_attributes';
    public const CLEAR_ATTRIBUTES = 'clear_attributes';

    /**
     * Full path from a level-1 root name, e.g. "Default Category/Men/Shirts".
     * Identifies the category when category_id is absent; informational
     * otherwise. Root categories are never created and never written to.
     *
     * @return string|null
     */
    public function getPath(): ?string;

    /**
     * @param string|null $path
     * @return $this
     */
    public function setPath(?string $path): self;

    /**
     * Authoritative identity when present. Required to rename a category,
     * because the new name no longer matches the stored path.
     *
     * @return int|null
     */
    public function getCategoryId(): ?int;

    /**
     * @param int|null $categoryId
     * @return $this
     */
    public function setCategoryId(?int $categoryId): self;

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
     * Raw sibling position. Siblings are never re-sequenced to make room.
     *
     * @return int|null
     */
    public function getPosition(): ?int;

    /**
     * @param int|null $position
     * @return $this
     */
    public function setPosition(?int $position): self;

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
