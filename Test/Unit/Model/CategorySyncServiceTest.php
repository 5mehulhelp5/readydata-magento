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
use ReadyData\Import\Api\Data\CategorySyncResponseInterfaceFactory;
use ReadyData\Import\Api\Data\CategorySyncResultInterface;
use ReadyData\Import\Api\Data\CategorySyncResultInterfaceFactory;
use ReadyData\Import\Logger\Logger;
use ReadyData\Import\Model\Cache\CategoryPathResolver;
use ReadyData\Import\Model\Cache\StoreWebsiteMap;
use ReadyData\Import\Model\Category\CategoryWriter;
use ReadyData\Import\Model\Category\PathParser;
use ReadyData\Import\Model\CategorySyncService;
use ReadyData\Import\Model\CategoryValidator;
use ReadyData\Import\Model\Config;
use ReadyData\Import\Model\Data\CategoryDefinition;
use ReadyData\Import\Model\Data\CategorySyncResponse;
use ReadyData\Import\Model\Data\CategorySyncResult;
use ReadyData\Import\Model\Data\CustomAttribute;
use ReadyData\Import\Model\Indexer\CategoryInvalidationHandler;
use ReadyData\Import\Model\ResourceModel\Category as CategoryResource;

class CategorySyncServiceTest extends TestCase
{
    private const ROOT_ID = 2;
    private const MEN_ID = 10;
    private const SHIRTS_ID = 11;

    private Config&MockObject $config;
    private LockManagerInterface&MockObject $lockManager;
    private AdapterInterface&MockObject $connection;
    private CategoryPathResolver&MockObject $pathResolver;
    private CategoryResource&MockObject $categoryResource;
    private CategoryWriter&MockObject $writer;
    private CategoryInvalidationHandler&MockObject $invalidationHandler;
    private ResourceConnection&MockObject $resourceConnection;
    private StoreWebsiteMap&MockObject $storeWebsiteMap;
    private CategorySyncService $service;

    /**
     * Scope the service resolves settings.store_view_code to; read lazily by
     * the StoreWebsiteMap mock so a test can switch scope after setUp.
     */
    private int $storeId = 0;

    protected function setUp(): void
    {
        $this->config = $this->configMock();

        $this->lockManager = $this->createMock(LockManagerInterface::class);
        $this->lockManager->method('lock')->willReturn(true);

        $this->connection = $this->createMock(AdapterInterface::class);
        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($this->connection);

        $this->pathResolver = $this->createMock(CategoryPathResolver::class);
        $this->categoryResource = $this->createMock(CategoryResource::class);
        $this->categoryResource->method('getRootCategories')->willReturn(['Default Category' => self::ROOT_ID]);

        $this->writer = $this->createMock(CategoryWriter::class);
        $this->invalidationHandler = $this->createMock(CategoryInvalidationHandler::class);

        $storeWebsiteMap = $this->createMock(StoreWebsiteMap::class);
        $storeWebsiteMap->method('resolveStoreId')->willReturnCallback(fn (): int => $this->storeId);

        $this->resourceConnection = $resourceConnection;
        $this->storeWebsiteMap = $storeWebsiteMap;
        $this->service = $this->buildService();
    }

    /**
     * The service's dependencies are readonly promoted properties, so a test
     * that needs a different Config or LockManager builds its own instance
     * rather than reflecting one in.
     */
    private function buildService(?Config $config = null, ?LockManagerInterface $lockManager = null): CategorySyncService
    {
        $resultFactory = $this->createMock(CategorySyncResultInterfaceFactory::class);
        $resultFactory->method('create')
            ->willReturnCallback(static fn (): CategorySyncResult => new CategorySyncResult());
        $responseFactory = $this->createMock(CategorySyncResponseInterfaceFactory::class);
        $responseFactory->method('create')
            ->willReturnCallback(static fn (): CategorySyncResponse => new CategorySyncResponse());

        return new CategorySyncService(
            $config ?? $this->config,
            $lockManager ?? $this->lockManager,
            $this->resourceConnection,
            new CategoryValidator(new PathParser()),
            $this->pathResolver,
            $this->categoryResource,
            $this->writer,
            $this->storeWebsiteMap,
            $this->invalidationHandler,
            $responseFactory,
            $resultFactory,
            $this->createMock(Logger::class)
        );
    }

    private function configMock(bool $categorySyncEnabled = true, bool $continueOnError = true): Config&MockObject
    {
        $config = $this->createMock(Config::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('isCategorySyncEnabled')->willReturn($categorySyncEnabled);
        $config->method('isContinueOnError')->willReturn($continueOnError);

        return $config;
    }

    private function definition(string $path): CategoryDefinition
    {
        return (new CategoryDefinition())->setPath($path);
    }

    public function testCreatesMissingCategoryUnderResolvedRoot(): void
    {
        $this->categoryResource->method('getChildIdsByParentIds')->willReturn([]);
        $this->writer->expects(self::once())->method('create')
            ->with(self::ROOT_ID, 'Men')
            ->willReturn(self::MEN_ID);
        $this->writer->expects(self::never())->method('update');
        $this->invalidationHandler->expects(self::once())->method('execute')->with([self::MEN_ID]);

        $response = $this->service->sync([$this->definition('Default Category/Men')]);

        self::assertSame(1, $response->getReceived());
        self::assertSame(1, $response->getCreated());
        $result = $response->getResults()[0];
        self::assertSame(CategorySyncResultInterface::STATUS_CREATED, $result->getStatus());
        self::assertSame(self::MEN_ID, $result->getEntityId());
        self::assertSame('Default Category/Men', $result->getPath());
    }

    public function testUnchangedCategoryIsReportedWithoutInvalidation(): void
    {
        $this->categoryResource->method('getChildIdsByParentIds')
            ->willReturn([self::ROOT_ID => ['Men' => [self::MEN_ID]]]);
        $this->writer->method('update')->willReturn(false);
        $this->writer->expects(self::never())->method('create');
        // Nothing changed, so nothing to reindex or purge.
        $this->invalidationHandler->expects(self::once())->method('execute')->with([]);

        $response = $this->service->sync([$this->definition('Default Category/Men')]);

        self::assertSame(1, $response->getUnchanged());
        self::assertSame(0, $response->getUpdated());
        self::assertSame(
            CategorySyncResultInterface::STATUS_UNCHANGED,
            $response->getResults()[0]->getStatus()
        );
    }

    public function testParentsAreCreatedBeforeChildrenRegardlessOfPayloadOrder(): void
    {
        // Child listed first: only depth ordering can make this work.
        $definitions = [
            $this->definition('Default Category/Men/Shirts'),
            $this->definition('Default Category/Men'),
        ];

        $this->categoryResource->method('getChildIdsByParentIds')->willReturn([]);
        $this->pathResolver->method('lookupPaths')->willReturn(['Default Category/Men' => self::MEN_ID]);

        $order = [];
        $this->writer->method('create')
            ->willReturnCallback(function (int $parentId, string $name) use (&$order): int {
                $order[] = $name;
                return $name === 'Men' ? self::MEN_ID : self::SHIRTS_ID;
            });

        $response = $this->service->sync($definitions);

        self::assertSame(['Men', 'Shirts'], $order);
        self::assertSame(2, $response->getCreated());
    }

    public function testAmbiguousSiblingNameIsSkippedRatherThanGuessed(): void
    {
        $this->categoryResource->method('getChildIdsByParentIds')
            ->willReturn([self::ROOT_ID => ['Men' => [self::MEN_ID, 99]]]);
        $this->writer->expects(self::never())->method('update');
        $this->writer->expects(self::never())->method('create');

        $response = $this->service->sync([$this->definition('Default Category/Men')]);

        $result = $response->getResults()[0];
        self::assertSame(CategorySyncResultInterface::STATUS_SKIPPED, $result->getStatus());
        self::assertSame(CategorySyncResultInterface::REASON_AMBIGUOUS_PATH, $result->getReason());
        self::assertStringContainsString('10, 99', $result->getMessages()[0]);
    }

    public function testUnknownRootIsSkipped(): void
    {
        $this->categoryResource->method('getChildIdsByParentIds')->willReturn([]);
        $this->writer->expects(self::never())->method('create');

        $response = $this->service->sync([$this->definition('Typo Category/Men')]);

        $result = $response->getResults()[0];
        self::assertSame(CategorySyncResultInterface::STATUS_SKIPPED, $result->getStatus());
        self::assertSame(CategorySyncResultInterface::REASON_UNKNOWN_ROOT, $result->getReason());
    }

    public function testMissingIntermediateParentIsReportedRatherThanCreated(): void
    {
        $this->pathResolver->method('lookupPaths')->willReturn([]);
        // Never resolvePaths(): implicit creation would produce a category the
        // caller never asked for and never sees in the response.
        $this->pathResolver->expects(self::never())->method('resolvePaths');
        $this->writer->expects(self::never())->method('create');

        $response = $this->service->sync([$this->definition('Default Category/Men/Shirts')]);

        self::assertSame(
            CategorySyncResultInterface::REASON_PARENT_NOT_FOUND,
            $response->getResults()[0]->getReason()
        );
    }

    public function testRootOnlyPathIsNeverWritten(): void
    {
        $this->writer->expects(self::never())->method('create');
        $this->writer->expects(self::never())->method('update');

        $response = $this->service->sync([$this->definition('Default Category')]);

        self::assertSame(
            CategorySyncResultInterface::REASON_ROOT_NOT_WRITABLE,
            $response->getResults()[0]->getReason()
        );
    }

    public function testRenameWithoutCategoryIdIsRefused(): void
    {
        $this->categoryResource->method('getChildIdsByParentIds')
            ->willReturn([self::ROOT_ID => ['Men' => [self::MEN_ID]]]);
        $this->writer->expects(self::never())->method('update');

        $definition = $this->definition('Default Category/Men')->setName('Gentlemen');
        $response = $this->service->sync([$definition]);

        self::assertSame(
            CategorySyncResultInterface::REASON_RENAME_REQUIRES_CATEGORY_ID,
            $response->getResults()[0]->getReason()
        );
    }

    public function testCategoryIdImplyingADifferentParentIsReportedAsAMove(): void
    {
        $this->categoryResource->method('getExistingByIds')->willReturn([
            self::SHIRTS_ID => [
                'entity_id' => self::SHIRTS_ID,
                'parent_id' => 77,
                'level' => 3,
                'path' => '1/2/77/11',
            ],
        ]);
        $this->pathResolver->method('lookupPaths')->willReturn(['Default Category/Men' => self::MEN_ID]);
        $this->writer->expects(self::never())->method('update');

        $definition = $this->definition('Default Category/Men/Shirts')->setCategoryId(self::SHIRTS_ID);
        $response = $this->service->sync([$definition]);

        self::assertSame(
            CategorySyncResultInterface::REASON_MOVE_NOT_SUPPORTED,
            $response->getResults()[0]->getReason()
        );
    }

    public function testRenameByCategoryIdMarksDescendantsInTheSameRequestAsStale(): void
    {
        $this->categoryResource->method('getExistingByIds')->willReturn([
            self::MEN_ID => ['entity_id' => self::MEN_ID, 'parent_id' => self::ROOT_ID, 'level' => 2, 'path' => '1/2/10'],
        ]);
        $this->categoryResource->method('getChildIdsByParentIds')->willReturn([]);
        $this->pathResolver->method('lookupPaths')->willReturn(['Default Category/Men' => self::MEN_ID]);
        $this->writer->method('update')->willReturn(true);
        // The renamed path must leave the resolver's cache or a later lookup
        // returns the category under a name it no longer has.
        $this->pathResolver->expects(self::once())->method('forget')->with('Default Category/Men');
        $this->writer->expects(self::never())->method('create');

        $response = $this->service->sync([
            $this->definition('Default Category/Men')->setCategoryId(self::MEN_ID)->setName('Gentlemen'),
            $this->definition('Default Category/Men/Shirts'),
        ]);

        $byPath = [];
        foreach ($response->getResults() as $result) {
            $byPath[$result->getPath()] = $result;
        }
        self::assertSame(CategorySyncResultInterface::STATUS_UPDATED, $byPath['Default Category/Men']->getStatus());
        self::assertSame(
            CategorySyncResultInterface::REASON_STALE_PARENT_PATH,
            $byPath['Default Category/Men/Shirts']->getReason()
        );
    }

    public function testDisabledSyncSkipsEverythingWithoutTakingTheLock(): void
    {
        $lockManager = $this->createMock(LockManagerInterface::class);
        $service = $this->buildService($this->configMock(categorySyncEnabled: false), $lockManager);

        $lockManager->expects(self::never())->method('lock');
        $this->writer->expects(self::never())->method('create');
        $this->writer->expects(self::never())->method('update');
        $this->invalidationHandler->expects(self::never())->method('execute');

        $response = $service->sync([$this->definition('Default Category/Men')]);

        self::assertSame(1, $response->getSkipped());
        self::assertSame(
            CategorySyncResultInterface::REASON_DISABLED,
            $response->getResults()[0]->getReason()
        );
    }

    public function testLockContentionThrows(): void
    {
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->method('lock')->willReturn(false);
        $service = $this->buildService(null, $lockManager);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Another import is already running.');

        $service->sync([$this->definition('Default Category/Men')]);
    }

    public function testEmptyPayloadThrows(): void
    {
        $this->expectException(LocalizedException::class);

        $this->service->sync([]);
    }

    public function testWriterFailureIsReportedPerCategoryAndRollsBackThatEntryOnly(): void
    {
        $this->categoryResource->method('getChildIdsByParentIds')->willReturn([]);
        $this->pathResolver->method('lookupPaths')->willReturn(['Default Category/Men' => self::MEN_ID]);
        $this->writer->method('create')->willReturnCallback(
            static function (int $parentId, string $name): int {
                if ($name === 'Men') {
                    throw new \RuntimeException('url key conflict');
                }
                return self::SHIRTS_ID;
            }
        );
        // The rollback is what clears the connection's partial-rollback flag;
        // without it the next beginTransaction() would throw.
        $this->connection->expects(self::once())->method('rollBack');
        $this->connection->expects(self::exactly(2))->method('beginTransaction');
        $this->connection->expects(self::once())->method('commit');

        $response = $this->service->sync([
            $this->definition('Default Category/Men'),
            $this->definition('Default Category/Men/Shirts'),
        ]);

        self::assertSame(1, $response->getFailed());
        self::assertSame(1, $response->getCreated());
        self::assertStringContainsString('url key conflict', $response->getResults()[0]->getMessages()[0]);
    }

    public function testContinueOnErrorOffAbortsRemainingCategories(): void
    {
        $service = $this->buildService($this->configMock(continueOnError: false));

        $this->categoryResource->method('getChildIdsByParentIds')->willReturn([]);
        $this->pathResolver->method('lookupPaths')->willReturn(['Default Category/Men' => self::MEN_ID]);
        $this->writer->method('create')->willThrowException(new \RuntimeException('boom'));

        $response = $service->sync([
            $this->definition('Default Category/Men'),
            $this->definition('Default Category/Men/Shirts'),
        ]);

        self::assertSame(1, $response->getFailed());
        self::assertSame(1, $response->getSkipped());
        self::assertSame(
            CategorySyncResultInterface::REASON_ABORTED,
            $response->getResults()[1]->getReason()
        );
    }

    public function testDuplicatePathsCollapseWithLastWinning(): void
    {
        $this->categoryResource->method('getChildIdsByParentIds')->willReturn([]);

        $captured = [];
        $this->writer->method('create')
            ->willReturnCallback(function (int $parentId, string $name, $definition) use (&$captured): int {
                $captured[] = $definition->getIsActive();
                return self::MEN_ID;
            });

        $response = $this->service->sync([
            $this->definition('Default Category/Men')->setIsActive(1),
            $this->definition('Default Category/Men')->setIsActive(0),
        ]);

        self::assertSame(2, $response->getReceived());
        self::assertCount(1, $response->getResults());
        self::assertSame([0], $captured);
    }

    public function testEscapedSeparatorCountsAsOneSegmentForDepthOrdering(): void
    {
        $this->categoryResource->method('getChildIdsByParentIds')->willReturn([]);

        $created = [];
        $this->writer->method('create')
            ->willReturnCallback(function (int $parentId, string $name) use (&$created): int {
                $created[] = $name;
                return self::MEN_ID;
            });

        // "Wo\/Men" is one segment, so this is depth 2, not depth 3.
        $response = $this->service->sync([$this->definition('Default Category/Wo\\/Men')]);

        self::assertSame(['Wo/Men'], $created);
        self::assertSame(1, $response->getCreated());
    }

    public function testUnsupportedCustomAttributeIsRejected(): void
    {
        $this->writer->expects(self::never())->method('create');

        $definition = $this->definition('Default Category/Men')->setCustomAttributes([
            (new CustomAttribute())->setAttributeCode('children_count')->setValue('5'),
        ]);
        $response = $this->service->sync([$definition]);

        self::assertSame(
            CategorySyncResultInterface::REASON_INVALID_DEFINITION,
            $response->getResults()[0]->getReason()
        );
    }

    public function testClearingAProtectedAttributeIsRejected(): void
    {
        $this->writer->expects(self::never())->method('create');

        $definition = $this->definition('Default Category/Men')->setClearAttributes(['name']);
        $response = $this->service->sync([$definition]);

        self::assertSame(
            CategorySyncResultInterface::REASON_PROTECTED_ATTRIBUTE,
            $response->getResults()[0]->getReason()
        );
    }

    public function testEveryRejectedEntryIsReportedNotJustTheLast(): void
    {
        // Rejected entries have no parsed path to be keyed by. Keying them all
        // as "the category with no id" collapsed them onto one another, so
        // `received` counted four and `results` explained one.
        $response = $this->service->sync([
            $this->definition('1'),
            $this->definition('2'),
            $this->definition('3'),
            $this->definition('4'),
        ]);

        self::assertSame(4, $response->getReceived());
        self::assertCount(4, $response->getResults());
        self::assertSame(4, $response->getSkipped());
        // Each one is echoed back as the caller typed it.
        self::assertSame(
            ['1', '2', '3', '4'],
            array_map(static fn ($result): string => $result->getPath(), $response->getResults())
        );
    }

    public function testRejectedEntriesWithNoPathAtAllAreStillReportedSeparately(): void
    {
        $response = $this->service->sync([new CategoryDefinition(), new CategoryDefinition()]);

        self::assertCount(2, $response->getResults());
        self::assertSame(['#0', '#1'], array_map(
            static fn ($result): string => $result->getPath(),
            $response->getResults()
        ));
    }

    public function testStalePathAlongsideACategoryIdDoesNotRenameTheCategory(): void
    {
        // The path is informational once category_id is given — it may well be
        // the pre-rename one a caller kept on file. Taking the name from its
        // last segment renamed the category back on every sync.
        $this->categoryResource->method('getExistingByIds')->willReturn([
            self::MEN_ID => [
                'entity_id' => self::MEN_ID,
                'parent_id' => self::ROOT_ID,
                'level' => 2,
                'path' => '1/2/10',
            ],
        ]);

        $this->writer->expects(self::once())->method('update')
            ->with(self::MEN_ID, null, self::anything(), 0)
            ->willReturn(false);

        $definition = $this->definition('Default Category/Men')
            ->setCategoryId(self::MEN_ID)
            ->setIsActive(0);
        $response = $this->service->sync([$definition]);

        self::assertSame(CategorySyncResultInterface::STATUS_UNCHANGED, $response->getResults()[0]->getStatus());
    }

    public function testMoveRefusalStillReturnsTheEntityId(): void
    {
        $this->categoryResource->method('getExistingByIds')->willReturn([
            self::SHIRTS_ID => [
                'entity_id' => self::SHIRTS_ID,
                'parent_id' => 77,
                'level' => 3,
                'path' => '1/2/77/11',
            ],
        ]);
        $this->pathResolver->method('lookupPaths')->willReturn(['Default Category/Men' => self::MEN_ID]);

        $definition = $this->definition('Default Category/Men/Shirts')->setCategoryId(self::SHIRTS_ID);
        $response = $this->service->sync([$definition]);

        // A caller that is the system of record wants the ID even for a
        // refusal — it is what they store to address the category next time.
        self::assertSame(self::SHIRTS_ID, $response->getResults()[0]->getEntityId());
    }

    public function testPositionIsRefusedAtStoreScope(): void
    {
        $this->storeId = 1;
        $this->categoryResource->method('getChildIdsByParentIds')
            ->willReturn([self::ROOT_ID => ['Men' => [self::MEN_ID]]]);
        // position is a column on catalog_category_entity with no store
        // dimension, so writing it here would reorder siblings for every store.
        $this->writer->expects(self::never())->method('update');

        $definition = $this->definition('Default Category/Men')->setPosition(10);
        $response = $this->service->sync([$definition]);

        self::assertSame(
            CategorySyncResultInterface::REASON_STORE_SCOPE_STRUCTURAL_CHANGE,
            $response->getResults()[0]->getReason()
        );
    }

    public function testStoreScopedRenameDoesNotMarkDescendantsStale(): void
    {
        $this->storeId = 1;
        $this->categoryResource->method('getExistingByIds')->willReturn([
            self::MEN_ID => [
                'entity_id' => self::MEN_ID,
                'parent_id' => self::ROOT_ID,
                'level' => 2,
                'path' => '1/2/10',
            ],
        ]);
        $this->categoryResource->method('getChildIdsByParentIds')
            ->willReturn([self::MEN_ID => ['Shirts' => [self::SHIRTS_ID]]]);
        $this->pathResolver->method('lookupPaths')->willReturn(['Default Category/Men' => self::MEN_ID]);
        $this->writer->method('update')->willReturn(true);
        // Path resolution matches store-0 names, which a store-scoped rename
        // never touches, so the child's path still resolves exactly as before.
        $this->pathResolver->expects(self::never())->method('forget');

        $response = $this->service->sync([
            $this->definition('Default Category/Men')->setCategoryId(self::MEN_ID)->setName('Messieurs'),
            $this->definition('Default Category/Men/Shirts'),
        ]);

        $byPath = [];
        foreach ($response->getResults() as $result) {
            $byPath[$result->getPath()] = $result;
        }
        self::assertSame(
            CategorySyncResultInterface::STATUS_UPDATED,
            $byPath['Default Category/Men/Shirts']->getStatus()
        );
        self::assertNull($byPath['Default Category/Men/Shirts']->getReason());
    }

    public function testSiblingsAreLoadedOncePerBucketWhenNothingChanges(): void
    {
        $calls = 0;
        $this->categoryResource->method('getChildIdsByParentIds')
            ->willReturnCallback(function () use (&$calls): array {
                $calls++;
                return [self::ROOT_ID => ['Men' => [self::MEN_ID], 'Women' => [12]]];
            });
        $this->writer->method('update')->willReturn(false);

        $this->service->sync([
            $this->definition('Default Category/Men'),
            $this->definition('Default Category/Women'),
        ]);

        self::assertSame(1, $calls, 'Both entries share a bucket and neither wrote, so one query covers them.');
    }

    public function testSiblingsAreReloadedAfterAWriteInTheSameBucket(): void
    {
        // A rename inside the bucket moves a name from one ID to another;
        // reusing the map taken before it would miss the renamed-to category
        // and create a duplicate sibling under the same parent.
        $calls = 0;
        $this->categoryResource->method('getChildIdsByParentIds')
            ->willReturnCallback(function () use (&$calls): array {
                $calls++;
                return $calls === 1
                    ? [self::ROOT_ID => ['Men' => [self::MEN_ID]]]
                    : [self::ROOT_ID => ['Men' => [self::MEN_ID], 'Gentlemen' => [self::MEN_ID]]];
            });
        $this->categoryResource->method('getExistingByIds')->willReturn([
            self::MEN_ID => [
                'entity_id' => self::MEN_ID,
                'parent_id' => self::ROOT_ID,
                'level' => 2,
                'path' => '1/2/10',
            ],
        ]);
        $this->writer->method('update')->willReturn(true);
        $this->writer->expects(self::never())->method('create');

        $response = $this->service->sync([
            $this->definition('Default Category/Men')->setCategoryId(self::MEN_ID)->setName('Gentlemen'),
            $this->definition('Default Category/Gentlemen'),
        ]);

        self::assertSame(2, $calls);
        self::assertSame(2, $response->getUpdated());
    }

    public function testCommittedCategoriesAreInvalidatedEvenWhenALaterBucketThrows(): void
    {
        $this->categoryResource->method('getChildIdsByParentIds')->willReturn([]);
        $this->writer->method('create')->willReturn(self::MEN_ID);
        // Blows up while resolving the deeper bucket's parents, i.e. outside
        // any per-category transaction.
        $this->pathResolver->method('lookupPaths')
            ->willThrowException(new \RuntimeException('connection lost'));

        // The first category is already committed; skipping this would leave
        // the storefront serving a stale tree for work that did land.
        $this->invalidationHandler->expects(self::once())->method('execute')->with([self::MEN_ID]);

        $this->expectException(\RuntimeException::class);

        $this->service->sync([
            $this->definition('Default Category/Men'),
            $this->definition('Default Category/Men/Shirts'),
        ]);
    }

    public function testInvalidationFailureDoesNotReplaceTheSyncOutcome(): void
    {
        $this->categoryResource->method('getChildIdsByParentIds')->willReturn([]);
        $this->writer->method('create')->willReturn(self::MEN_ID);
        $this->invalidationHandler->method('execute')
            ->willThrowException(new \RuntimeException('cache backend down'));

        // The category is committed either way; failing the response here
        // would tell the caller their write did not happen.
        $response = $this->service->sync([$this->definition('Default Category/Men')]);

        self::assertSame(1, $response->getCreated());
    }
}
