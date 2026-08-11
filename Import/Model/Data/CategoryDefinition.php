<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Data;

use ReadyData\Import\Api\Data\CategoryDefinitionInterface;

class CategoryDefinition implements CategoryDefinitionInterface
{
    private ?string $path = null;
    private ?int $categoryId = null;
    private ?int $rootCategoryId = null;
    private ?string $parentPath = null;
    private ?int $parentCategoryId = null;
    private ?string $name = null;
    private ?string $urlKey = null;
    private ?int $isActive = null;
    private ?int $includeInMenu = null;
    private ?int $isAnchor = null;
    private ?int $position = null;
    private ?array $customAttributes = null;
    private ?array $clearAttributes = null;
    private ?int $delete = null;
    private ?int $deleteChildren = null;
    private ?array $storeValues = null;

    public function getPath(): ?string
    {
        return $this->path;
    }

    public function setPath(?string $path): CategoryDefinitionInterface
    {
        $this->path = $path;
        return $this;
    }

    public function getCategoryId(): ?int
    {
        return $this->categoryId;
    }

    public function setCategoryId(?int $categoryId): CategoryDefinitionInterface
    {
        $this->categoryId = $categoryId;
        return $this;
    }

    public function getRootCategoryId(): ?int
    {
        return $this->rootCategoryId;
    }

    public function setRootCategoryId(?int $rootCategoryId): CategoryDefinitionInterface
    {
        $this->rootCategoryId = $rootCategoryId;
        return $this;
    }

    public function getParentPath(): ?string
    {
        return $this->parentPath;
    }

    public function setParentPath(?string $parentPath): CategoryDefinitionInterface
    {
        $this->parentPath = $parentPath;
        return $this;
    }

    public function getParentCategoryId(): ?int
    {
        return $this->parentCategoryId;
    }

    public function setParentCategoryId(?int $parentCategoryId): CategoryDefinitionInterface
    {
        $this->parentCategoryId = $parentCategoryId;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): CategoryDefinitionInterface
    {
        $this->name = $name;
        return $this;
    }

    public function getUrlKey(): ?string
    {
        return $this->urlKey;
    }

    public function setUrlKey(?string $urlKey): CategoryDefinitionInterface
    {
        $this->urlKey = $urlKey;
        return $this;
    }

    public function getIsActive(): ?int
    {
        return $this->isActive;
    }

    public function setIsActive(?int $isActive): CategoryDefinitionInterface
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getIncludeInMenu(): ?int
    {
        return $this->includeInMenu;
    }

    public function setIncludeInMenu(?int $includeInMenu): CategoryDefinitionInterface
    {
        $this->includeInMenu = $includeInMenu;
        return $this;
    }

    public function getIsAnchor(): ?int
    {
        return $this->isAnchor;
    }

    public function setIsAnchor(?int $isAnchor): CategoryDefinitionInterface
    {
        $this->isAnchor = $isAnchor;
        return $this;
    }

    public function getPosition(): ?int
    {
        return $this->position;
    }

    public function setPosition(?int $position): CategoryDefinitionInterface
    {
        $this->position = $position;
        return $this;
    }

    public function getCustomAttributes(): ?array
    {
        return $this->customAttributes;
    }

    public function setCustomAttributes(?array $customAttributes): CategoryDefinitionInterface
    {
        $this->customAttributes = $customAttributes;
        return $this;
    }

    public function getClearAttributes(): ?array
    {
        return $this->clearAttributes;
    }

    public function setClearAttributes(?array $clearAttributes): CategoryDefinitionInterface
    {
        $this->clearAttributes = $clearAttributes;
        return $this;
    }

    public function getDelete(): ?int
    {
        return $this->delete;
    }

    public function setDelete(?int $delete): CategoryDefinitionInterface
    {
        $this->delete = $delete;
        return $this;
    }

    public function getDeleteChildren(): ?int
    {
        return $this->deleteChildren;
    }

    public function setDeleteChildren(?int $deleteChildren): CategoryDefinitionInterface
    {
        $this->deleteChildren = $deleteChildren;
        return $this;
    }

    public function getStoreValues(): ?array
    {
        return $this->storeValues;
    }

    public function setStoreValues(?array $storeValues): CategoryDefinitionInterface
    {
        $this->storeValues = $storeValues;
        return $this;
    }
}
