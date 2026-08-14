<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Api\Data;

/**
 * Stock payload for a single product.
 *
 * @api
 */
interface StockDataInterface
{
    public const QTY = 'qty';
    public const IS_IN_STOCK = 'is_in_stock';
    public const SOURCE_CODE = 'source_code';
    public const MANAGE_STOCK = 'manage_stock';
    public const MIN_QTY = 'min_qty';
    public const MIN_SALE_QTY = 'min_sale_qty';
    public const MAX_SALE_QTY = 'max_sale_qty';
    public const QTY_INCREMENTS = 'qty_increments';
    public const NOTIFY_STOCK_QTY = 'notify_stock_qty';
    public const BACKORDERS = 'backorders';

    /**
     * @return float
     */
    public function getQty(): float;

    /**
     * @param float $qty
     * @return $this
     */
    public function setQty(float $qty): self;

    /**
     * @return bool|null
     */
    public function getIsInStock(): ?bool;

    /**
     * @param bool $isInStock
     * @return $this
     */
    public function setIsInStock(bool $isInStock): self;

    /**
     * MSI source code. Defaults to "default".
     *
     * @return string|null
     */
    public function getSourceCode(): ?string;

    /**
     * @param string $sourceCode
     * @return $this
     */
    public function setSourceCode(string $sourceCode): self;

    /**
     * @return bool|null
     */
    public function getManageStock(): ?bool;

    /**
     * @param bool $manageStock
     * @return $this
     */
    public function setManageStock(bool $manageStock): self;

    /**
     * Out-of-stock threshold. Null falls back to the store configuration.
     *
     * @return float|null
     */
    public function getMinQty(): ?float;

    /**
     * @param float $minQty
     * @return $this
     */
    public function setMinQty(float $minQty): self;

    /**
     * Minimum qty allowed in a shopping cart. Null falls back to config.
     *
     * @return float|null
     */
    public function getMinSaleQty(): ?float;

    /**
     * @param float $minSaleQty
     * @return $this
     */
    public function setMinSaleQty(float $minSaleQty): self;

    /**
     * Maximum qty allowed in a shopping cart. Null falls back to config.
     *
     * @return float|null
     */
    public function getMaxSaleQty(): ?float;

    /**
     * @param float $maxSaleQty
     * @return $this
     */
    public function setMaxSaleQty(float $maxSaleQty): self;

    /**
     * Qty increments. Null falls back to config.
     *
     * @return float|null
     */
    public function getQtyIncrements(): ?float;

    /**
     * @param float $qtyIncrements
     * @return $this
     */
    public function setQtyIncrements(float $qtyIncrements): self;

    /**
     * Notify-for-quantity-below threshold. Null falls back to config.
     *
     * @return float|null
     */
    public function getNotifyStockQty(): ?float;

    /**
     * @param float $notifyStockQty
     * @return $this
     */
    public function setNotifyStockQty(float $notifyStockQty): self;

    /**
     * Backorders mode (0 no, 1 allow, 2 allow+notify). Null falls back to config.
     *
     * @return int|null
     */
    public function getBackorders(): ?int;

    /**
     * @param int $backorders
     * @return $this
     */
    public function setBackorders(int $backorders): self;
}
