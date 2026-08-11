<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Test\Unit\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReadyData\Import\Api\Data\ImportResponseInterfaceFactory;
use ReadyData\Import\Api\Data\ImportResultInterface;
use ReadyData\Import\Api\Data\ImportResultInterfaceFactory;
use ReadyData\Import\Api\Data\StoreResultInterface;
use ReadyData\Import\Api\Data\StoreResultInterfaceFactory;
use ReadyData\Import\Logger\Logger;
use ReadyData\Import\Model\BatchContext;
use ReadyData\Import\Model\BatchContextFactory;
use ReadyData\Import\Model\Cache\StoreWebsiteMap;
use ReadyData\Import\Model\Config;
use ReadyData\Import\Model\Data\CustomAttribute;
use ReadyData\Import\Model\Data\ImportResponse;
use ReadyData\Import\Model\Data\ImportResult;
use ReadyData\Import\Model\Data\Product;
use ReadyData\Import\Model\Data\ProductStoreValues;
use ReadyData\Import\Model\Data\StoreResult;
use ReadyData\Import\Model\Event\ImportEventDispatcher;
use ReadyData\Import\Model\Exception\ImportLockedException;
use ReadyData\Import\Model\ImportLocks;
use ReadyData\Import\Model\ImportService;
use ReadyData\Import\Model\ImportState;
use ReadyData\Import\Model\Indexer\InvalidationHandler;
use ReadyData\Import\Model\Processor\PreparableInterface;
use ReadyData\Import\Model\Processor\ProcessorInterface;
use ReadyData\Import\Model\ResourceModel\AttributeOption;
use ReadyData\Import\Model\ResourceModel\ProductEntity;

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

    /**
     * @var string[] SKUs the catalog already holds. The lock decision reads this,
     *      because an insert of an unknown SKU is itself a race.
     */
    private array $existingSkus = [];

    /** @var string[] lock names another request is holding, so lock() refuses them */
    private array $blockedLocks = [];

    /** Which create() call the batch context factory should throw on, 1-based. */
    private ?int $contextFactoryFailsOnCall = null;

    protected function setUp(): void
    {
        $this->calls = [];
        $this->existingSkus = ['P1'];
        $this->blockedLocks = [];
        $this->contextFactoryFailsOnCall = null;

        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('beginTransaction')->willReturnCallback(function (): AdapterInterface {
            $this->calls[] = 'beginTransaction';
            return $this->connection;
        });
        $this->connection->method('commit')->willReturnCallback(function (): AdapterInterface {
            $this->calls[] = 'commit';
            return $this->connection;
        });
        $this->connection->method('rollBack')->willReturnCallback(function (): AdapterInterface {
            $this->calls[] = 'rollBack';
            return $this->connection;
        });
        $this->connection->method('fetchOne')->willReturn('1');

        $this->lockManager = $this->createMock(LockManagerInterface::class);
        $this->lockManager->method('lock')->willReturnCallback(function (string $name): bool {
            if (in_array($name, $this->blockedLocks, true)) {
                $this->calls[] = 'lock-refused:' . $name;

                return false;
            }
            $this->calls[] = 'lock:' . $name;

            return true;
        });
        $this->lockManager->method('unlock')->willReturnCallback(function (string $name): bool {
            $this->calls[] = 'unlock:' . $name;

            return true;
        });

        $this->invalidationHandler = $this->createMock(InvalidationHandler::class);
        $this->invalidationHandler->method('execute')->willReturnCallback(function (): void {
            $this->calls[] = 'invalidate';
        });
        $this->logger = $this->createMock(Logger::class);
    }

    /**
     * Just the lock traffic from the call log, so a test can assert what was
     * taken, in which order, and when it went back.
     *
     * @return string[]
     */
    private function lockCalls(): array
    {
        return array_values(array_filter(
            $this->calls,
            static fn (string $call): bool => str_starts_with($call, 'lock') || str_starts_with($call, 'unlock')
        ));
    }

    public function testPreparationRunsBeforeTheTransactionOpens(): void
    {
        $processor = $this->preparable(710);

        $this->serviceWith([$processor])->import([$this->product('P1')]);

        self::assertSame(
            ['prepare:710', 'beginTransaction', 'process:710', 'commit', 'invalidate'],
            $this->calls
        );
    }

    public function testPreparablesRunInSortOrder(): void
    {
        $service = $this->serviceWith([$this->preparable(900), $this->preparable(710)]);

        $service->import([$this->product('P1')]);

        self::assertSame(
            [
                'prepare:710',
                'prepare:900',
                'beginTransaction',
                'process:710',
                'process:900',
                'commit',
                'invalidate',
            ],
            $this->calls
        );
    }

    public function testDisabledPreparableIsNeitherPreparedNorRun(): void
    {
        $processor = $this->preparable(710, enabled: false);
        $processor->expects(self::never())->method('prepare');
        $processor->expects(self::never())->method('process');

        $this->serviceWith([$processor])->import([$this->product('P1')]);

        self::assertSame(['beginTransaction', 'commit', 'invalidate'], $this->calls);
    }

    public function testPlainProcessorIsNotPrepared(): void
    {
        $processor = $this->createMock(ProcessorInterface::class);
        $processor->method('isEnabled')->willReturn(true);
        $processor->method('getSortOrder')->willReturn(710);
        $processor->expects(self::once())->method('process');

        $this->serviceWith([$processor])->import([$this->product('P1')]);

        self::assertSame(['beginTransaction', 'commit', 'invalidate'], $this->calls);
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

    public function testPreparationIsSkippedEntirelyWhenNoProcessorNeedsIt(): void
    {
        $processor = $this->createMock(ProcessorInterface::class);
        $processor->method('isEnabled')->willReturn(true);
        $processor->method('getSortOrder')->willReturn(710);

        // No liveness probe either: nothing idled the connection.
        $this->connection->expects(self::never())->method('fetchOne');

        $this->serviceWith([$processor])->import([$this->product('P1')]);
    }

    /**
     * The locks are taken AFTER the download phase and given back as soon as the
     * transaction resolves. That ordering is the whole point: a competing import
     * waits for one transaction, not for a feed's worth of image downloads, and
     * not for the reindex that follows.
     */
    public function testLocksAreHeldOnlyForTheTransactionNeverAcrossDownloadsOrIndexing(): void
    {
        $this->serviceWith([$this->preparable(710)])
            ->import([$this->product('P1')->setCategories([])]);

        self::assertSame(
            [
                'prepare:710',
                'lock:' . ImportLocks::CATEGORY_TREE,
                'beginTransaction',
                'process:710',
                'commit',
                'unlock:' . ImportLocks::CATEGORY_TREE,
                'invalidate',
            ],
            $this->calls
        );
    }

    /**
     * The locks are also released before the after-commit events: an observer is
     * someone else's code doing an unknown amount of work, and by then the rows
     * are committed and visible, so there is nothing left to protect.
     */
    public function testLocksAreReleasedBeforeTheAfterCommitEvents(): void
    {
        $dispatcher = $this->createMock(ImportEventDispatcher::class);
        $dispatcher->method('dispatchAfterCommit')->willReturnCallback(function (): void {
            $this->calls[] = 'afterCommitEvents';
        });

        $this->serviceWith([], dispatcher: $dispatcher)->import([$this->product('P1')->setCategories([])]);

        self::assertSame(
            [
                'lock:' . ImportLocks::CATEGORY_TREE,
                'beginTransaction',
                'commit',
                'unlock:' . ImportLocks::CATEGORY_TREE,
                'afterCommitEvents',
                'invalidate',
            ],
            $this->calls
        );
    }

    /**
     * A batch that can reach no read-then-create has nothing to serialize
     * against and should not pay for it — a price refresh over products that
     * already exist, typically.
     */
    public function testAPayloadThatCanCreateNothingUnkeyedRunsWithoutAnyLock(): void
    {
        $this->serviceWith([])->import([$this->product('P1')->setPrice(9.99)]);

        self::assertSame([], $this->lockCalls());
    }

    /**
     * catalog_product_entity.sku carries a plain index, not a unique key —
     * Magento enforces SKU uniqueness in PHP. Two concurrent runs naming the
     * same new SKU both miss the read and both insert, and no payload field
     * reveals it: only the database can say whether the row is already there.
     */
    public function testAnUnknownSkuTakesTheProductCreateLockAndNothingElse(): void
    {
        $this->existingSkus = [];

        $this->serviceWith([])->import([$this->product('P1')->setPrice(9.99)]);

        self::assertSame(
            ['lock:' . ImportLocks::PRODUCT_CREATE, 'unlock:' . ImportLocks::PRODUCT_CREATE],
            $this->lockCalls()
        );
    }

    /**
     * catalog_product_entity_media_gallery has no key on (attribute_id, value),
     * so two concurrent runs carrying the same file for one product both insert
     * it. `[]` counts too: it means "remove everything", which is still work.
     *
     * That it takes the gallery lock ALONE is the point of splitting the names: a
     * media feed and a category sync cannot duplicate each other's work, so one
     * lock for both would serialize them for nothing.
     */
    public function testAMediaFieldTakesTheGalleryLockAndNothingElse(): void
    {
        $this->serviceWith([])->import([$this->product('P1')->setMedia([])]);

        self::assertSame(
            ['lock:' . ImportLocks::MEDIA_GALLERY, 'unlock:' . ImportLocks::MEDIA_GALLERY],
            $this->lockCalls()
        );
    }

    public function testAMediaFieldRunsLockFreeWhenTheMediaStepIsDisabled(): void
    {
        // A disabled media importer inserts nothing, so nothing can race.
        $this->serviceWith([], mediaEnabled: false)->import([$this->product('P1')->setMedia([])]);

        self::assertSame([], $this->lockCalls());
    }

    public function testACategoriesFieldTakesTheTreeLockAndNothingElse(): void
    {
        // Presence is the test, not whether any entry turns out to need
        // creating — knowing that means resolving the tree, which is the work
        // the lock protects.
        $this->serviceWith([])->import([$this->product('P1')->setCategories([])]);

        self::assertSame(
            ['lock:' . ImportLocks::CATEGORY_TREE, 'unlock:' . ImportLocks::CATEGORY_TREE],
            $this->lockCalls()
        );
    }

    /**
     * eav_attribute_option has no key on the label at all, so two concurrent
     * imports writing the same new option label both miss and both insert. The
     * attribute endpoint takes this same name, which is what closes that race
     * across the two endpoints.
     */
    public function testCustomAttributesTakeTheOptionLockWhenAutoCreationIsOn(): void
    {
        $product = $this->product('P1')->setCustomAttributes([
            (new CustomAttribute())->setAttributeCode('color')->setValue('Red'),
        ]);

        $this->serviceWith([], createMissingOptions: true)->import([$product]);

        self::assertSame(
            ['lock:' . ImportLocks::ATTRIBUTE_OPTIONS, 'unlock:' . ImportLocks::ATTRIBUTE_OPTIONS],
            $this->lockCalls()
        );
    }

    public function testCustomAttributesRunLockFreeWhenOptionAutoCreationIsOff(): void
    {
        // Nothing can be created, so nothing can race.
        $product = $this->product('P1')->setCustomAttributes([
            (new CustomAttribute())->setAttributeCode('color')->setValue('Red'),
        ]);

        $this->serviceWith([], createMissingOptions: false)->import([$product]);

        self::assertSame([], $this->lockCalls());
    }

    /**
     * A scoped block's custom attributes only ever resolve option labels they
     * did not create — AttributeProcessor harvests labels from the product's own
     * custom attributes alone — so locking for them buys nothing.
     */
    public function testCustomAttributesInsideAStoreValuesBlockDoNotTakeTheLock(): void
    {
        $product = $this->product('P1')->setStoreValues([
            (new ProductStoreValues())->setStoreId(3)->setCustomAttributes([
                (new CustomAttribute())->setAttributeCode('color')->setValue('Rot'),
            ]),
        ]);

        $this->serviceWith([], createMissingOptions: true)->import([$product]);

        self::assertSame([], $this->lockCalls());
    }

    public function testALockFreePayloadStillProbesTheConnection(): void
    {
        $this->connection->expects(self::once())->method('fetchOne');

        $this->serviceWith([$this->preparable(710)])->import([$this->product('P1')]);

        self::assertSame([], $this->lockCalls());
    }

    /**
     * Every holder acquires in one fixed order, which is what stops two requests
     * wanting overlapping sets from taking them in opposite orders and waiting on
     * each other until both time out. Released in reverse.
     */
    public function testASetOfLocksIsTakenInOneCanonicalOrder(): void
    {
        $this->existingSkus = [];
        $product = $this->product('P1')
            ->setMedia([])
            ->setCategories([])
            ->setCustomAttributes([(new CustomAttribute())->setAttributeCode('color')->setValue('Red')]);

        $this->serviceWith([], createMissingOptions: true)->import([$product]);

        self::assertSame(
            [
                'lock:' . ImportLocks::ATTRIBUTE_OPTIONS,
                'lock:' . ImportLocks::PRODUCT_CREATE,
                'lock:' . ImportLocks::CATEGORY_TREE,
                'lock:' . ImportLocks::MEDIA_GALLERY,
                'unlock:' . ImportLocks::MEDIA_GALLERY,
                'unlock:' . ImportLocks::CATEGORY_TREE,
                'unlock:' . ImportLocks::PRODUCT_CREATE,
                'unlock:' . ImportLocks::ATTRIBUTE_OPTIONS,
            ],
            $this->lockCalls()
        );
    }

    /**
     * A lock left held would block every later import in the process, so opening
     * the transaction has to be inside the guarded region too — and rolling back
     * one that never opened would raise a second, misleading error.
     */
    public function testLocksGoBackEvenWhenTheTransactionCannotBeOpened(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('fetchOne')->willReturn('1');
        $this->connection->method('beginTransaction')
            ->willThrowException(new \RuntimeException('the server has gone away'));
        $this->connection->expects(self::never())->method('rollBack');

        $response = $this->serviceWith([])->import([$this->product('P1')->setCategories([])]);

        self::assertSame(
            ['lock:' . ImportLocks::CATEGORY_TREE, 'unlock:' . ImportLocks::CATEGORY_TREE],
            $this->lockCalls()
        );
        self::assertSame(1, $response->getFailed());
        self::assertStringContainsString('the server has gone away', $response->getResults()[0]->getMessages()[0]);
    }

    /**
     * A set is all or nothing. Half of it left held while the caller retries
     * would block the very request that is about to come back.
     */
    public function testAHalfAcquiredSetIsReleasedAgain(): void
    {
        $this->existingSkus = [];
        $this->blockedLocks = [ImportLocks::CATEGORY_TREE];

        $this->expectExceptionMessage('Another import is already running');

        try {
            $this->serviceWith([])->import([$this->product('P1')->setCategories([])]);
        } finally {
            self::assertSame(
                [
                    'lock:' . ImportLocks::PRODUCT_CREATE,
                    'lock-refused:' . ImportLocks::CATEGORY_TREE,
                    'unlock:' . ImportLocks::PRODUCT_CREATE,
                ],
                $this->lockCalls()
            );
        }
    }

    /**
     * Decided per BATCH, not per request: one unknown SKU in a large payload used
     * to make the whole request serialize against every other import, when only
     * the batch carrying it can create anything.
     */
    public function testOnlyTheBatchThatCanCreateAProductTakesTheProductLock(): void
    {
        $this->existingSkus = ['P1', 'P2'];

        // Batch size 1, so each product is its own batch and only the second one
        // names a SKU the catalog does not have.
        $this->serviceWith([], batchSize: 1)->import([
            $this->product('P1'),
            $this->product('NEW-1'),
            $this->product('P2'),
        ]);

        self::assertSame(
            ['lock:' . ImportLocks::PRODUCT_CREATE, 'unlock:' . ImportLocks::PRODUCT_CREATE],
            $this->lockCalls()
        );
    }

    /**
     * The option memo survives batches, but the lock does not. A label another
     * import committed while we were downloading is not in the memo, and
     * createOptions() trusts the memo to decide what is missing — so re-reading
     * under the freshly taken lock is what keeps it from inserting a duplicate.
     */
    public function testTheOptionMemoIsDroppedUnderEveryBatchsFreshLock(): void
    {
        $attributeOption = $this->createMock(AttributeOption::class);
        $attributeOption->expects(self::exactly(2))->method('forget');

        $this->serviceWith([], batchSize: 1, attributeOption: $attributeOption)
            ->import([$this->product('P1'), $this->product('P1-B')]);
    }

    /**
     * Nothing is committed yet, so the honest answer is that the request did not
     * happen — and this is the wording callers recognise and back off on.
     *
     * The type carries the rest: a status code of its own (429) and a
     * machine-readable reason, so a caller never has to match the message. Every
     * other failure from this endpoint is a 400, which must not be retried.
     */
    public function testAFirstBatchThatCannotTakeItsLocksIsRejectedAsRetryable(): void
    {
        $this->blockedLocks = [ImportLocks::CATEGORY_TREE];

        try {
            $this->serviceWith([])->import([$this->product('P1')->setCategories([])]);
            self::fail('the rejection should have been thrown');
        } catch (ImportLockedException $e) {
            self::assertSame('Another import is already running. Try again later.', $e->getMessage());
            self::assertSame(429, $e->getHttpCode());
            self::assertSame(
                [
                    'reason' => ImportLockedException::REASON,
                    'locks' => [ImportLocks::CATEGORY_TREE],
                    'retry_after' => ImportLocks::TIMEOUT_SEC,
                ],
                $e->getDetails()
            );
        }
    }

    /**
     * It is still a LocalizedException, so a caller outside the web API — the
     * CLI, another module — catches it exactly as it always did.
     */
    public function testTheRejectionRemainsALocalizedException(): void
    {
        $this->blockedLocks = [ImportLocks::CATEGORY_TREE];

        $this->expectException(LocalizedException::class);

        $this->serviceWith([])->import([$this->product('P1')->setCategories([])]);
    }

    /**
     * A later batch has committed work whose results are worth returning, so it
     * fails as a batch instead of throwing: a request that threw here would hand
     * back a 400 with no results at all and leave the caller to work out by other
     * means which of its products landed.
     */
    public function testALaterBatchThatCannotTakeItsLocksFailsOnlyItself(): void
    {
        $this->existingSkus = ['P1', 'P2'];
        // Only the batch carrying the media block wants the gallery lock.
        $this->blockedLocks = [ImportLocks::MEDIA_GALLERY];

        $response = $this->serviceWith([], batchSize: 1)->import([
            $this->product('P1'),
            $this->product('P2')->setMedia([]),
        ]);

        self::assertSame(2, $response->getReceived());
        self::assertSame(1, $response->getFailed());
        self::assertSame(ImportResultInterface::STATUS_ERROR, $response->getResults()[1]->getStatus());
        self::assertStringContainsString(
            'another import is holding',
            $response->getResults()[1]->getMessages()[0]
        );
        // The first batch committed and is reported normally.
        self::assertNotSame(ImportResultInterface::STATUS_ERROR, $response->getResults()[0]->getStatus());
    }

    /**
     * Work that committed is already visible to the storefront, so anything
     * escaping the batch loop must not leave it un-indexed behind stale FPC
     * entries — which is why invalidation runs in a finally.
     *
     * The batch itself cannot be the thing that escapes: processBatch() catches
     * Throwable and reports a rolled-back batch as failed results. What is left
     * is the machinery around it, and the context factory stands in for that.
     */
    public function testCommittedWorkIsStillInvalidatedWhenTheBatchLoopThrows(): void
    {
        $this->contextFactoryFailsOnCall = 2;

        try {
            $this->serviceWith([], batchSize: 1)->import([$this->product('P1'), $this->product('P1-B')]);
            self::fail('the failure should have propagated');
        } catch (\RuntimeException $e) {
            self::assertSame('the context could not be built', $e->getMessage());
        }

        // The first batch committed, and its IDs still reached the indexers.
        self::assertSame(['beginTransaction', 'commit', 'invalidate'], $this->calls);
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

    public function testResponseEchoesTheScopeTheRequestActuallyRanIn(): void
    {
        // The caller cannot infer it: /rest/V1/... resolves against the default
        // store view, and only `settings` overrides that.
        $response = $this->serviceWith([], storeId: 3)->import([$this->product('P1')]);

        self::assertSame(3, $response->getStoreId());
    }

    public function testProductWithoutScopedValuesReportsNoStoreResults(): void
    {
        $response = $this->serviceWith([])->import([$this->product('P1')]);

        // Null, not [] — a payload that predates store_values gets back exactly
        // the response shape it got before.
        self::assertNull($response->getResults()[0]->getStoreResults());
    }

    public function testEachScopeGetsItsOwnResultInPayloadOrder(): void
    {
        $processor = $this->scopeWriter([
            5 => ['applied' => true],
            3 => ['applied' => false, 'message' => 'every value it carried was refused'],
        ]);

        $response = $this->serviceWith([$processor])->import([$this->product('P1')]);

        $storeResults = $response->getResults()[0]->getStoreResults();
        self::assertCount(2, $storeResults);
        self::assertSame([5, 3], array_map(static fn ($r): int => $r->getStoreId(), $storeResults));
        self::assertSame(StoreResultInterface::STATUS_UPDATED, $storeResults[0]->getStatus());
        self::assertSame([], $storeResults[0]->getMessages());
        self::assertSame(StoreResultInterface::STATUS_SKIPPED, $storeResults[1]->getStatus());
        self::assertSame(['every value it carried was refused'], $storeResults[1]->getMessages());
        self::assertNull($storeResults[0]->getReason());
    }

    /**
     * One row per block the payload sent, whether or not its store view existed
     * — a caller matching rows against blocks has nothing to reconcile. There is
     * no store ID to report it under, and 0 would name the default scope, which
     * this list never covers.
     */
    public function testAnUnresolvableBlockReportsItsOwnRowWithoutAStoreId(): void
    {
        $processor = $this->createMock(ProcessorInterface::class);
        $processor->method('isEnabled')->willReturn(true);
        $processor->method('getSortOrder')->willReturn(300);
        $processor->method('process')->willReturnCallback(
            static function (BatchContext $context): void {
                $context->registerUnresolvedScope(
                    'P1',
                    StoreResultInterface::REASON_UNKNOWN_STORE,
                    'no such store view'
                );
            }
        );

        $storeResults = $this->serviceWith([$processor])
            ->import([$this->product('P1')])
            ->getResults()[0]
            ->getStoreResults();

        self::assertCount(1, $storeResults);
        self::assertNull($storeResults[0]->getStoreId());
        self::assertSame(StoreResultInterface::STATUS_SKIPPED, $storeResults[0]->getStatus());
        self::assertSame(StoreResultInterface::REASON_UNKNOWN_STORE, $storeResults[0]->getReason());
        self::assertSame(['no such store view'], $storeResults[0]->getMessages());
    }

    /**
     * A message belongs to exactly one result — the scope's, if it has one.
     * Reporting it in both places would double-count it in a caller that walks
     * the product result and then its scopes.
     */
    public function testAMessageIsReportedOnceAndOnItsOwnScope(): void
    {
        $processor = $this->createMock(ProcessorInterface::class);
        $processor->method('isEnabled')->willReturn(true);
        $processor->method('getSortOrder')->willReturn(300);
        $processor->method('process')->willReturnCallback(
            static function (BatchContext $context): void {
                $context->addMessage('P1', 'product-wide');
                $context->registerScope('P1', 3);
                $context->markScopeApplied('P1', 3);
                $context->addMessage('P1', 'scoped', 3);
            }
        );

        $result = $this->serviceWith([$processor])->import([$this->product('P1')])->getResults()[0];

        self::assertSame(['product-wide'], $result->getMessages());
        self::assertSame(['scoped'], $result->getStoreResults()[0]->getMessages());
    }

    public function testCountersCountProductsNotProductScopes(): void
    {
        $processor = $this->scopeWriter([
            3 => ['applied' => true],
            4 => ['applied' => true],
            5 => ['applied' => true],
        ]);

        $response = $this->serviceWith([$processor])->import([$this->product('P1')]);

        // One product created, not four. Counting scopes would quadruple every
        // existing dashboard the day a caller starts sending store_values.
        self::assertSame(1, $response->getReceived());
        self::assertSame(1, $response->getCreated());
        self::assertSame(0, $response->getFailed());
        self::assertCount(3, $response->getResults()[0]->getStoreResults());
    }

    public function testAFailedProductFailsEveryOneOfItsScopes(): void
    {
        // The batch is one transaction: nothing it wrote survives, in any scope.
        $processor = $this->createMock(ProcessorInterface::class);
        $processor->method('isEnabled')->willReturn(true);
        $processor->method('getSortOrder')->willReturn(300);
        $processor->method('process')->willReturnCallback(
            static function (BatchContext $context): void {
                $context->registerScope('P1', 3);
                $context->markScopeApplied('P1', 3);
                $context->fail('P1', 'the entity row could not be written');
            }
        );

        $response = $this->serviceWith([$processor])->import([$this->product('P1')]);

        self::assertSame(1, $response->getFailed());
        $result = $response->getResults()[0];
        self::assertSame(ImportResultInterface::STATUS_ERROR, $result->getStatus());
        self::assertSame(StoreResultInterface::STATUS_ERROR, $result->getStoreResults()[0]->getStatus());
    }

    /**
     * A stand-in for EavValueProcessor: records the scopes a product carries and
     * what happened in each, which is all buildResponse() reads.
     *
     * @param array<int, array{applied: bool, message?: string}> $scopes store ID => outcome
     */
    private function scopeWriter(array $scopes): ProcessorInterface&MockObject
    {
        $processor = $this->createMock(ProcessorInterface::class);
        $processor->method('isEnabled')->willReturn(true);
        $processor->method('getSortOrder')->willReturn(300);
        $processor->method('process')->willReturnCallback(
            static function (BatchContext $context) use ($scopes): void {
                foreach ($scopes as $storeId => $outcome) {
                    $context->registerScope('P1', $storeId);
                    if ($outcome['applied']) {
                        $context->markScopeApplied('P1', $storeId);
                    }
                    if (isset($outcome['message'])) {
                        $context->addMessage('P1', $outcome['message'], $storeId);
                    }
                }
            }
        );

        return $processor;
    }

    /**
     * @param ProcessorInterface[] $processors
     */
    private function serviceWith(
        array $processors,
        int $storeId = 0,
        bool $createMissingOptions = false,
        bool $mediaEnabled = true,
        int $batchSize = 500,
        ?AttributeOption $attributeOption = null,
        ?ImportEventDispatcher $dispatcher = null
    ): ImportService {
        $config = $this->createMock(Config::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('getBatchSize')->willReturn($batchSize);
        $config->method('isContinueOnError')->willReturn(true);
        $config->method('isCreateMissingOptions')->willReturn($createMissingOptions);
        $config->method('isMediaEnabled')->willReturn($mediaEnabled);

        $productEntity = $this->createMock(ProductEntity::class);
        $productEntity->method('getExistingBySkus')->willReturnCallback(
            fn (array $skus): array => array_fill_keys(
                array_values(array_intersect($skus, $this->existingSkus)),
                ['entity_id' => 42]
            )
        );

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($this->connection);

        $batchContextFactory = $this->createMock(BatchContextFactory::class);
        $created = 0;
        $batchContextFactory->method('create')->willReturnCallback(
            function (array $data) use (&$created): BatchContext {
                if (++$created === $this->contextFactoryFailsOnCall) {
                    throw new \RuntimeException('the context could not be built');
                }

                return new BatchContext($data['products'], $data['storeId']);
            }
        );

        $storeWebsiteMap = $this->createMock(StoreWebsiteMap::class);
        $storeWebsiteMap->method('resolveScopeStoreId')->willReturn($storeId);

        $responseFactory = $this->createMock(ImportResponseInterfaceFactory::class);
        $responseFactory->method('create')->willReturnCallback(static fn (): ImportResponse => new ImportResponse());
        $resultFactory = $this->createMock(ImportResultInterfaceFactory::class);
        $resultFactory->method('create')->willReturnCallback(static fn (): ImportResult => new ImportResult());
        $storeResultFactory = $this->createMock(StoreResultInterfaceFactory::class);
        $storeResultFactory->method('create')->willReturnCallback(static fn (): StoreResult => new StoreResult());

        return new ImportService(
            $config,
            $resourceConnection,
            $this->lockManager,
            $batchContextFactory,
            $productEntity,
            $attributeOption ?? $this->createMock(AttributeOption::class),
            $storeWebsiteMap,
            $this->invalidationHandler,
            $dispatcher ?? $this->createMock(ImportEventDispatcher::class),
            $this->createMock(ImportState::class),
            $responseFactory,
            $resultFactory,
            $storeResultFactory,
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
