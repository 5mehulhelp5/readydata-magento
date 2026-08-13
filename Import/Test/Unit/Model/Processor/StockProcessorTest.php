<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Test\Unit\Model\Processor;

use PHPUnit\Framework\TestCase;
use ReadyData\Import\Model\BatchContext;
use ReadyData\Import\Model\Data\Product;
use ReadyData\Import\Model\Data\StockData;
use ReadyData\Import\Model\Processor\StockProcessor;
use ReadyData\Import\Model\ResourceModel\Stock;

class StockProcessorTest extends TestCase
{
    public function testOptionalFieldsToggleUseConfigFlags(): void
    {
        $stockRows = null;
        $stockResource = $this->createMock(Stock::class);
        $stockResource->method('upsertStockItems')->willReturnCallback(
            function (array $rows) use (&$stockRows): void {
                $stockRows = $rows;
            }
        );

        $stock = (new StockData())->setQty(5.0);
        $stock->setMinQty(2.0);        // set  -> use_config_min_qty = 0
        $stock->setBackorders(1);      // set  -> use_config_backorders = 0
        // min_sale_qty / max_sale_qty / qty_increments / notify_stock_qty left null.

        $product = (new Product())->setSku('SKU-A');
        $product->setStock($stock);

        $context = new BatchContext([$product], 0);
        $context->setEntityId('SKU-A', 42);

        (new StockProcessor($stockResource))->process($context);

        self::assertNotNull($stockRows);
        $row = $stockRows[0];
        self::assertSame(42, $row['product_id']);
        self::assertEqualsWithDelta(2.0, $row['min_qty'], 0.0001);
        self::assertSame(0, $row['use_config_min_qty']);
        self::assertSame(1, $row['backorders']);
        self::assertSame(0, $row['use_config_backorders']);
        // Omitted fields fall back to config.
        self::assertSame(1, $row['use_config_min_sale_qty']);
        self::assertSame(1, $row['use_config_max_sale_qty']);
        self::assertSame(1, $row['use_config_qty_increments']);
        self::assertSame(1, $row['use_config_notify_stock_qty']);
    }
}
