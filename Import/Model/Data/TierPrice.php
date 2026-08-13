<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Data;

use ReadyData\Import\Api\Data\TierPriceInterface;

class TierPrice implements TierPriceInterface
{
    private string $customerGroup = '';
    private float $qty = 0.0;
    private ?float $price = null;
    private ?float $percentageDiscount = null;
    private ?string $website = null;

    public function getCustomerGroup(): string
    {
        return $this->customerGroup;
    }

    public function setCustomerGroup(string $customerGroup): TierPriceInterface
    {
        $this->customerGroup = $customerGroup;
        return $this;
    }

    public function getQty(): float
    {
        return $this->qty;
    }

    public function setQty(float $qty): TierPriceInterface
    {
        $this->qty = $qty;
        return $this;
    }

    public function getPrice(): ?float
    {
        return $this->price;
    }

    public function setPrice(float $price): TierPriceInterface
    {
        $this->price = $price;
        return $this;
    }

    public function getPercentageDiscount(): ?float
    {
        return $this->percentageDiscount;
    }

    public function setPercentageDiscount(float $percentageDiscount): TierPriceInterface
    {
        $this->percentageDiscount = $percentageDiscount;
        return $this;
    }

    public function getWebsite(): ?string
    {
        return $this->website;
    }

    public function setWebsite(string $website): TierPriceInterface
    {
        $this->website = $website;
        return $this;
    }
}
