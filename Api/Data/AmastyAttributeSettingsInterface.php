<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Api\Data;

/**
 * Amasty layered-navigation properties for one attribute, as authored by the
 * calling application. Groups three independent concerns, each applied only
 * when the corresponding Amasty module/table is present (soft dependency):
 *
 *  - filter settings  -> Amasty Improved Layered Navigation per-attribute row
 *                        (e.g. amasty_amshopby_attribute), keyed by attribute_id.
 *  - is_brand         -> Amasty Shop by Brand attribute designation
 *                        (amshopby_brand/general/attribute_code config).
 *  - option_settings  -> per-option brand/landing data
 *                        (e.g. amasty_amshopby_option_setting).
 *
 * All fields are friendly and optional. The module owns the friendly->column
 * mapping and drops anything the installed Amasty version does not expose, so
 * an omitted or unknown value never fails the base attribute sync.
 *
 * @api
 */
interface AmastyAttributeSettingsInterface
{
    public const DISPLAY_MODE = 'display_mode';
    public const IS_MULTISELECT = 'is_multiselect';
    public const URL_ALIAS = 'url_alias';
    public const IS_EXPANDED = 'is_expanded';
    public const TOOLTIP = 'tooltip';
    public const SLIDER_STEP = 'slider_step';
    public const IS_BRAND = 'is_brand';
    public const FILTER_EXTRA = 'filter_extra';
    public const OPTION_SETTINGS = 'option_settings';

    /**
     * Amasty numeric display-mode code for the filter: 0 Labels, 1 Dropdown,
     * 2 Slider, 3 From-To only, 4 Images, 5 Images+Labels, 6 Text swatch.
     * Written to amasty_amshopby_filter_setting.display_mode (a smallint).
     *
     * @return int|null
     */
    public function getDisplayMode(): ?int;

    /**
     * @param int|null $displayMode
     * @return $this
     */
    public function setDisplayMode(?int $displayMode): self;

    /**
     * @return int|null
     */
    public function getIsMultiselect(): ?int;

    /**
     * @param int|null $isMultiselect
     * @return $this
     */
    public function setIsMultiselect(?int $isMultiselect): self;

    /**
     * SEO url alias for the filter (amasty_amshopby_filter_setting.attribute_url_alias).
     *
     * @return string|null
     */
    public function getUrlAlias(): ?string;

    /**
     * @param string|null $urlAlias
     * @return $this
     */
    public function setUrlAlias(?string $urlAlias): self;

    /**
     * Whether the filter block renders expanded by default.
     *
     * @return int|null
     */
    public function getIsExpanded(): ?int;

    /**
     * @param int|null $isExpanded
     * @return $this
     */
    public function setIsExpanded(?int $isExpanded): self;

    /**
     * @return string|null
     */
    public function getTooltip(): ?string;

    /**
     * @param string|null $tooltip
     * @return $this
     */
    public function setTooltip(?string $tooltip): self;

    /**
     * Slider step for range/slider filters.
     *
     * @return int|null
     */
    public function getSliderStep(): ?int;

    /**
     * @param int|null $sliderStep
     * @return $this
     */
    public function setSliderStep(?int $sliderStep): self;

    /**
     * Designate this attribute as the Shop by Brand attribute (1) or leave
     * brand config untouched (null/0).
     *
     * @return int|null
     */
    public function getIsBrand(): ?int;

    /**
     * @param int|null $isBrand
     * @return $this
     */
    public function setIsBrand(?int $isBrand): self;

    /**
     * Version-specific filter columns, already keyed by real Amasty column
     * name. Merged over the friendly fields, intersected with the live table.
     *
     * @return array<string, string|int>|null
     */
    public function getFilterExtra(): ?array;

    /**
     * @param array<string, string|int>|null $filterExtra
     * @return $this
     */
    public function setFilterExtra(?array $filterExtra): self;

    /**
     * @return \ReadyData\Import\Api\Data\AmastyOptionSettingInterface[]|null
     */
    public function getOptionSettings(): ?array;

    /**
     * @param \ReadyData\Import\Api\Data\AmastyOptionSettingInterface[]|null $optionSettings
     * @return $this
     */
    public function setOptionSettings(?array $optionSettings): self;
}
