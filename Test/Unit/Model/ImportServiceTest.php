<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Test\Unit\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Lock\LockManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReadyData\Import\Api\Data\ImportResponseInterfaceFactory;
use ReadyData\Import\Api\Data\ImportResultInterface;
use ReadyData\Import\Api\Data\ImportResultInterfaceFactory;
use ReadyData\Import\Logger\Logger;
use ReadyData\Import\Model\BatchContext;
use ReadyData\Import\Model\BatchContextFactory;
use ReadyData\Import\Model\Cache\StoreWebsiteMap;
use ReadyData\Import\Model\Config;
use ReadyData\Import\Model\Data\ImportResponse;
use ReadyData\Import\Model\Data\ImportResult;
use ReadyData\Import\Model\Data\Product;
use ReadyData\Import\Model\Event\ImportEventDispatcher;
use ReadyData\Import\Model\ImportService;
use ReadyData\Import\Model\ImportState;
use ReadyData\Import\Model\Indexer\InvalidationHandler;
use ReadyData\Import\Model\Processor\PreparableInterface;
use ReadyData\Import\Model\Processor\ProcessorInterface;

/**
 * Covers the pre-transaction phase contract; the pipeline itself is exercised by
 * the individual processor tests.
 */
class ImportServiceTest extends TestCase
{
    private AdapterInterface&MockObject $connection;
    private LockManagerInterface&MockObject $lockManager;
    private InvalidationHandler&MockObject $invalidationHandler;
    private Logger&MockObject $logger;

    /** @var string[] ordered log of the calls the tests care about */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->calls = [];

        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('beginTransaction')->willReturnCallback(function (): AdapterInterface {
            $this->calls[] = 'beginTransaction';
            return $this->connection;
        });
        $this->connection->method('commit')->willReturnCallback(function (): AdapterInterface {
            $this->calls[] = 'commit';
            return $this->connection;
        });
        $this->connection->method('fetchOne')->willReturn('1');

        $this->lockManager = $this->createMock(LockManagerInterface::class);
        $this->lockManager->method('lock')->willReturn(true);
        $this->lockManager->method('isLocked')->willReturn(true);

        $this->invalidationHandler = $this->createMock(InvalidationHandler::class);
        $this->logger = $this->createMock(Logger::class);
    }

    public function testPreparationRunsBeforeTheTransactionOpens(): void
    {
        $processor = $this->preparable(710);

        $this->serviceWith([$processor])->import([$this->product('P1')]);

        self::assertSame(['prepare:710', 'beginTransaction', 'process:710', 'commit'], $this->calls);
    }

    public function testPreparablesRunInSortOrder(): void
    {
        $service = $this->serviceWith([$this->preparable(900), $this->preparable(710)]);

        $service->import([$this->product('P1')]);

        self::assertSame(
            ['prepare:710', 'prepare:900', 'beginTransaction', 'process:710', 'process:900', 'commit'],
            $this->calls
        );
    }

    public function testDisabledPreparableIsNeitherPreparedNorRun(): void
    {
        $processor = $this->preparable(710, enabled: false);
        $processor->expects(self::never())->method('prepare');
        $processor->expects(self::never())->method('process');

        $this->serviceWith([$processor])->import([$this->product('P1')]);

        self::assertSame(['beginTransaction', 'commit'], $this->calls);
    }

    public function testPlainProcessorIsNotPrepared(): void
    {
        $processor = $this->createMock(ProcessorInterface::class);
        $processor->method('isEnabled')->willReturn(true);
        $processor->method('getSortOrder')->willReturn(710);
        $processor->expects(self::once())->method('process');

        $this->serviceWith([$processor])->import([$this->product('P1')]);

        self::assertSame(['beginTransaction', 'commit'], $this->calls);
    }

    public function testThrowingPreparationFailsTheBatchWithoutOpeningOrRollingBackAnything(): void
    {
        $processor = $this->preparable(710);
        $processor->method('prepare')->willThrowException(new \RuntimeException('the CDN is unreachable'));
        $processor->expects(self::never())->method('process');

        $this->connection->expects(self::never())->method('beginTransaction');
        // Rolling back a transaction that was never opened would raise a second,
        // misleading error on top of the real one.
        $this->connection->expects(self::never())->method('rollBack');
        $this->logger->expects(self::once())->method('error');

        $response = $this->serviceWith([$processor])->import([$this->product('P1')]);

        self::assertSame(1, $response->getReceived());
        self::assertSame(1, $response->getFailed());
        self::assertSame(0, $response->getCreated());
        $result = $response->getResults()[0];
        self::assertSame(ImportResultInterface::STATUS_ERROR, $result->getStatus());
        self::assertStringContainsString('preparation failed', $result->getMessages()[0]);
        self::assertStringContainsString('the CDN is unreachable', $result->getMessages()[0]);
    }

    public function testLosingTheImportLockDuringPreparationFailsTheBatch(): void
    {
        // The lock is a GET_LOCK on the import's own connection, so a reconnect
        // after a long download phase silently releases it.
        $this->lockManager = $this->createMock(LockManagerInterface::class);
        $this->lockManager->method('lock')->willReturn(true);
        $this->lockManager->method('isLocked')->willReturn(false);

        $this->connection->expects(self::never())->method('beginTransaction');

        $response = $this->serviceWith([$this->preparable(710)])->import([$this->product('P1')]);

        self::assertSame(1, $response->getFailed());
        self::assertStringContainsString(
            'the import lock was lost',
            $response->getResults()[0]->getMessages()[0]
        );
    }

    public function testPreparationIsSkippedEntirelyWhenNoProcessorNeedsIt(): void
    {
        $processor = $this->createMock(ProcessorInterface::class);
        $processor->method('isEnabled')->willReturn(true);
        $processor->method('getSortOrder')->willReturn(710);

        // No liveness probe either: nothing idled the connection.
        $this->connection->expects(self::never())->method('fetchOne');
        $this->lockManager->expects(self::never())->method('isLocked');

        $this->serviceWith([$processor])->import([$this->product('P1')]);
    }

    /**
     * @return PreparableInterface&ProcessorInterface&MockObject
     */
    private function preparable(int $sortOrder, bool $enabled = true): MockObject
    {
        // One mock has to satisfy both interfaces, so it is built from a stub
        // class that declares them together.
        $processor = $this->createMock(PreparableProcessorStub::class);
        $processor->method('isEnabled')->willReturn($enabled);
        $processor->method('getSortOrder')->willReturn($sortOrder);
        $processor->method('prepare')->willReturnCallback(function () use ($sortOrder): void {
            $this->calls[] = 'prepare:' . $sortOrder;
        });
        $processor->method('process')->willReturnCallback(function () use ($sortOrder): void {
            $this->calls[] = 'process:' . $sortOrder;
        });

        return $processor;
    }

    /**
     * @param ProcessorInterface[] $processors
     */
    private function serviceWith(array $processors): ImportService
    {
        $config = $this->createMock(Config::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('getBatchSize')->willReturn(500);
        $config->method('isContinueOnError')->willReturn(true);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($this->connection);

        $batchContextFactory = $this->createMock(BatchContextFactory::class);
        $batchContextFactory->method('create')->willReturnCallback(
            static fn (array $data): BatchContext => new BatchContext($data['products'], $data['storeId'])
        );

        $storeWebsiteMap = $this->createMock(StoreWebsiteMap::class);
        $storeWebsiteMap->method('resolveScopeStoreId')->willReturn(0);

        $responseFactory = $this->createMock(ImportResponseInterfaceFactory::class);
        $responseFactory->method('create')->willReturnCallback(static fn (): ImportResponse => new ImportResponse());
        $resultFactory = $this->createMock(ImportResultInterfaceFactory::class);
        $resultFactory->method('create')->willReturnCallback(static fn (): ImportResult => new ImportResult());

        return new ImportService(
            $config,
            $resourceConnection,
            $this->lockManager,
            $batchContextFactory,
            $storeWebsiteMap,
            $this->invalidationHandler,
            $this->createMock(ImportEventDispatcher::class),
            $this->createMock(ImportState::class),
            $responseFactory,
            $resultFactory,
            $this->logger,
            $processors
        );
    }

    private function product(string $sku): Product
    {
        return (new Product())->setSku($sku);
    }
}

/**
 * Only exists so a single mock can stand in for a pipeline step that also opts
 * into the pre-transaction phase.
 */
abstract class PreparableProcessorStub implements ProcessorInterface, PreparableInterface
{
}
