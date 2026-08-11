<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Data;

use ReadyData\Import\Api\Data\CategoryStoreValuesInterface;

class CategoryStoreValues implements CategoryStoreValuesInterface
{
    private ?int $storeId = null;
    private ?string $storeViewCode = null;
    private ?string $name = null;
    private ?string $urlKey = null;
    private ?int $isActive = null;
    private ?int $includeInMenu = null;
    private ?int $isAnchor = null;
    private ?array $customAttributes = null;
    private ?array $clearAttributes = null;

    public function getStoreId(): ?int
    {
        return $this->storeId;
    }

    public function setStoreId(?int $storeId): CategoryStoreValuesInterface
    {
        $this->storeId = $storeId;
        return $this;
    }

    public function getStoreViewCode(): ?string
    {
        return $this->storeViewCode;
    }

    public function setStoreViewCode(?string $storeViewCode): CategoryStoreValuesInterface
    {
        $this->storeViewCode = $storeViewCode;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): CategoryStoreValuesInterface
    {
        $this->name = $name;
        return $this;
    }

    public function getUrlKey(): ?string
    {
        return $this->urlKey;
    }

    public function setUrlKey(?string $urlKey): CategoryStoreValuesInterface
    {
        $this->urlKey = $urlKey;
        return $this;
    }

    public function getIsActive(): ?int
    {
        return $this->isActive;
    }

    public function setIsActive(?int $isActive): CategoryStoreValuesInterface
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getIncludeInMenu(): ?int
    {
        return $this->includeInMenu;
    }

    public function setIncludeInMenu(?int $includeInMenu): CategoryStoreValuesInterface
    {
        $this->includeInMenu = $includeInMenu;
        return $this;
    }

    public function getIsAnchor(): ?int
    {
        return $this->isAnchor;
    }

    public function setIsAnchor(?int $isAnchor): CategoryStoreValuesInterface
    {
        $this->isAnchor = $isAnchor;
        return $this;
    }

    public function getCustomAttributes(): ?array
    {
        return $this->customAttributes;
    }

    public function setCustomAttributes(?array $customAttributes): CategoryStoreValuesInterface
    {
        $this->customAttributes = $customAttributes;
        return $this;
    }

    public function getClearAttributes(): ?array
    {
        return $this->clearAttributes;
    }

    public function setClearAttributes(?array $clearAttributes): CategoryStoreValuesInterface
    {
        $this->clearAttributes = $clearAttributes;
        return $this;
    }
}
