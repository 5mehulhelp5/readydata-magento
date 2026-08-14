<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Api\Data;

/**
 * An already-Magento-shaped product attribute definition, as authored by the
 * calling application (the system of record for what each attribute should be).
 *
 * attribute_code is always required. frontend_input is required to create an
 * attribute but optional when updating an existing one (an omitted input keeps
 * the stored shape). Everything else is optional: the caller supplies whatever
 * it decided, and omitted properties fall back to Magento's own column defaults
 * (via EavSetup). The module does not invent business semantics — it validates
 * and persists what it is given.
 *
 * Boolean-like flags are transported as int (0/1) so "absent" (null) is
 * distinguishable from "explicitly 0" and maps cleanly over the Web API.
 *
 * @api
 */
interface AttributeDefinitionInterface
{
    public const ATTRIBUTE_CODE = 'attribute_code';
    public const FRONTEND_INPUT = 'frontend_input';
    public const BACKEND_TYPE = 'backend_type';
    public const BACKEND_MODEL = 'backend_model';
    public const SOURCE_MODEL = 'source_model';
    public const FRONTEND_CLASS = 'frontend_class';
    public const FRONTEND_LABEL = 'frontend_label';
    public const SCOPE = 'scope';
    public const IS_REQUIRED = 'is_required';
    public const IS_UNIQUE = 'is_unique';
    public const DEFAULT_VALUE = 'default_value';
    public const IS_SEARCHABLE = 'is_searchable';
    public const IS_FILTERABLE = 'is_filterable';
    public const IS_FILTERABLE_IN_SEARCH = 'is_filterable_in_search';
    public const IS_COMPARABLE = 'is_comparable';
    public const IS_VISIBLE_ON_FRONT = 'is_visible_on_front';
    public const IS_HTML_ALLOWED_ON_FRONT = 'is_html_allowed_on_front';
    public const IS_WYSIWYG_ENABLED = 'is_wysiwyg_enabled';
    public const USED_IN_PRODUCT_LISTING = 'used_in_product_listing';
    public const USED_FOR_SORT_BY = 'used_for_sort_by';
    public const IS_VISIBLE_IN_GRID = 'is_visible_in_grid';
    public const IS_FILTERABLE_IN_GRID = 'is_filterable_in_grid';
    public const IS_USED_IN_GRID = 'is_used_in_grid';
    public const APPLY_TO = 'apply_to';
    public const OPTIONS = 'options';
    public const IS_USER_DEFINED = 'is_user_defined';
    public const NOTE = 'note';
    public const PLACEMENTS = 'placements';
    public const AMASTY = 'amasty';

    /**
     * Attribute value scope; maps to catalog_eav_attribute.is_global.
     */
    public const SCOPE_STORE = 'store';
    public const SCOPE_WEBSITE = 'website';
    public const SCOPE_GLOBAL = 'global';

    /**
     * @return string
     */
    public function getAttributeCode(): string;

    /**
     * @param string $attributeCode
     * @return $this
     */
    public function setAttributeCode(string $attributeCode): self;

    /**
     * Magento frontend input (text, textarea, select, multiselect, boolean,
     * date, datetime, price, ...).
     *
     * @return string
     */
    public function getFrontendInput(): string;

    /**
     * @param string $frontendInput
     * @return $this
     */
    public function setFrontendInput(string $frontendInput): self;

    /**
     * Storage type: varchar|int|decimal|text|datetime. Derived from
     * frontend_input when omitted.
     *
     * @return string|null
     */
    public function getBackendType(): ?string;

    /**
     * @param string|null $backendType
     * @return $this
     */
    public function setBackendType(?string $backendType): self;

    /**
     * @return string|null
     */
    public function getBackendModel(): ?string;

    /**
     * @param string|null $backendModel
     * @return $this
     */
    public function setBackendModel(?string $backendModel): self;

    /**
     * @return string|null
     */
    public function getSourceModel(): ?string;

    /**
     * @param string|null $sourceModel
     * @return $this
     */
    public function setSourceModel(?string $sourceModel): self;

    /**
     * @return string|null
     */
    public function getFrontendClass(): ?string;

    /**
     * @param string|null $frontendClass
     * @return $this
     */
    public function setFrontendClass(?string $frontendClass): self;

    /**
     * Default admin-scope label.
     *
     * @return string|null
     */
    public function getFrontendLabel(): ?string;

    /**
     * @param string|null $frontendLabel
     * @return $this
     */
    public function setFrontendLabel(?string $frontendLabel): self;

    /**
     * One of: store, website, global. Maps to is_global.
     *
     * @return string|null
     */
    public function getScope(): ?string;

    /**
     * @param string|null $scope
     * @return $this
     */
    public function setScope(?string $scope): self;

    /**
     * @return int|null
     */
    public function getIsRequired(): ?int;

    /**
     * @param int|null $isRequired
     * @return $this
     */
    public function setIsRequired(?int $isRequired): self;

    /**
     * @return int|null
     */
    public function getIsUnique(): ?int;

    /**
     * @param int|null $isUnique
     * @return $this
     */
    public function setIsUnique(?int $isUnique): self;

    /**
     * @return string|null
     */
    public function getDefaultValue(): ?string;

    /**
     * @param string|null $defaultValue
     * @return $this
     */
    public function setDefaultValue(?string $defaultValue): self;

    /**
     * @return int|null
     */
    public function getIsSearchable(): ?int;

    /**
     * @param int|null $isSearchable
     * @return $this
     */
    public function setIsSearchable(?int $isSearchable): self;

    /**
     * @return int|null
     */
    public function getIsFilterable(): ?int;

    /**
     * @param int|null $isFilterable
     * @return $this
     */
    public function setIsFilterable(?int $isFilterable): self;

    /**
     * @return int|null
     */
    public function getIsFilterableInSearch(): ?int;

    /**
     * @param int|null $isFilterableInSearch
     * @return $this
     */
    public function setIsFilterableInSearch(?int $isFilterableInSearch): self;

    /**
     * @return int|null
     */
    public function getIsComparable(): ?int;

    /**
     * @param int|null $isComparable
     * @return $this
     */
    public function setIsComparable(?int $isComparable): self;

    /**
     * @return int|null
     */
    public function getIsVisibleOnFront(): ?int;

    /**
     * @param int|null $isVisibleOnFront
     * @return $this
     */
    public function setIsVisibleOnFront(?int $isVisibleOnFront): self;

    /**
     * @return int|null
     */
    public function getIsHtmlAllowedOnFront(): ?int;

    /**
     * @param int|null $isHtmlAllowedOnFront
     * @return $this
     */
    public function setIsHtmlAllowedOnFront(?int $isHtmlAllowedOnFront): self;

    /**
     * @return int|null
     */
    public function getIsWysiwygEnabled(): ?int;

    /**
     * @param int|null $isWysiwygEnabled
     * @return $this
     */
    public function setIsWysiwygEnabled(?int $isWysiwygEnabled): self;

    /**
     * @return int|null
     */
    public function getUsedInProductListing(): ?int;

    /**
     * @param int|null $usedInProductListing
     * @return $this
     */
    public function setUsedInProductListing(?int $usedInProductListing): self;

    /**
     * @return int|null
     */
    public function getUsedForSortBy(): ?int;

    /**
     * @param int|null $usedForSortBy
     * @return $this
     */
    public function setUsedForSortBy(?int $usedForSortBy): self;

    /**
     * @return int|null
     */
    public function getIsVisibleInGrid(): ?int;

    /**
     * @param int|null $isVisibleInGrid
     * @return $this
     */
    public function setIsVisibleInGrid(?int $isVisibleInGrid): self;

    /**
     * @return int|null
     */
    public function getIsFilterableInGrid(): ?int;

    /**
     * @param int|null $isFilterableInGrid
     * @return $this
     */
    public function setIsFilterableInGrid(?int $isFilterableInGrid): self;

    /**
     * @return int|null
     */
    public function getIsUsedInGrid(): ?int;

    /**
     * @param int|null $isUsedInGrid
     * @return $this
     */
    public function setIsUsedInGrid(?int $isUsedInGrid): self;

    /**
     * Product types the attribute applies to; null/empty = all types.
     *
     * @return string[]|null
     */
    public function getApplyTo(): ?array;

    /**
     * @param string[]|null $applyTo
     * @return $this
     */
    public function setApplyTo(?array $applyTo): self;

    /**
     * Admin-scope option labels to seed for select/multiselect attributes.
     *
     * @return string[]|null
     */
    public function getOptions(): ?array;

    /**
     * @param string[]|null $options
     * @return $this
     */
    public function setOptions(?array $options): self;

    /**
     * @return int|null
     */
    public function getIsUserDefined(): ?int;

    /**
     * @param int|null $isUserDefined
     * @return $this
     */
    public function setIsUserDefined(?int $isUserDefined): self;

    /**
     * @return string|null
     */
    public function getNote(): ?string;

    /**
     * @param string|null $note
     * @return $this
     */
    public function setNote(?string $note): self;

    /**
     * Attribute-set/group placements (additive).
     *
     * @return \ReadyData\Import\Api\Data\AttributeSetPlacementInterface[]|null
     */
    public function getPlacements(): ?array;

    /**
     * @param \ReadyData\Import\Api\Data\AttributeSetPlacementInterface[]|null $placements
     * @return $this
     */
    public function setPlacements(?array $placements): self;

    /**
     * Amasty layered-navigation properties (filter settings, brand designation,
     * per-option brand data). Applied only when the matching Amasty module is
     * present; null = nothing Amasty-related to sync.
     *
     * @return \ReadyData\Import\Api\Data\AmastyAttributeSettingsInterface|null
     */
    public function getAmasty(): ?AmastyAttributeSettingsInterface;

    /**
     * @param \ReadyData\Import\Api\Data\AmastyAttributeSettingsInterface|null $amasty
     * @return $this
     */
    public function setAmasty(?AmastyAttributeSettingsInterface $amasty): self;
}
