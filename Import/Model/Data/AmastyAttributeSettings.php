<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Data;

use ReadyData\Import\Api\Data\AmastyAttributeSettingsInterface;

class AmastyAttributeSettings implements AmastyAttributeSettingsInterface
{
    private ?int $displayMode = null;
    private ?int $isMultiselect = null;
    private ?string $urlAlias = null;
    private ?int $isExpanded = null;
    private ?string $tooltip = null;
    private ?int $sliderStep = null;
    private ?int $isBrand = null;
    private ?array $filterExtra = null;
    private ?array $optionSettings = null;

    public function getDisplayMode(): ?int
    {
        return $this->displayMode;
    }

    public function setDisplayMode(?int $displayMode): AmastyAttributeSettingsInterface
    {
        $this->displayMode = $displayMode;
        return $this;
    }

    public function getIsMultiselect(): ?int
    {
        return $this->isMultiselect;
    }

    public function setIsMultiselect(?int $isMultiselect): AmastyAttributeSettingsInterface
    {
        $this->isMultiselect = $isMultiselect;
        return $this;
    }

    public function getUrlAlias(): ?string
    {
        return $this->urlAlias;
    }

    public function setUrlAlias(?string $urlAlias): AmastyAttributeSettingsInterface
    {
        $this->urlAlias = $urlAlias;
        return $this;
    }

    public function getIsExpanded(): ?int
    {
        return $this->isExpanded;
    }

    public function setIsExpanded(?int $isExpanded): AmastyAttributeSettingsInterface
    {
        $this->isExpanded = $isExpanded;
        return $this;
    }

    public function getTooltip(): ?string
    {
        return $this->tooltip;
    }

    public function setTooltip(?string $tooltip): AmastyAttributeSettingsInterface
    {
        $this->tooltip = $tooltip;
        return $this;
    }

    public function getSliderStep(): ?int
    {
        return $this->sliderStep;
    }

    public function setSliderStep(?int $sliderStep): AmastyAttributeSettingsInterface
    {
        $this->sliderStep = $sliderStep;
        return $this;
    }

    public function getIsBrand(): ?int
    {
        return $this->isBrand;
    }

    public function setIsBrand(?int $isBrand): AmastyAttributeSettingsInterface
    {
        $this->isBrand = $isBrand;
        return $this;
    }

    public function getFilterExtra(): ?array
    {
        return $this->filterExtra;
    }

    public function setFilterExtra(?array $filterExtra): AmastyAttributeSettingsInterface
    {
        $this->filterExtra = $filterExtra;
        return $this;
    }

    public function getOptionSettings(): ?array
    {
        return $this->optionSettings;
    }

    public function setOptionSettings(?array $optionSettings): AmastyAttributeSettingsInterface
    {
        $this->optionSettings = $optionSettings;
        return $this;
    }
}
