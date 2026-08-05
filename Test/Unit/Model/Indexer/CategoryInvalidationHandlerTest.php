<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Test\Unit\Model\Indexer;

use Magento\Catalog\Model\Category;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Indexer\CacheContext;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReadyData\Import\Logger\Logger;
use ReadyData\Import\Model\Config;
use ReadyData\Import\Model\Indexer\CategoryInvalidationHandler;
use ReadyData\Import\Model\ResourceModel\Category as CategoryResource;

class CategoryInvalidationHandlerTest extends TestCase
{
    private Config&MockObject $config;
    private IndexerRegistry&MockObject $indexerRegistry;
    private CacheContext&MockObject $cacheContext;
    private EventManager&MockObject $eventManager;
    private CategoryResource&MockObject $categoryResource;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('getIndexingMode')->willReturn(Config::INDEXING_MODE_PARTIAL);
        $this->config->method('isCleanCache')->willReturn(true);

        $this->indexerRegistry = $this->createMock(IndexerRegistry::class);
        $this->cacheContext = $this->createMock(CacheContext::class);
        $this->eventManager = $this->createMock(EventManager::class);
        $this->categoryResource = $this->createMock(CategoryResource::class);
        $this->categoryResource->method('getDescendantIds')->willReturn([]);
    }

    private function handler(array $indexerIds = ['catalogsearch_fulltext']): CategoryInvalidationHandler
    {
        return new CategoryInvalidationHandler(
            $this->config,
            $this->indexerRegistry,
            $this->cacheContext,
            $this->eventManager,
            $this->categoryResource,
            $this->createMock(Logger::class),
            $indexerIds
        );
    }

    public function testNoCategoriesIsANoOp(): void
    {
        $this->indexerRegistry->expects(self::never())->method('get');
        $this->eventManager->expects(self::never())->method('dispatch');

        $this->handler()->execute([]);
    }

    public function testInvalidatesSearchIndexAndRegistersCacheTags(): void
    {
        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->method('isScheduled')->willReturn(false);
        $indexer->expects(self::once())->method('invalidate');
        $this->indexerRegistry->method('get')->with('catalogsearch_fulltext')->willReturn($indexer);

        $this->cacheContext->expects(self::once())->method('registerEntities')
            ->with(Category::CACHE_TAG, [10, 11]);
        $this->eventManager->expects(self::once())->method('dispatch')->with('clean_cache_by_tags');

        $this->handler()->execute([10, 11, 10]);
    }

    public function testDescendantsAreTaggedBecauseUrlPathCascades(): void
    {
        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->method('isScheduled')->willReturn(true);
        $this->indexerRegistry->method('get')->willReturn($indexer);

        $categoryResource = $this->createMock(CategoryResource::class);
        $categoryResource->method('getDescendantIds')->with([10])->willReturn([11, 12]);
        $this->categoryResource = $categoryResource;

        $this->cacheContext->expects(self::once())->method('registerEntities')
            ->with(Category::CACHE_TAG, [10, 11, 12]);

        $this->handler()->execute([10]);
    }

    public function testScheduledIndexerIsLeftToItsChangelog(): void
    {
        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->method('isScheduled')->willReturn(true);
        $indexer->expects(self::never())->method('invalidate');
        $this->indexerRegistry->method('get')->willReturn($indexer);

        $this->handler()->execute([10]);
    }

    public function testMissingIndexerIsLoggedRatherThanFatal(): void
    {
        $this->indexerRegistry->method('get')
            ->willThrowException(new \InvalidArgumentException('unknown indexer'));

        // Cache work must still happen.
        $this->cacheContext->expects(self::once())->method('registerEntities');

        $this->handler()->execute([10]);
    }

    public function testIndexingModeNoneSkipsInvalidationButStillCleansCache(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getIndexingMode')->willReturn(Config::INDEXING_MODE_NONE);
        $config->method('isCleanCache')->willReturn(true);
        $this->config = $config;

        $this->indexerRegistry->expects(self::never())->method('get');
        $this->cacheContext->expects(self::once())->method('registerEntities');

        $this->handler()->execute([10]);
    }

    public function testCleanCacheDisabledSkipsTagRegistration(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getIndexingMode')->willReturn(Config::INDEXING_MODE_NONE);
        $config->method('isCleanCache')->willReturn(false);
        $this->config = $config;

        $this->cacheContext->expects(self::never())->method('registerEntities');
        $this->eventManager->expects(self::never())->method('dispatch');

        $this->handler()->execute([10]);
    }
}
