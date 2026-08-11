<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

/**
 * Direct stock writes: legacy cataloginventory_stock_item and, when the
 * MSI modules are installed, inventory_source_item.
 */
class Stock
{
    private const INSERT_CHUNK = 1000;
    public const DEFAULT_STOCK_ID = 1;
    public const DEFAULT_SOURCE_CODE = 'default';

    private ?bool $msiAvailable = null;

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    public function isMsiAvailable(): bool
    {
        if ($this->msiAvailable === null) {
            $connection = $this->resourceConnection->getConnection();
            $this->msiAvailable = $connection->isTableExists(
                $this->resourceConnection->getTableName('inventory_source_item')
            );
        }

        return $this->msiAvailable;
    }

    /**
     * @param array<int, array<string, mixed>> $rows each keyed by stock item
     *        column (product_id, qty, is_in_stock, manage_stock and the
     *        optional field + use_config_* companions)
     */
    public function upsertStockItems(array $rows): void
    {
        if (!$rows) {
            return;
        }

        foreach ($rows as &$row) {
            $row['stock_id'] = self::DEFAULT_STOCK_ID;
            $row['website_id'] = 0;
        }
        unset($row);

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('cataloginventory_stock_item');
        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            $connection->insertOnDuplicate(
                $table,
                $chunk,
                [
                    'qty', 'is_in_stock', 'manage_stock', 'use_config_manage_stock',
                    'min_qty', 'use_config_min_qty',
                    'min_sale_qty', 'use_config_min_sale_qty',
                    'max_sale_qty', 'use_config_max_sale_qty',
                    'qty_increments', 'use_config_qty_increments',
                    'enable_qty_increments', 'use_config_enable_qty_inc',
                    'notify_stock_qty', 'use_config_notify_stock_qty',
                    'backorders', 'use_config_backorders',
                ]
            );
        }
    }

    /**
     * @param array<int, array{source_code: string, sku: string, quantity: float, status: int}> $rows
     */
    public function upsertSourceItems(array $rows): void
    {
        if (!$rows || !$this->isMsiAvailable()) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('inventory_source_item');
        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            $connection->insertOnDuplicate($table, $chunk, ['quantity', 'status']);
        }
    }
}
