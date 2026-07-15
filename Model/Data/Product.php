<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Data;

use ReadyData\Import\Api\Data\ProductInterface;
use ReadyData\Import\Api\Data\StockDataInterface;

class Product implements ProductInterface
{
    private string $sku = '';
    private ?string $typeId = null;
    private ?string $attributeSet = null;
    private ?string $name = null;
    private ?float $price = null;
    private ?int $status = null;
    private ?int $visibility = null;
    private ?float $weight = null;
    private ?string $urlKey = null;
    private ?array $websites = null;
    private ?StockDataInterface $stock = null;
    private ?array $customAttributes = null;
    private ?array $clearAttributes = null;

    public function getSku(): string
    {
        return $this->sku;
    }

    public function setSku(string $sku): ProductInterface
    {
        $this->sku = $sku;
        return $this;
    }

    public function getTypeId(): ?string
    {
        return $this->typeId;
    }

    public function setTypeId(string $typeId): ProductInterface
    {
        $this->typeId = $typeId;
        return $this;
    }

    public function getAttributeSet(): ?string
    {
        return $this->attributeSet;
    }

    public function setAttributeSet(string $attributeSet): ProductInterface
    {
        $this->attributeSet = $attributeSet;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): ProductInterface
    {
        $this->name = $name;
        return $this;
    }

    public function getPrice(): ?float
    {
        return $this->price;
    }

    public function setPrice(float $price): ProductInterface
    {
        $this->price = $price;
        return $this;
    }

    public function getStatus(): ?int
    {
        return $this->status;
    }

    public function setStatus(int $status): ProductInterface
    {
        $this->status = $status;
        return $this;
    }

    public function getVisibility(): ?int
    {
        return $this->visibility;
    }

    public function setVisibility(int $visibility): ProductInterface
    {
        $this->visibility = $visibility;
        return $this;
    }

    public function getWeight(): ?float
    {
        return $this->weight;
    }

    public function setWeight(float $weight): ProductInterface
    {
        $this->weight = $weight;
        return $this;
    }

    public function getUrlKey(): ?string
    {
        return $this->urlKey;
    }

    public function setUrlKey(string $urlKey): ProductInterface
    {
        $this->urlKey = $urlKey;
        return $this;
    }

    public function getWebsites(): ?array
    {
        return $this->websites;
    }

    public function setWebsites(array $websites): ProductInterface
    {
        $this->websites = $websites;
        return $this;
    }

    public function getStock(): ?StockDataInterface
    {
        return $this->stock;
    }

    public function setStock(StockDataInterface $stock): ProductInterface
    {
        $this->stock = $stock;
        return $this;
    }

    public function getCustomAttributes(): ?array
    {
        return $this->customAttributes;
    }

    public function setCustomAttributes(array $customAttributes): ProductInterface
    {
        $this->customAttributes = $customAttributes;
        return $this;
    }

    public function getClearAttributes(): ?array
    {
        return $this->clearAttributes;
    }

    public function setClearAttributes(array $clearAttributes): ProductInterface
    {
        $this->clearAttributes = $clearAttributes;
        return $this;
    }
}
