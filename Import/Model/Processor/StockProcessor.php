<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Processor;

use ReadyData\Import\Model\BatchContext;
use ReadyData\Import\Model\ResourceModel\Stock;

/**
 * Bulk stock updates: legacy cataloginventory_stock_item always, MSI
 * inventory_source_item when the MSI modules are installed.
 *
 * Salability/reservations recalculation is left to the stock indexer
 * (see InvalidationHandler). Per-source multi-warehouse payloads beyond a
 * single source_code per product are a future expansion.
 */
class StockProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Stock $stockResource
    ) {
    }

    public function process(BatchContext $context): void
    {
        $stockItemRows = [];
        $sourceItemRows = [];

        foreach ($context->getValidProducts() as $sku => $product) {
            $stock = $product->getStock();
            $entityId = $context->getEntityId($sku);
            if ($stock === null || $entityId === null) {
                continue;
            }

            $qty = $stock->getQty();
            $isInStock = $stock->getIsInStock() ?? ($qty > 0);
            $manageStock = $stock->getManageStock();

            $stockItemRows[] = [
                'product_id' => $entityId,
                'qty' => $qty,
                'is_in_stock' => (int)$isInStock,
                'manage_stock' => (int)($manageStock ?? false),
                'use_config_manage_stock' => $manageStock === null ? 1 : 0,
                // Optional fields: a null payload value means "use the store
                // configuration" (use_config_* = 1), mirroring native behavior.
                'min_qty' => $stock->getMinQty() ?? 0,
                'use_config_min_qty' => $stock->getMinQty() === null ? 1 : 0,
                'min_sale_qty' => $stock->getMinSaleQty() ?? 1,
                'use_config_min_sale_qty' => $stock->getMinSaleQty() === null ? 1 : 0,
                'max_sale_qty' => $stock->getMaxSaleQty() ?? 0,
                'use_config_max_sale_qty' => $stock->getMaxSaleQty() === null ? 1 : 0,
                'qty_increments' => $stock->getQtyIncrements() ?? 0,
                'use_config_qty_increments' => $stock->getQtyIncrements() === null ? 1 : 0,
                'enable_qty_increments' => $stock->getQtyIncrements() !== null && $stock->getQtyIncrements() > 0 ? 1 : 0,
                'use_config_enable_qty_inc' => $stock->getQtyIncrements() === null ? 1 : 0,
                'notify_stock_qty' => $stock->getNotifyStockQty() ?? 1,
                'use_config_notify_stock_qty' => $stock->getNotifyStockQty() === null ? 1 : 0,
                'backorders' => $stock->getBackorders() ?? 0,
                'use_config_backorders' => $stock->getBackorders() === null ? 1 : 0,
            ];

            $sourceItemRows[] = [
                'source_code' => $stock->getSourceCode() ?: Stock::DEFAULT_SOURCE_CODE,
                'sku' => (string)$sku,
                'quantity' => $qty,
                'status' => (int)$isInStock,
            ];
        }

        $this->stockResource->upsertStockItems($stockItemRows);
        $this->stockResource->upsertSourceItems($sourceItemRows);
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function getSortOrder(): int
    {
        return 500;
    }
}
