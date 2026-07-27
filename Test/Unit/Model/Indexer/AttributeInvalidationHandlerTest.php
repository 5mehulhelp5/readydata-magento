<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Test\Unit\Model\Indexer;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReadyData\Import\Logger\Logger;
use ReadyData\Import\Model\Indexer\AttributeInvalidationHandler;

class AttributeInvalidationHandlerTest extends TestCase
{
    private TypeListInterface&MockObject $cacheTypeList;
    private IndexerRegistry&MockObject $indexerRegistry;

    protected function setUp(): void
    {
        $this->cacheTypeList = $this->createMock(TypeListInterface::class);
        $this->indexerRegistry = $this->createMock(IndexerRegistry::class);
    }

    public function testNoChangeDoesNothing(): void
    {
        $this->cacheTypeList->expects(self::never())->method('invalidate');
        $this->indexerRegistry->expects(self::never())->method('get');

        $this->handler()->execute(false);
    }

    public function testChangeCleansCachesAndInvalidatesIndexers(): void
    {
        $invalidatedCaches = [];
        $this->cacheTypeList->method('invalidate')
            ->willReturnCallback(function (string $type) use (&$invalidatedCaches): void {
                $invalidatedCaches[] = $type;
            });

        $invalidatedIndexers = [];
        $this->indexerRegistry->method('get')->willReturnCallback(
            function (string $id) use (&$invalidatedIndexers): IndexerInterface {
                $indexer = $this->createMock(IndexerInterface::class);
                $indexer->expects(self::once())->method('invalidate')
                    ->willReturnCallback(static function () use (&$invalidatedIndexers, $id): void {
                        $invalidatedIndexers[] = $id;
                    });
                return $indexer;
            }
        );

        $this->handler(['eav', 'full_page'], ['catalog_product_attribute', 'catalog_product_flat'])
            ->execute(true);

        self::assertSame(['eav', 'full_page'], $invalidatedCaches);
        self::assertSame(['catalog_product_attribute', 'catalog_product_flat'], $invalidatedIndexers);
    }

    public function testMissingIndexerIsSwallowed(): void
    {
        $this->indexerRegistry->method('get')
            ->willThrowException(new \InvalidArgumentException('indexer does not exist'));

        // No exception should escape.
        $this->handler(['eav'], ['catalog_product_flat'])->execute(true);
        $this->addToAssertionCount(1);
    }

    /**
     * @param string[] $cacheTypes
     * @param string[] $indexerIds
     */
    private function handler(array $cacheTypes = ['eav'], array $indexerIds = ['catalog_product_attribute']): AttributeInvalidationHandler
    {
        return new AttributeInvalidationHandler(
            $this->cacheTypeList,
            $this->indexerRegistry,
            $this->createMock(Logger::class),
            $cacheTypes,
            $indexerIds
        );
    }
}
