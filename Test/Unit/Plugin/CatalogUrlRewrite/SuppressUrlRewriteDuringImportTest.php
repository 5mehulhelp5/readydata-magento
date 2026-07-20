<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Test\Unit\Plugin\CatalogUrlRewrite;

use Magento\CatalogUrlRewrite\Observer\ProductProcessUrlRewriteSavingObserver;
use Magento\Framework\Event\Observer as EventObserver;
use PHPUnit\Framework\TestCase;
use ReadyData\Import\Model\ImportState;
use ReadyData\Import\Plugin\CatalogUrlRewrite\SuppressUrlRewriteDuringImport;

class SuppressUrlRewriteDuringImportTest extends TestCase
{
    public function testProceedSkippedWhileImporting(): void
    {
        $state = new ImportState();
        $state->enter();
        $plugin = new SuppressUrlRewriteDuringImport($state);

        $called = false;
        $plugin->aroundExecute(
            $this->createMock(ProductProcessUrlRewriteSavingObserver::class),
            static function () use (&$called): void {
                $called = true;
            },
            $this->createMock(EventObserver::class)
        );

        self::assertFalse($called, 'Native observer must not run during an import.');
    }

    public function testProceedCalledOutsideImport(): void
    {
        $plugin = new SuppressUrlRewriteDuringImport(new ImportState());

        $called = false;
        $plugin->aroundExecute(
            $this->createMock(ProductProcessUrlRewriteSavingObserver::class),
            static function () use (&$called): void {
                $called = true;
            },
            $this->createMock(EventObserver::class)
        );

        self::assertTrue($called, 'Native observer must run outside imports.');
    }
}
