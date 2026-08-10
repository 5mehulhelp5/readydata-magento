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
interface CategoryDefinitionInterface extends CategoryValuesInterface
{
    public const PATH = 'path';
    public const CATEGORY_ID = 'category_id';
    public const ROOT_CATEGORY_ID = 'root_category_id';
    public const PARENT_PATH = 'parent_path';
    public const PARENT_CATEGORY_ID = 'parent_category_id';
    public const NAME = 'name';
    public const URL_KEY = 'url_key';
    public const IS_ACTIVE = 'is_active';
    public const INCLUDE_IN_MENU = 'include_in_menu';
    public const IS_ANCHOR = 'is_anchor';
    public const POSITION = 'position';
    public const CUSTOM_ATTRIBUTES = 'custom_attributes';
    public const CLEAR_ATTRIBUTES = 'clear_attributes';
    public const DELETE = 'delete';
    public const DELETE_CHILDREN = 'delete_children';
    public const STORE_VALUES = 'store_values';

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
     * Pins this entry's `path` to one root category, overriding
     * `settings.root_category_id` for this entry alone — for a payload that
     * spans several root trees and cannot state one root for the whole request.
     *
     * See `settings.root_category_id` for what a pin does and why a path that
     * contradicts it is refused rather than reparented. On a single-segment
     * path the pin identifies the root itself, which is what lets a payload
     * address one of two same-named roots before any `category_id` exists.
     *
     * @return int|null
     */
    public function getRootCategoryId(): ?int;

    /**
     * @param int|null $rootCategoryId
     * @return $this
     */
    public function setRootCategoryId(?int $rootCategoryId): self;

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
     * The parent this category should live under, as a full path from a level-1
     * root name — same grammar as {@see getPath()}, and a single segment names a
     * root. Present means "reconcile the parent", so a category stored elsewhere
     * is MOVED here; omitted means the parent is left alone.
     *
     * Deliberately separate from `path`: `path` identifies, and a caller that
     * kept a path on file from before a rename or an earlier move must never
     * have it read as "put it back there".
     *
     * A move requires `category_id` for the same reason a rename does — after
     * the move the old path no longer resolves, so path identity would not
     * survive a replay.
     *
     * @return string|null
     */
    public function getParentPath(): ?string;

    /**
     * @param string|null $parentPath
     * @return $this
     */
    public function setParentPath(?string $parentPath): self;

    /**
     * The parent this category should live under, by ID. Authoritative when both
     * this and `parent_path` are given, which is how a caller addresses a parent
     * whose name is ambiguous among its siblings. `1` is the catalog tree root
     * and promotes the category to a level-1 root.
     *
     * @return int|null
     */
    public function getParentCategoryId(): ?int;

    /**
     * @param int|null $parentCategoryId
     * @return $this
     */
    public function setParentCategoryId(?int $parentCategoryId): self;

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
     * 0 or 1. Remove this category instead of reconciling it. Cannot be combined
     * with fields that set a value — a payload that both deletes a category and
     * describes what it should be is a mistake, not an instruction.
     *
     * Deleting is recursive in Magento: the whole descendant subtree goes with
     * it, which is why {@see getDeleteChildren()} has to be set explicitly for a
     * category that still has children.
     *
     * @return int|null
     */
    public function getDelete(): ?int;

    /**
     * @param int|null $delete
     * @return $this
     */
    public function setDelete(?int $delete): self;

    /**
     * 0 or 1. Acknowledges that deleting this category also deletes every
     * category beneath it. Without it, a delete of a non-empty category is
     * refused rather than silently removing a whole branch of the catalog.
     *
     * Only meaningful alongside `delete`.
     *
     * @return int|null
     */
    public function getDeleteChildren(): ?int;

    /**
     * @param int|null $deleteChildren
     * @return $this
     */
    public function setDeleteChildren(?int $deleteChildren): self;

    /**
     * Additional store views to write this category's values in, alongside the
     * request's own scope — so one request can carry the category's structure
     * and every localized value set it has.
     *
     * Each block names its store view and carries only what has a store
     * dimension ({@see CategoryValuesInterface}). Everything structural stays
     * on the category itself and is written once, at the request's scope: where
     * it sits, what identifies it, its `position`, and whether it is deleted.
     *
     * The blocks run after the category itself, in one transaction with it: a
     * half-localized category is worse than an unlocalized one. A block is
     * skipped, with its own result row, when its store view does not exist or
     * does not show this category's root tree — and never at the cost of the
     * category's own write or of its other scopes.
     *
     * @return \ReadyData\Import\Api\Data\CategoryStoreValuesInterface[]|null
     */
    public function getStoreValues(): ?array;

    /**
     * @param \ReadyData\Import\Api\Data\CategoryStoreValuesInterface[]|null $storeValues
     * @return $this
     */
    public function setStoreValues(?array $storeValues): self;
}
