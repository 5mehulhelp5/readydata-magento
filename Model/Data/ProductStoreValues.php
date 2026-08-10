<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Data;

use ReadyData\Import\Api\Data\ProductStoreValuesInterface;

class ProductStoreValues implements ProductStoreValuesInterface
{
    private ?int $storeId = null;
    private ?string $storeViewCode = null;
    private ?string $name = null;
    private ?float $price = null;
    private ?int $status = null;
    private ?int $visibility = null;
    private ?float $weight = null;
    private ?string $urlKey = null;
    private ?array $customAttributes = null;
    private ?array $clearAttributes = null;

    public function getStoreId(): ?int
    {
        return $this->storeId;
    }

    public function setStoreId(int $storeId): ProductStoreValuesInterface
    {
        $this->storeId = $storeId;
        return $this;
    }

    public function getStoreViewCode(): ?string
    {
        return $this->storeViewCode;
    }

    public function setStoreViewCode(string $storeViewCode): ProductStoreValuesInterface
    {
        $this->storeViewCode = $storeViewCode;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): ProductStoreValuesInterface
    {
        $this->name = $name;
        return $this;
    }

    public function getPrice(): ?float
    {
        return $this->price;
    }

    public function setPrice(float $price): ProductStoreValuesInterface
    {
        $this->price = $price;
        return $this;
    }

    public function getStatus(): ?int
    {
        return $this->status;
    }

    public function setStatus(int $status): ProductStoreValuesInterface
    {
        $this->status = $status;
        return $this;
    }

    public function getVisibility(): ?int
    {
        return $this->visibility;
    }

    public function setVisibility(int $visibility): ProductStoreValuesInterface
    {
        $this->visibility = $visibility;
        return $this;
    }

    public function getWeight(): ?float
    {
        return $this->weight;
    }

    public function setWeight(float $weight): ProductStoreValuesInterface
    {
        $this->weight = $weight;
        return $this;
    }

    public function getUrlKey(): ?string
    {
        return $this->urlKey;
    }

    public function setUrlKey(string $urlKey): ProductStoreValuesInterface
    {
        $this->urlKey = $urlKey;
        return $this;
    }

    public function getCustomAttributes(): ?array
    {
        return $this->customAttributes;
    }

    public function setCustomAttributes(array $customAttributes): ProductStoreValuesInterface
    {
        $this->customAttributes = $customAttributes;
        return $this;
    }

    public function getClearAttributes(): ?array
    {
        return $this->clearAttributes;
    }

    public function setClearAttributes(array $clearAttributes): ProductStoreValuesInterface
    {
        $this->clearAttributes = $clearAttributes;
        return $this;
    }
}
