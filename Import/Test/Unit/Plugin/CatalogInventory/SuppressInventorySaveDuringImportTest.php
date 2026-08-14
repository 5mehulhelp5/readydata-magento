<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Test\Unit\Plugin\CatalogInventory;

use Magento\CatalogInventory\Observer\SaveInventoryDataObserver;
use Magento\Framework\Event\Observer as EventObserver;
use PHPUnit\Framework\TestCase;
use ReadyData\Import\Model\ImportState;
use ReadyData\Import\Plugin\CatalogInventory\SuppressInventorySaveDuringImport;

class SuppressInventorySaveDuringImportTest extends TestCase
{
    public function testProceedSkippedWhileImporting(): void
    {
        $state = new ImportState();
        $state->enter();
        $plugin = new SuppressInventorySaveDuringImport($state);

        $called = false;
        $plugin->aroundExecute(
            $this->createMock(SaveInventoryDataObserver::class),
            static function () use (&$called): void {
                $called = true;
            },
            $this->createMock(EventObserver::class)
        );

        self::assertFalse($called, 'Native observer must not run during an import.');
    }

    public function testProceedCalledOutsideImport(): void
    {
        $plugin = new SuppressInventorySaveDuringImport(new ImportState());

        $called = false;
        $plugin->aroundExecute(
            $this->createMock(SaveInventoryDataObserver::class),
            static function () use (&$called): void {
                $called = true;
            },
            $this->createMock(EventObserver::class)
        );

        self::assertTrue($called, 'Native observer must run outside imports.');
    }
}
