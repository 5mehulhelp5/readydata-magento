<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Data;

use ReadyData\Import\Api\Data\StockDataInterface;

class StockData implements StockDataInterface
{
    private float $qty = 0.0;
    private ?bool $isInStock = null;
    private ?string $sourceCode = null;
    private ?bool $manageStock = null;
    private ?float $minQty = null;
    private ?float $minSaleQty = null;
    private ?float $maxSaleQty = null;
    private ?float $qtyIncrements = null;
    private ?float $notifyStockQty = null;
    private ?int $backorders = null;

    public function getQty(): float
    {
        return $this->qty;
    }

    public function setQty(float $qty): StockDataInterface
    {
        $this->qty = $qty;
        return $this;
    }

    public function getIsInStock(): ?bool
    {
        return $this->isInStock;
    }

    public function setIsInStock(bool $isInStock): StockDataInterface
    {
        $this->isInStock = $isInStock;
        return $this;
    }

    public function getSourceCode(): ?string
    {
        return $this->sourceCode;
    }

    public function setSourceCode(string $sourceCode): StockDataInterface
    {
        $this->sourceCode = $sourceCode;
        return $this;
    }

    public function getManageStock(): ?bool
    {
        return $this->manageStock;
    }

    public function setManageStock(bool $manageStock): StockDataInterface
    {
        $this->manageStock = $manageStock;
        return $this;
    }

    public function getMinQty(): ?float
    {
        return $this->minQty;
    }

    public function setMinQty(float $minQty): StockDataInterface
    {
        $this->minQty = $minQty;
        return $this;
    }

    public function getMinSaleQty(): ?float
    {
        return $this->minSaleQty;
    }

    public function setMinSaleQty(float $minSaleQty): StockDataInterface
    {
        $this->minSaleQty = $minSaleQty;
        return $this;
    }

    public function getMaxSaleQty(): ?float
    {
        return $this->maxSaleQty;
    }

    public function setMaxSaleQty(float $maxSaleQty): StockDataInterface
    {
        $this->maxSaleQty = $maxSaleQty;
        return $this;
    }

    public function getQtyIncrements(): ?float
    {
        return $this->qtyIncrements;
    }

    public function setQtyIncrements(float $qtyIncrements): StockDataInterface
    {
        $this->qtyIncrements = $qtyIncrements;
        return $this;
    }

    public function getNotifyStockQty(): ?float
    {
        return $this->notifyStockQty;
    }

    public function setNotifyStockQty(float $notifyStockQty): StockDataInterface
    {
        $this->notifyStockQty = $notifyStockQty;
        return $this;
    }

    public function getBackorders(): ?int
    {
        return $this->backorders;
    }

    public function setBackorders(int $backorders): StockDataInterface
    {
        $this->backorders = $backorders;
        return $this;
    }
}
