<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Data;

use ReadyData\Import\Api\Data\AttributeDefinitionInterface;

class AttributeDefinition implements AttributeDefinitionInterface
{
    private string $attributeCode = '';
    private string $frontendInput = '';
    private ?string $backendType = null;
    private ?string $backendModel = null;
    private ?string $sourceModel = null;
    private ?string $frontendClass = null;
    private ?string $frontendLabel = null;
    private ?string $scope = null;
    private ?int $isRequired = null;
    private ?int $isUnique = null;
    private ?string $defaultValue = null;
    private ?int $isSearchable = null;
    private ?int $isFilterable = null;
    private ?int $isFilterableInSearch = null;
    private ?int $isComparable = null;
    private ?int $isVisibleOnFront = null;
    private ?int $isHtmlAllowedOnFront = null;
    private ?int $isWysiwygEnabled = null;
    private ?int $usedInProductListing = null;
    private ?int $usedForSortBy = null;
    private ?int $isVisibleInGrid = null;
    private ?int $isFilterableInGrid = null;
    private ?int $isUsedInGrid = null;
    private ?array $applyTo = null;
    private ?array $options = null;
    private ?int $isUserDefined = null;
    private ?string $note = null;
    private ?array $placements = null;

    public function getAttributeCode(): string
    {
        return $this->attributeCode;
    }

    public function setAttributeCode(string $attributeCode): AttributeDefinitionInterface
    {
        $this->attributeCode = $attributeCode;
        return $this;
    }

    public function getFrontendInput(): string
    {
        return $this->frontendInput;
    }

    public function setFrontendInput(string $frontendInput): AttributeDefinitionInterface
    {
        $this->frontendInput = $frontendInput;
        return $this;
    }

    public function getBackendType(): ?string
    {
        return $this->backendType;
    }

    public function setBackendType(?string $backendType): AttributeDefinitionInterface
    {
        $this->backendType = $backendType;
        return $this;
    }

    public function getBackendModel(): ?string
    {
        return $this->backendModel;
    }

    public function setBackendModel(?string $backendModel): AttributeDefinitionInterface
    {
        $this->backendModel = $backendModel;
        return $this;
    }

    public function getSourceModel(): ?string
    {
        return $this->sourceModel;
    }

    public function setSourceModel(?string $sourceModel): AttributeDefinitionInterface
    {
        $this->sourceModel = $sourceModel;
        return $this;
    }

    public function getFrontendClass(): ?string
    {
        return $this->frontendClass;
    }

    public function setFrontendClass(?string $frontendClass): AttributeDefinitionInterface
    {
        $this->frontendClass = $frontendClass;
        return $this;
    }

    public function getFrontendLabel(): ?string
    {
        return $this->frontendLabel;
    }

    public function setFrontendLabel(?string $frontendLabel): AttributeDefinitionInterface
    {
        $this->frontendLabel = $frontendLabel;
        return $this;
    }

    public function getScope(): ?string
    {
        return $this->scope;
    }

    public function setScope(?string $scope): AttributeDefinitionInterface
    {
        $this->scope = $scope;
        return $this;
    }

    public function getIsRequired(): ?int
    {
        return $this->isRequired;
    }

    public function setIsRequired(?int $isRequired): AttributeDefinitionInterface
    {
        $this->isRequired = $isRequired;
        return $this;
    }

    public function getIsUnique(): ?int
    {
        return $this->isUnique;
    }

    public function setIsUnique(?int $isUnique): AttributeDefinitionInterface
    {
        $this->isUnique = $isUnique;
        return $this;
    }

    public function getDefaultValue(): ?string
    {
        return $this->defaultValue;
    }

    public function setDefaultValue(?string $defaultValue): AttributeDefinitionInterface
    {
        $this->defaultValue = $defaultValue;
        return $this;
    }

    public function getIsSearchable(): ?int
    {
        return $this->isSearchable;
    }

    public function setIsSearchable(?int $isSearchable): AttributeDefinitionInterface
    {
        $this->isSearchable = $isSearchable;
        return $this;
    }

    public function getIsFilterable(): ?int
    {
        return $this->isFilterable;
    }

    public function setIsFilterable(?int $isFilterable): AttributeDefinitionInterface
    {
        $this->isFilterable = $isFilterable;
        return $this;
    }

    public function getIsFilterableInSearch(): ?int
    {
        return $this->isFilterableInSearch;
    }

    public function setIsFilterableInSearch(?int $isFilterableInSearch): AttributeDefinitionInterface
    {
        $this->isFilterableInSearch = $isFilterableInSearch;
        return $this;
    }

    public function getIsComparable(): ?int
    {
        return $this->isComparable;
    }

    public function setIsComparable(?int $isComparable): AttributeDefinitionInterface
    {
        $this->isComparable = $isComparable;
        return $this;
    }

    public function getIsVisibleOnFront(): ?int
    {
        return $this->isVisibleOnFront;
    }

    public function setIsVisibleOnFront(?int $isVisibleOnFront): AttributeDefinitionInterface
    {
        $this->isVisibleOnFront = $isVisibleOnFront;
        return $this;
    }

    public function getIsHtmlAllowedOnFront(): ?int
    {
        return $this->isHtmlAllowedOnFront;
    }

    public function setIsHtmlAllowedOnFront(?int $isHtmlAllowedOnFront): AttributeDefinitionInterface
    {
        $this->isHtmlAllowedOnFront = $isHtmlAllowedOnFront;
        return $this;
    }

    public function getIsWysiwygEnabled(): ?int
    {
        return $this->isWysiwygEnabled;
    }

    public function setIsWysiwygEnabled(?int $isWysiwygEnabled): AttributeDefinitionInterface
    {
        $this->isWysiwygEnabled = $isWysiwygEnabled;
        return $this;
    }

    public function getUsedInProductListing(): ?int
    {
        return $this->usedInProductListing;
    }

    public function setUsedInProductListing(?int $usedInProductListing): AttributeDefinitionInterface
    {
        $this->usedInProductListing = $usedInProductListing;
        return $this;
    }

    public function getUsedForSortBy(): ?int
    {
        return $this->usedForSortBy;
    }

    public function setUsedForSortBy(?int $usedForSortBy): AttributeDefinitionInterface
    {
        $this->usedForSortBy = $usedForSortBy;
        return $this;
    }

    public function getIsVisibleInGrid(): ?int
    {
        return $this->isVisibleInGrid;
    }

    public function setIsVisibleInGrid(?int $isVisibleInGrid): AttributeDefinitionInterface
    {
        $this->isVisibleInGrid = $isVisibleInGrid;
        return $this;
    }

    public function getIsFilterableInGrid(): ?int
    {
        return $this->isFilterableInGrid;
    }

    public function setIsFilterableInGrid(?int $isFilterableInGrid): AttributeDefinitionInterface
    {
        $this->isFilterableInGrid = $isFilterableInGrid;
        return $this;
    }

    public function getIsUsedInGrid(): ?int
    {
        return $this->isUsedInGrid;
    }

    public function setIsUsedInGrid(?int $isUsedInGrid): AttributeDefinitionInterface
    {
        $this->isUsedInGrid = $isUsedInGrid;
        return $this;
    }

    public function getApplyTo(): ?array
    {
        return $this->applyTo;
    }

    public function setApplyTo(?array $applyTo): AttributeDefinitionInterface
    {
        $this->applyTo = $applyTo;
        return $this;
    }

    public function getOptions(): ?array
    {
        return $this->options;
    }

    public function setOptions(?array $options): AttributeDefinitionInterface
    {
        $this->options = $options;
        return $this;
    }

    public function getIsUserDefined(): ?int
    {
        return $this->isUserDefined;
    }

    public function setIsUserDefined(?int $isUserDefined): AttributeDefinitionInterface
    {
        $this->isUserDefined = $isUserDefined;
        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): AttributeDefinitionInterface
    {
        $this->note = $note;
        return $this;
    }

    public function getPlacements(): ?array
    {
        return $this->placements;
    }

    public function setPlacements(?array $placements): AttributeDefinitionInterface
    {
        $this->placements = $placements;
        return $this;
    }
}
