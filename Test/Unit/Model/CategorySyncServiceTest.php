<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Test\Unit\Model;

use Magento\Catalog\Model\Category as CategoryModel;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReadyData\Import\Api\Data\CategoryStoreResultInterface;
use ReadyData\Import\Api\Data\CategoryStoreResultInterfaceFactory;
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
use ReadyData\Import\Model\Data\CategoryStoreResult;
use ReadyData\Import\Model\Data\CategoryStoreValues;
use ReadyData\Import\Model\Data\CategorySyncResponse;
use ReadyData\Import\Model\Data\ImportSettings;
use ReadyData\Import\Model\Data\CategorySyncResult;
use ReadyData\Import\Model\Data\CustomAttribute;
use ReadyData\Import\Model\Indexer\CategoryInvalidationHandler;
use ReadyData\Import\Model\ResourceModel\Category as CategoryResource;

class CategorySyncServiceTest extends TestCase
{
    private const ROOT_ID = 2;
    private const OUTDOOR_ID = 7;
    private const MEN_ID = 10;
    private const SHIRTS_ID = 11;
    private const TENTS_ID = 12;
    private const WOMEN_ID = 20;

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

    /**
     * Root category of the store view above, likewise read lazily.
     */
    private int $storeRootId = self::ROOT_ID;

    /**
     * Per-store-view root override, for store_values blocks pointed at a view
     * whose storefront shows another tree.
     *
     * @var array<int, int>
     */
    private array $storeRoots = [];

    /**
     * Level-1 roots as the resource model would report them. Mutable so a test
     * can add an ambiguous name, a second tree, or a root created mid-request.
     *
     * @var array<string, int[]>
     */
    private array $rootIds = ['Default Category' => [self::ROOT_ID]];

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
        $this->categoryResource->method('getRootCategoryIds')
            ->willReturnCallback(fn (): array => $this->rootIds);

        $this->writer = $this->createMock(CategoryWriter::class);
        $this->invalidationHandler = $this->createMock(CategoryInvalidationHandler::class);

        $storeWebsiteMap = $this->createMock(StoreWebsiteMap::class);
        $storeWebsiteMap->method('resolveStoreId')->willReturnCallback(fn (): int => $this->storeId);
        // Keyed by store ID so a store_values block can name a view whose
        // storefront shows a different root than the request's own scope.
        $storeWebsiteMap->method('getRootCategoryId')
            ->willReturnCallback(fn (int $storeId): int => $this->storeRoots[$storeId] ?? $this->storeRootId);
        $storeWebsiteMap->method('findScopeStoreId')->willReturnCallback(
            fn (?int $storeId, ?string $code): ?int => match (true) {
                $storeId !== null => in_array($storeId, [0, 1, 2, 3], true) ? $storeId : null,
                default => ['de_de' => 1][$code] ?? null,
            }
        );

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
        $storeResultFactory = $this->createMock(CategoryStoreResultInterfaceFactory::class);
        $storeResultFactory->method('create')
            ->willReturnCallback(static fn (): CategoryStoreResult => new CategoryStoreResult());

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
            $storeResultFactory,
            $this->createMock(Logger::class)
        );
    }

    /**
     * The structural switches default to ON here so a test only mentions them
     * when it is testing them. Production defaults are the opposite (both off);
     * that is what testMovesDisabledIsRefused/testDeletesDisabledIsRefused cover.
     */
    private function configMock(
        bool $categorySyncEnabled = true,
        bool $continueOnError = true,
        bool $allowMove = true,
        bool $allowDelete = true,
        bool $allowCrossRootMove = false
    ): Config&MockObject {
        $config = $this->createMock(Config::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('isCategorySyncEnabled')->willReturn($categorySyncEnabled);
        $config->method('isContinueOnError')->willReturn($continueOnError);
        $config->method('isCategoryMoveAllowed')->willReturn($allowMove);
        $config->method('isCategoryDeleteAllowed')->willReturn($allowDelete);
        $config->method('isCrossRootMoveAllowed')->willReturn($allowCrossRootMove);

        return $config;
    }

    private function definition(string $path): CategoryDefinition
    {
        return (new CategoryDefinition())->setPath($path);
    }

    /**
     * A stored row as CategoryResource::getExistingByIds() reports it.
     *
     * @return array{entity_id: int, parent_id: int, level: int, path: string}
     */
    private static function row(int $entityId, int $parentId, int $level, string $path): array
    {
        return ['entity_id' => $entityId, 'parent_id' => $parentId, 'level' => $level, 'path' => $path];
    }

    /**
     * Stub getExistingByIds() over a fixed set of rows, honouring the requested
     * IDs — the move and delete paths look up a destination or a batch of
     * targets, so a flat willReturn() would hand back the wrong rows.
     *
     * @param array<int, array{entity_id: int, parent_id: int, level: int, path: string}> $rows
     */
    private function stubExistingRows(array $rows): void
    {
        $this->categoryResource->method('getExistingByIds')->willReturnCallback(
            static fn (array $ids): array => array_intersect_key($rows, array_flip($ids))
        );
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

    public function testSingleSegmentPathCreatesARootUnderTheTreeRoot(): void
    {
        $this->categoryResource->method('getChildIdsByParentIds')->willReturn([]);
        // Explicitly the tree root: CategoryRepository::save() falls back to the
        // CURRENT STORE's root for a falsy parent, which would silently create
        // the "root" one level too deep.
        $this->writer->expects(self::once())->method('create')
            ->with(CategoryModel::TREE_ROOT_ID, 'Outdoor Catalog')
            ->willReturn(self::OUTDOOR_ID);
        // The resolver memoizes the root name => ID map for the request.
        $this->pathResolver->expects(self::once())->method('forgetRoots');

        $response = $this->service->sync([$this->definition('Outdoor Catalog')]);

        self::assertSame(1, $response->getCreated());
        $result = $response->getResults()[0];
        self::assertSame(CategorySyncResultInterface::STATUS_CREATED, $result->getStatus());
        self::assertSame(self::OUTDOOR_ID, $result->getEntityId());
    }

    public function testExistingRootIsUpdatedThroughItsSingleSegmentPath(): void
    {
        $this->categoryResource->method('getChildIdsByParentIds')
            ->willReturn([CategoryModel::TREE_ROOT_ID => ['Default Category' => [self::ROOT_ID]]]);
        $this->writer->expects(self::once())->method('update')
            ->with(self::ROOT_ID, 'Default Category', self::anything(), null, 0)
            ->willReturn(true);
        $this->writer->expects(self::never())->method('create');

        $response = $this->service->sync([$this->definition('Default Category')->setIsActive(1)]);

        self::assertSame(1, $response->getUpdated());
        self::assertSame(self::ROOT_ID, $response->getResults()[0]->getEntityId());
    }

    public function testRootAddressedByCategoryIdCanBeRenamed(): void
    {
        $this->categoryResource->method('getExistingByIds')->willReturn([
            self::ROOT_ID => [
                'entity_id' => self::ROOT_ID,
                'parent_id' => CategoryModel::TREE_ROOT_ID,
                'level' => 1,
                'path' => '1/2',
            ],
        ]);
        $this->writer->expects(self::once())->method('update')
            ->with(self::ROOT_ID, 'Main Catalog', self::anything(), null, 0)
            ->willReturn(true);
        // A root lives only in the resolver's root map — no path cache entry
        // covers it — so forget() cannot reach it.
        $this->pathResolver->expects(self::once())->method('forgetRoots')->with('Default Category');

        $definition = $this->definition('Default Category')
            ->setCategoryId(self::ROOT_ID)
            ->setName('Main Catalog');
        $response = $this->service->sync([$definition]);

        self::assertSame(1, $response->getUpdated());
    }

    public function testCatalogTreeRootIsNotWritable(): void
    {
        $this->categoryResource->method('getExistingByIds')->willReturn([
            CategoryModel::TREE_ROOT_ID => [
                'entity_id' => CategoryModel::TREE_ROOT_ID,
                'parent_id' => 0,
                'level' => 0,
                'path' => '1',
            ],
        ]);
        $this->writer->expects(self::never())->method('update');

        $definition = (new CategoryDefinition())
            ->setCategoryId(CategoryModel::TREE_ROOT_ID)
            ->setName('Root Catalog');
        $response = $this->service->sync([$definition]);

        $result = $response->getResults()[0];
        self::assertSame(CategorySyncResultInterface::REASON_ROOT_NOT_WRITABLE, $result->getReason());
        self::assertSame(CategoryModel::TREE_ROOT_ID, $result->getEntityId());
    }

    public function testTwoRootsSharingANameAreRefusedRatherThanGuessed(): void
    {
        $this->categoryResource->method('getChildIdsByParentIds')
            ->willReturn([CategoryModel::TREE_ROOT_ID => ['Default Category' => [self::ROOT_ID, 8]]]);
        $this->writer->expects(self::never())->method('update');
        $this->writer->expects(self::never())->method('create');

        $response = $this->service->sync([$this->definition('Default Category')]);

        $result = $response->getResults()[0];
        self::assertSame(CategorySyncResultInterface::REASON_AMBIGUOUS_PATH, $result->getReason());
        self::assertStringContainsString('2, 8', $result->getMessages()[0]);
    }

    public function testAmbiguousRootNameIsRefusedForADeeperPathToo(): void
    {
        // Reads elsewhere in the module take the lowest ID; a write cannot,
        // because the two roots are two different catalogs.
        $this->rootIds = ['Default Category' => [self::ROOT_ID, 8]];
        $this->writer->expects(self::never())->method('create');
        $this->writer->expects(self::never())->method('update');

        $response = $this->service->sync([$this->definition('Default Category/Men')]);

        $result = $response->getResults()[0];
        self::assertSame(CategorySyncResultInterface::REASON_AMBIGUOUS_PATH, $result->getReason());
        self::assertStringContainsString('2, 8', $result->getMessages()[0]);
    }

    /**
     * The pin is what makes an ambiguous root writable — and the only thing
     * that can on a first run, before any category_id has been recorded.
     */
    public function testARequestPinMakesAnAmbiguousRootWritable(): void
    {
        $this->rootIds = ['Default Category' => [self::ROOT_ID, 8]];
        $this->categoryResource->method('getChildrenByParentIds')->willReturn([]);
        $this->writer->expects(self::once())->method('create')
            ->with(8, 'Men', self::anything(), self::anything())
            ->willReturn(77);

        $response = $this->service->sync(
            [$this->definition('Default Category/Men')],
            (new ImportSettings())->setRootCategoryId(8)
        );

        $result = $response->getResults()[0];
        self::assertSame(CategorySyncResultInterface::STATUS_CREATED, $result->getStatus());
        self::assertSame(77, $result->getEntityId());
    }

    public function testAnEntryPinOverridesTheRequestPin(): void
    {
        // A payload spanning two root trees cannot name one root for all of it.
        $this->rootIds = ['Default Category' => [self::ROOT_ID, 8]];
        $this->categoryResource->method('getChildrenByParentIds')->willReturn([]);
        $this->writer->expects(self::once())->method('create')
            ->with(self::ROOT_ID, 'Men', self::anything(), self::anything())
            ->willReturn(77);

        $entry = $this->definition('Default Category/Men')->setRootCategoryId(self::ROOT_ID);

        $response = $this->service->sync([$entry], (new ImportSettings())->setRootCategoryId(8));

        self::assertSame(CategorySyncResultInterface::STATUS_CREATED, $response->getResults()[0]->getStatus());
    }

    public function testAPinNamingADifferentRootThanThePathIsRefused(): void
    {
        $this->rootIds = ['Default Category' => [self::ROOT_ID], 'Outdoor Catalog' => [self::OUTDOOR_ID]];
        $this->writer->expects(self::never())->method('create');

        $response = $this->service->sync(
            [$this->definition('Default Category/Men')],
            (new ImportSettings())->setRootCategoryId(self::OUTDOOR_ID)
        );

        $result = $response->getResults()[0];
        self::assertSame(CategorySyncResultInterface::REASON_UNKNOWN_ROOT, $result->getReason());
        self::assertStringContainsString('is named "Outdoor Catalog"', $result->getMessages()[0]);
    }

    public function testAPinThatIsNotARootAtAllIsRefused(): void
    {
        $this->writer->expects(self::never())->method('create');

        $response = $this->service->sync(
            [$this->definition('Default Category/Men')],
            (new ImportSettings())->setRootCategoryId(4242)
        );

        $result = $response->getResults()[0];
        self::assertSame(CategorySyncResultInterface::REASON_UNKNOWN_ROOT, $result->getReason());
        self::assertStringContainsString('4242 is not a root category', $result->getMessages()[0]);
    }

    public function testTheAmbiguityRefusalNamesThePinAsTheWayOut(): void
    {
        $this->rootIds = ['Default Category' => [self::ROOT_ID, 8]];

        $response = $this->service->sync([$this->definition('Default Category/Men')]);

        self::assertStringContainsString(
            'send root_category_id to pick one',
            $response->getResults()[0]->getMessages()[0]
        );
    }

    public function testRootCreatedInTheSameRequestIsVisibleToItsChildren(): void
    {
        $this->categoryResource->method('getChildIdsByParentIds')->willReturn([]);
        $created = [];
        $this->writer->method('create')
            ->willReturnCallback(function (int $parentId, string $name) use (&$created): int {
                $created[] = [$parentId, $name];
                if ($name === 'Outdoor Catalog') {
                    // Committed by the time the deeper bucket resolves parents,
                    // which is why the roots map must be re-read per bucket.
                    $this->rootIds['Outdoor Catalog'] = [self::OUTDOOR_ID];
                    return self::OUTDOOR_ID;
                }
                return self::MEN_ID;
            });

        $response = $this->service->sync([
            $this->definition('Outdoor Catalog/Tents'),
            $this->definition('Outdoor Catalog'),
        ]);

        self::assertSame(
            [[CategoryModel::TREE_ROOT_ID, 'Outdoor Catalog'], [self::OUTDOOR_ID, 'Tents']],
            $created
        );
        self::assertSame(2, $response->getCreated());
    }

    public function testRootRenameMarksDescendantsInTheSameRequestAsStale(): void
    {
        $this->categoryResource->method('getExistingByIds')->willReturn([
            self::ROOT_ID => [
                'entity_id' => self::ROOT_ID,
                'parent_id' => CategoryModel::TREE_ROOT_ID,
                'level' => 1,
                'path' => '1/2',
            ],
        ]);
        $this->categoryResource->method('getChildIdsByParentIds')
            ->willReturn([CategoryModel::TREE_ROOT_ID => ['Default Category' => [self::ROOT_ID]]]);
        $this->writer->method('update')->willReturn(true);

        $response = $this->service->sync([
            $this->definition('Default Category')->setCategoryId(self::ROOT_ID)->setName('Main Catalog'),
            $this->definition('Default Category/Men'),
        ]);

        $byPath = [];
        foreach ($response->getResults() as $result) {
            $byPath[$result->getPath()] = $result;
        }
        self::assertSame(CategorySyncResultInterface::STATUS_UPDATED, $byPath['Default Category']->getStatus());
        self::assertSame(
            CategorySyncResultInterface::REASON_STALE_PARENT_PATH,
            $byPath['Default Category/Men']->getReason()
        );
    }

    public function testRootCannotBeCreatedAtStoreScope(): void
    {
        $this->storeId = 1;
        $this->categoryResource->method('getChildIdsByParentIds')->willReturn([]);
        $this->writer->expects(self::never())->method('create');

        $response = $this->service->sync([$this->definition('Outdoor Catalog')]);

        self::assertSame(
            CategorySyncResultInterface::REASON_STORE_SCOPE_STRUCTURAL_CHANGE,
            $response->getResults()[0]->getReason()
        );
    }

    public function testStoreScopedWriteToAnotherStoresTreeIsRefused(): void
    {
        $this->storeId = 1;
        $this->storeRootId = self::ROOT_ID;
        $this->rootIds['Outdoor Catalog'] = [self::OUTDOOR_ID];
        $this->categoryResource->method('getChildIdsByParentIds')
            ->willReturn([self::OUTDOOR_ID => ['Tents' => [self::TENTS_ID]]]);
        // The write would succeed and be invisible on the storefront the caller
        // named, which is worse than refusing.
        $this->writer->expects(self::never())->method('update');

        $response = $this->service->sync([$this->definition('Outdoor Catalog/Tents')->setIsActive(0)]);

        $result = $response->getResults()[0];
        self::assertSame(CategorySyncResultInterface::REASON_WRONG_STORE_ROOT, $result->getReason());
        self::assertSame(self::TENTS_ID, $result->getEntityId());
    }

    public function testStoreScopedWriteByCategoryIdOutsideTheStoreRootIsRefused(): void
    {
        $this->storeId = 1;
        $this->storeRootId = self::ROOT_ID;
        $this->categoryResource->method('getExistingByIds')->willReturn([
            self::TENTS_ID => [
                'entity_id' => self::TENTS_ID,
                'parent_id' => self::OUTDOOR_ID,
                'level' => 2,
                'path' => '1/7/12',
            ],
        ]);
        $this->writer->expects(self::never())->method('update');

        $definition = (new CategoryDefinition())->setCategoryId(self::TENTS_ID)->setName('Tents');
        $response = $this->service->sync([$definition]);

        $result = $response->getResults()[0];
        self::assertSame(CategorySyncResultInterface::REASON_WRONG_STORE_ROOT, $result->getReason());
        self::assertSame(self::TENTS_ID, $result->getEntityId());
    }

    public function testStoreScopedWriteUnderTheStoreRootIsAllowed(): void
    {
        $this->storeId = 1;
        $this->storeRootId = self::ROOT_ID;
        $this->categoryResource->method('getChildIdsByParentIds')
            ->willReturn([self::ROOT_ID => ['Men' => [self::MEN_ID]]]);
        $this->writer->expects(self::once())->method('update')
            ->with(self::MEN_ID, 'Men', self::anything(), null, 1)
            ->willReturn(true);

        $response = $this->service->sync([$this->definition('Default Category/Men')->setIsActive(0)]);

        self::assertSame(1, $response->getUpdated());
        self::assertNull($response->getResults()[0]->getReason());
    }

    public function testAmbiguityIsReportedBeforeTheStoreRootMismatch(): void
    {
        // Which category is wrong-rooted cannot be said before knowing which
        // category was meant.
        $this->storeId = 1;
        $this->storeRootId = self::ROOT_ID;
        $this->rootIds['Outdoor Catalog'] = [self::OUTDOOR_ID, 9];

        $response = $this->service->sync([$this->definition('Outdoor Catalog/Tents')]);

        self::assertSame(
            CategorySyncResultInterface::REASON_AMBIGUOUS_PATH,
            $response->getResults()[0]->getReason()
        );
    }

    public function testMissingCategoryInAnotherStoresTreeReportsTheRootMismatch(): void
    {
        // Not store_scope_structural_change: "omit store_view_code to create it"
        // would be wrong advice for a caller pointed at the wrong tree entirely.
        $this->storeId = 1;
        $this->storeRootId = self::ROOT_ID;
        $this->rootIds['Outdoor Catalog'] = [self::OUTDOOR_ID];
        $this->categoryResource->method('getChildIdsByParentIds')->willReturn([]);
        $this->writer->expects(self::never())->method('create');

        $response = $this->service->sync([$this->definition('Outdoor Catalog/Tents')]);

        self::assertSame(
            CategorySyncResultInterface::REASON_WRONG_STORE_ROOT,
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
            ->with(self::MEN_ID, null, self::anything(), null, 0)
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

        $result = $response->getResults()[0];
        self::assertSame(CategorySyncResultInterface::REASON_STORE_SCOPE_STRUCTURAL_CHANGE, $result->getReason());
        // Refused after the category was identified, so the caller still learns
        // which one it was.
        self::assertSame(self::MEN_ID, $result->getEntityId());
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

    // --- Moving -----------------------------------------------------------

    public function testParentPathMovesTheCategory(): void
    {
        $this->stubExistingRows([
            self::SHIRTS_ID => self::row(self::SHIRTS_ID, self::MEN_ID, 3, '1/2/10/11'),
            self::WOMEN_ID => self::row(self::WOMEN_ID, self::ROOT_ID, 2, '1/2/20'),
        ]);
        $this->pathResolver->method('lookupPaths')->willReturn([
            'Default Category/Men' => self::MEN_ID,
            'Default Category/Women' => self::WOMEN_ID,
        ]);
        $this->writer->expects(self::once())->method('move')->with(self::SHIRTS_ID, self::WOMEN_ID);

        $definition = $this->definition('Default Category/Men/Shirts')
            ->setCategoryId(self::SHIRTS_ID)
            ->setParentPath('Default Category/Women');
        $response = $this->service->sync([$definition]);

        $result = $response->getResults()[0];
        self::assertSame(CategorySyncResultInterface::STATUS_UPDATED, $result->getStatus());
        self::assertNull($result->getReason());
        self::assertSame(self::SHIRTS_ID, $result->getEntityId());
        self::assertSame(1, $response->getUpdated());
    }

    public function testAMoveWithNoAttributeChangeStillReportsUpdated(): void
    {
        $this->stubExistingRows([
            self::SHIRTS_ID => self::row(self::SHIRTS_ID, self::MEN_ID, 3, '1/2/10/11'),
            self::WOMEN_ID => self::row(self::WOMEN_ID, self::ROOT_ID, 2, '1/2/20'),
        ]);
        $this->pathResolver->method('lookupPaths')->willReturn(['Default Category/Women' => self::WOMEN_ID]);
        // Nothing differs on the attribute side, so the writer reports no change.
        $this->writer->method('update')->willReturn(false);
        $this->writer->expects(self::once())->method('move');

        $definition = (new CategoryDefinition())
            ->setCategoryId(self::SHIRTS_ID)
            ->setName('Shirts')
            ->setParentPath('Default Category/Women');
        $response = $this->service->sync([$definition]);

        // The category is somewhere else than it was; "unchanged" would be a lie.
        self::assertSame(CategorySyncResultInterface::STATUS_UPDATED, $response->getResults()[0]->getStatus());
    }

    public function testParentCategoryIdWinsOverParentPath(): void
    {
        $this->stubExistingRows([
            self::SHIRTS_ID => self::row(self::SHIRTS_ID, self::MEN_ID, 3, '1/2/10/11'),
            self::WOMEN_ID => self::row(self::WOMEN_ID, self::ROOT_ID, 2, '1/2/20'),
        ]);
        // parent_path is not consulted at all, so no path lookup is needed for it.
        $this->writer->expects(self::once())->method('move')->with(self::SHIRTS_ID, self::WOMEN_ID);

        $definition = (new CategoryDefinition())
            ->setCategoryId(self::SHIRTS_ID)
            ->setName('Shirts')
            ->setParentPath('Default Category/Somewhere Ambiguous')
            ->setParentCategoryId(self::WOMEN_ID);
        $response = $this->service->sync([$definition]);

        self::assertSame(CategorySyncResultInterface::STATUS_UPDATED, $response->getResults()[0]->getStatus());
    }

    public function testMoveOntoTheCurrentParentIsUnchanged(): void
    {
        $this->stubExistingRows([
            self::SHIRTS_ID => self::row(self::SHIRTS_ID, self::MEN_ID, 3, '1/2/10/11'),
        ]);
        $this->writer->method('update')->willReturn(false);
        // This is the replayed-payload case and it has to cost nothing.
        $this->writer->expects(self::never())->method('move');

        $definition = (new CategoryDefinition())
            ->setCategoryId(self::SHIRTS_ID)
            ->setName('Shirts')
            ->setParentCategoryId(self::MEN_ID);
        $response = $this->service->sync([$definition]);

        self::assertSame(CategorySyncResultInterface::STATUS_UNCHANGED, $response->getResults()[0]->getStatus());
    }

    public function testMoveWithoutCategoryIdIsRefused(): void
    {
        $this->categoryResource->method('getChildIdsByParentIds')
            ->willReturn([self::MEN_ID => ['Shirts' => [self::SHIRTS_ID]]]);
        $this->pathResolver->method('lookupPaths')->willReturn(['Default Category/Men' => self::MEN_ID]);
        $this->writer->expects(self::never())->method('move');

        $definition = $this->definition('Default Category/Men/Shirts')
            ->setParentPath('Default Category/Women');
        $response = $this->service->sync([$definition]);

        self::assertSame(
            CategorySyncResultInterface::REASON_MOVE_REQUIRES_CATEGORY_ID,
            $response->getResults()[0]->getReason()
        );
    }

    public function testMoveIntoOwnDescendantIsRefused(): void
    {
        $this->stubExistingRows([
            self::MEN_ID => self::row(self::MEN_ID, self::ROOT_ID, 2, '1/2/10'),
            self::SHIRTS_ID => self::row(self::SHIRTS_ID, self::MEN_ID, 3, '1/2/10/11'),
        ]);
        $this->writer->expects(self::never())->method('move');

        $definition = (new CategoryDefinition())
            ->setCategoryId(self::MEN_ID)
            ->setName('Men')
            ->setParentCategoryId(self::SHIRTS_ID);
        $response = $this->service->sync([$definition]);

        $result = $response->getResults()[0];
        self::assertSame(CategorySyncResultInterface::REASON_MOVE_INTO_DESCENDANT, $result->getReason());
        self::assertSame(self::MEN_ID, $result->getEntityId());
    }

    public function testMoveUnderItselfIsRefused(): void
    {
        $this->stubExistingRows([
            self::MEN_ID => self::row(self::MEN_ID, self::ROOT_ID, 2, '1/2/10'),
        ]);
        $this->writer->expects(self::never())->method('move');

        $definition = (new CategoryDefinition())
            ->setCategoryId(self::MEN_ID)
            ->setName('Men')
            ->setParentCategoryId(self::MEN_ID);
        $response = $this->service->sync([$definition]);

        self::assertSame(
            CategorySyncResultInterface::REASON_MOVE_INTO_DESCENDANT,
            $response->getResults()[0]->getReason()
        );
    }

    public function testMoveToAnUnresolvableDestinationIsRefused(): void
    {
        $this->stubExistingRows([
            self::SHIRTS_ID => self::row(self::SHIRTS_ID, self::MEN_ID, 3, '1/2/10/11'),
        ]);
        $this->pathResolver->method('lookupPaths')->willReturn([]);
        $this->writer->expects(self::never())->method('move');

        $definition = (new CategoryDefinition())
            ->setCategoryId(self::SHIRTS_ID)
            ->setName('Shirts')
            ->setParentPath('Default Category/Nowhere/Deep');
        $response = $this->service->sync([$definition]);

        self::assertSame(
            CategorySyncResultInterface::REASON_PARENT_NOT_FOUND,
            $response->getResults()[0]->getReason()
        );
    }

    public function testMoveToAnUnknownRootIsRefused(): void
    {
        $this->stubExistingRows([
            self::SHIRTS_ID => self::row(self::SHIRTS_ID, self::MEN_ID, 3, '1/2/10/11'),
        ]);
        $this->writer->expects(self::never())->method('move');

        $definition = (new CategoryDefinition())
            ->setCategoryId(self::SHIRTS_ID)
            ->setName('Shirts')
            ->setParentPath('No Such Root/Women');
        $response = $this->service->sync([$definition]);

        self::assertSame(
            CategorySyncResultInterface::REASON_UNKNOWN_ROOT,
            $response->getResults()[0]->getReason()
        );
    }

    public function testMoveToAnAmbiguousDestinationRootIsRefused(): void
    {
        $this->rootIds = ['Default Category' => [self::ROOT_ID, 9]];
        $this->stubExistingRows([
            self::SHIRTS_ID => self::row(self::SHIRTS_ID, self::MEN_ID, 3, '1/2/10/11'),
        ]);
        $this->writer->expects(self::never())->method('move');

        $definition = (new CategoryDefinition())
            ->setCategoryId(self::SHIRTS_ID)
            ->setName('Shirts')
            ->setParentPath('Default Category/Women');
        $response = $this->service->sync([$definition]);

        self::assertSame(
            CategorySyncResultInterface::REASON_AMBIGUOUS_PATH,
            $response->getResults()[0]->getReason()
        );
    }

    public function testMoveIsRefusedAtStoreScope(): void
    {
        $this->storeId = 1;
        $this->stubExistingRows([
            self::SHIRTS_ID => self::row(self::SHIRTS_ID, self::MEN_ID, 3, '1/2/10/11'),
            self::WOMEN_ID => self::row(self::WOMEN_ID, self::ROOT_ID, 2, '1/2/20'),
        ]);
        $this->writer->expects(self::never())->method('move');

        $definition = (new CategoryDefinition())
            ->setCategoryId(self::SHIRTS_ID)
            ->setName('Shirts')
            ->setParentCategoryId(self::WOMEN_ID);
        $response = $this->service->sync([$definition]);

        self::assertSame(
            CategorySyncResultInterface::REASON_STORE_SCOPE_STRUCTURAL_CHANGE,
            $response->getResults()[0]->getReason()
        );
    }

    public function testMovesDisabledIsRefused(): void
    {
        $service = $this->buildService($this->configMock(allowMove: false));
        $this->stubExistingRows([
            self::SHIRTS_ID => self::row(self::SHIRTS_ID, self::MEN_ID, 3, '1/2/10/11'),
            self::WOMEN_ID => self::row(self::WOMEN_ID, self::ROOT_ID, 2, '1/2/20'),
        ]);
        $this->writer->expects(self::never())->method('move');

        $definition = (new CategoryDefinition())
            ->setCategoryId(self::SHIRTS_ID)
            ->setName('Shirts')
            ->setParentCategoryId(self::WOMEN_ID);
        $response = $service->sync([$definition]);

        $result = $response->getResults()[0];
        self::assertSame(CategorySyncResultInterface::REASON_MOVE_DISABLED, $result->getReason());
        self::assertSame(self::SHIRTS_ID, $result->getEntityId());
    }

    public function testAStoreValuesBlockWritesInItsOwnScope(): void
    {
        $this->categoryResource->method('getChildIdsByParentIds')
            ->willReturn([self::ROOT_ID => ['Men' => [self::MEN_ID]]]);
        $writes = [];
        $this->writer->method('update')->willReturnCallback(
            function (int $id, ?string $name, $values, ?int $position, int $storeId) use (&$writes): bool {
                $writes[] = [$storeId, $name];
                return true;
            }
        );

        $definition = $this->definition('Default Category/Men')
            ->setStoreValues([(new CategoryStoreValues())->setStoreId(1)->setName('Herren')]);
        $response = $this->service->sync([$definition]);

        // Default scope first — a block cannot be written before the values it
        // falls back to exist.
        self::assertSame([[0, 'Men'], [1, 'Herren']], $writes);

        $result = $response->getResults()[0];
        self::assertSame(CategorySyncResultInterface::STATUS_UPDATED, $result->getStatus());
        self::assertSame(self::ROOT_ID, $result->getRootCategoryId());
        $storeResults = $result->getStoreResults();
        self::assertCount(1, $storeResults);
        self::assertSame(1, $storeResults[0]->getStoreId());
        self::assertSame(CategoryStoreResultInterface::STATUS_UPDATED, $storeResults[0]->getStatus());
    }

    public function testAScopeWhereNothingDifferedIsUnchangedRatherThanSkipped(): void
    {
        // The property that makes a replayed payload free has to survive per
        // scope, or a re-run looks like it did nothing rather than nothing new.
        $this->categoryResource->method('getChildIdsByParentIds')
            ->willReturn([self::ROOT_ID => ['Men' => [self::MEN_ID]]]);
        $this->writer->method('update')->willReturn(false);

        $definition = $this->definition('Default Category/Men')
            ->setStoreValues([(new CategoryStoreValues())->setStoreId(1)->setName('Herren')]);
        $response = $this->service->sync([$definition]);

        $storeResults = $response->getResults()[0]->getStoreResults();
        self::assertSame(CategoryStoreResultInterface::STATUS_UNCHANGED, $storeResults[0]->getStatus());
        self::assertNull($storeResults[0]->getReason());
    }

    public function testAnUnknownStoreViewCostsThatBlockAndNothingElse(): void
    {
        $this->categoryResource->method('getChildIdsByParentIds')
            ->willReturn([self::ROOT_ID => ['Men' => [self::MEN_ID]]]);
        $this->writer->method('update')->willReturn(true);

        $definition = $this->definition('Default Category/Men')->setStoreValues([
            (new CategoryStoreValues())->setStoreId(99)->setName('Nowhere'),
            (new CategoryStoreValues())->setStoreId(1)->setName('Herren'),
        ]);
        $response = $this->service->sync([$definition]);

        $result = $response->getResults()[0];
        self::assertSame(CategorySyncResultInterface::STATUS_UPDATED, $result->getStatus());
        $storeResults = $result->getStoreResults();
        self::assertSame(CategorySyncResultInterface::REASON_UNKNOWN_STORE, $storeResults[0]->getReason());
        self::assertSame(CategoryStoreResultInterface::STATUS_UPDATED, $storeResults[1]->getStatus());
    }

    public function testABlockNamingTheDefaultScopeIsRefused(): void
    {
        // Accepting it would let a block overwrite the value every other store
        // view falls back to.
        $this->categoryResource->method('getChildIdsByParentIds')
            ->willReturn([self::ROOT_ID => ['Men' => [self::MEN_ID]]]);
        $this->writer->method('update')->willReturn(true);

        $definition = $this->definition('Default Category/Men')
            ->setStoreValues([(new CategoryStoreValues())->setStoreId(0)->setName('Nope')]);
        $response = $this->service->sync([$definition]);

        $storeResults = $response->getResults()[0]->getStoreResults();
        self::assertSame(CategorySyncResultInterface::REASON_INVALID_DEFINITION, $storeResults[0]->getReason());
        self::assertStringContainsString('cannot name the default scope', $storeResults[0]->getMessages()[0]);
    }

    public function testASecondBlockForOneStoreViewIsRefusedRatherThanSilentlyLosing(): void
    {
        $this->categoryResource->method('getChildIdsByParentIds')
            ->willReturn([self::ROOT_ID => ['Men' => [self::MEN_ID]]]);
        $this->writer->method('update')->willReturn(true);

        $definition = $this->definition('Default Category/Men')->setStoreValues([
            (new CategoryStoreValues())->setStoreId(1)->setName('Herren'),
            (new CategoryStoreValues())->setStoreId(1)->setName('Männer'),
        ]);
        $response = $this->service->sync([$definition]);

        $storeResults = $response->getResults()[0]->getStoreResults();
        self::assertSame(CategoryStoreResultInterface::STATUS_UPDATED, $storeResults[0]->getStatus());
        self::assertSame(CategorySyncResultInterface::REASON_INVALID_DEFINITION, $storeResults[1]->getReason());
        self::assertStringContainsString('merge them', $storeResults[1]->getMessages()[0]);
    }

    public function testABlockPointedAtAStoreShowingAnotherTreeIsRefused(): void
    {
        // The write would succeed and be invisible on the storefront the block
        // named — the same reasoning wrong_store_root applies to a request.
        $this->storeRoots = [1 => self::OUTDOOR_ID];
        $this->categoryResource->method('getChildIdsByParentIds')
            ->willReturn([self::ROOT_ID => ['Men' => [self::MEN_ID]]]);
        $this->writer->expects(self::once())->method('update')->willReturn(true);

        $definition = $this->definition('Default Category/Men')
            ->setStoreValues([(new CategoryStoreValues())->setStoreId(1)->setName('Herren')]);
        $response = $this->service->sync([$definition]);

        $storeResults = $response->getResults()[0]->getStoreResults();
        self::assertSame(CategorySyncResultInterface::REASON_WRONG_STORE_ROOT, $storeResults[0]->getReason());
    }

    public function testACategoryThatWasSkippedReportsNoScopesAtAll(): void
    {
        // There is nothing to localize and no scope to report against; the
        // entry's own reason is the whole story.
        $this->rootIds = ['Default Category' => [self::ROOT_ID, 8]];
        $this->writer->expects(self::never())->method('update');

        $definition = $this->definition('Default Category/Men')
            ->setStoreValues([(new CategoryStoreValues())->setStoreId(1)->setName('Herren')]);
        $response = $this->service->sync([$definition]);

        $result = $response->getResults()[0];
        self::assertSame(CategorySyncResultInterface::REASON_AMBIGUOUS_PATH, $result->getReason());
        self::assertNull($result->getStoreResults());
    }

    public function testAPayloadWithoutStoreValuesReportsNoStoreResults(): void
    {
        $this->categoryResource->method('getChildIdsByParentIds')
            ->willReturn([self::ROOT_ID => ['Men' => [self::MEN_ID]]]);
        $this->writer->method('update')->willReturn(true);

        $response = $this->service->sync([$this->definition('Default Category/Men')]);

        self::assertNull($response->getResults()[0]->getStoreResults());
    }

    /**
     * The two roots are two different catalogs, so this takes the category, its
     * subtree and their product assignments off one storefront and onto
     * another. Core's move performs it without complaint, and no other guard
     * here catches it: the descendant check only looks downwards, and
     * root_in_use only fires for a store group's own root.
     */
    public function testAMoveIntoAnotherRootTreeIsRefusedByDefault(): void
    {
        $this->stubExistingRows([
            self::SHIRTS_ID => self::row(self::SHIRTS_ID, self::MEN_ID, 3, '1/2/10/11'),
            self::TENTS_ID => self::row(self::TENTS_ID, self::OUTDOOR_ID, 2, '1/7/12'),
        ]);
        $this->writer->expects(self::never())->method('move');

        $definition = (new CategoryDefinition())
            ->setCategoryId(self::SHIRTS_ID)
            ->setName('Shirts')
            ->setParentCategoryId(self::TENTS_ID);
        $response = $this->service->sync([$definition]);

        $result = $response->getResults()[0];
        self::assertSame(CategorySyncResultInterface::REASON_CROSS_ROOT_MOVE, $result->getReason());
        self::assertStringContainsString('different catalogs', $result->getMessages()[0]);
        self::assertSame(self::SHIRTS_ID, $result->getEntityId());
    }

    public function testAMoveIntoAnotherRootTreeIsAllowedWhenSwitchedOn(): void
    {
        $service = $this->buildService($this->configMock(allowCrossRootMove: true));
        $this->stubExistingRows([
            self::SHIRTS_ID => self::row(self::SHIRTS_ID, self::MEN_ID, 3, '1/2/10/11'),
            self::TENTS_ID => self::row(self::TENTS_ID, self::OUTDOOR_ID, 2, '1/7/12'),
        ]);
        $this->writer->expects(self::once())->method('move')->with(self::SHIRTS_ID, self::TENTS_ID);

        $definition = (new CategoryDefinition())
            ->setCategoryId(self::SHIRTS_ID)
            ->setName('Shirts')
            ->setParentCategoryId(self::TENTS_ID);
        $response = $service->sync([$definition]);

        $result = $response->getResults()[0];
        self::assertSame(CategorySyncResultInterface::STATUS_UPDATED, $result->getStatus());
        // The result reports the tree it ended up in, not the one it left.
        self::assertSame(self::OUTDOOR_ID, $result->getRootCategoryId());
    }

    public function testAMoveInsideOneRootTreeIsUnaffected(): void
    {
        $this->stubExistingRows([
            self::SHIRTS_ID => self::row(self::SHIRTS_ID, self::MEN_ID, 3, '1/2/10/11'),
            self::WOMEN_ID => self::row(self::WOMEN_ID, self::ROOT_ID, 2, '1/2/20'),
        ]);
        $this->writer->expects(self::once())->method('move')->with(self::SHIRTS_ID, self::WOMEN_ID);

        $definition = (new CategoryDefinition())
            ->setCategoryId(self::SHIRTS_ID)
            ->setName('Shirts')
            ->setParentCategoryId(self::WOMEN_ID);
        $response = $this->service->sync([$definition]);

        self::assertNull($response->getResults()[0]->getReason());
    }

    public function testMovingAStoreGroupRootIsRefused(): void
    {
        $this->stubExistingRows([
            self::ROOT_ID => self::row(self::ROOT_ID, 1, 1, '1/2'),
            self::OUTDOOR_ID => self::row(self::OUTDOOR_ID, 1, 1, '1/7'),
        ]);
        $this->categoryResource->method('getStoreGroupRootCategoryIds')->willReturn([self::ROOT_ID => true]);
        $this->writer->expects(self::never())->method('move');

        // Demoting it would leave the storefront pointing at a non-root.
        $definition = (new CategoryDefinition())
            ->setCategoryId(self::ROOT_ID)
            ->setName('Default Category')
            ->setParentCategoryId(self::OUTDOOR_ID);
        $response = $this->service->sync([$definition]);

        self::assertSame(
            CategorySyncResultInterface::REASON_ROOT_IN_USE,
            $response->getResults()[0]->getReason()
        );
    }

    public function testPromotingToARootIsAllowed(): void
    {
        $this->stubExistingRows([
            self::MEN_ID => self::row(self::MEN_ID, self::ROOT_ID, 2, '1/2/10'),
            CategoryModel::TREE_ROOT_ID => self::row(CategoryModel::TREE_ROOT_ID, 0, 0, '1'),
        ]);
        $this->writer->expects(self::once())
            ->method('move')
            ->with(self::MEN_ID, CategoryModel::TREE_ROOT_ID);
        // The root name => ID map the resolver memoizes has a new entry.
        $this->pathResolver->expects(self::once())->method('forgetRoots');

        $definition = (new CategoryDefinition())
            ->setCategoryId(self::MEN_ID)
            ->setName('Men')
            ->setParentCategoryId(CategoryModel::TREE_ROOT_ID);
        $response = $this->service->sync([$definition]);

        $result = $response->getResults()[0];
        self::assertSame(CategorySyncResultInterface::STATUS_UPDATED, $result->getStatus());
        self::assertNotEmpty(array_filter(
            $result->getMessages(),
            static fn (string $m): bool => str_contains($m, 'level-1 root')
        ));
    }

    public function testAPathImplyingADifferentParentIsStillRefusedWithoutADestination(): void
    {
        // The behaviour the endpoint has always had, and the reason parent_path
        // exists: a caller replaying a path they stored before an earlier move
        // must not have it read as "put it back".
        $this->stubExistingRows([
            self::SHIRTS_ID => self::row(self::SHIRTS_ID, 77, 3, '1/2/77/11'),
        ]);
        $this->pathResolver->method('lookupPaths')->willReturn(['Default Category/Men' => self::MEN_ID]);
        $this->writer->expects(self::never())->method('move');
        $this->writer->expects(self::never())->method('update');

        $definition = $this->definition('Default Category/Men/Shirts')->setCategoryId(self::SHIRTS_ID);
        $response = $this->service->sync([$definition]);

        self::assertSame(
            CategorySyncResultInterface::REASON_MOVE_NOT_SUPPORTED,
            $response->getResults()[0]->getReason()
        );
    }

    public function testAStalePathIsIgnoredWhenADestinationIsGiven(): void
    {
        // Same stale path as above, but now the caller said where it should be.
        // The path stops being a cross-check and the move goes ahead.
        $this->stubExistingRows([
            self::SHIRTS_ID => self::row(self::SHIRTS_ID, 77, 3, '1/2/77/11'),
            self::WOMEN_ID => self::row(self::WOMEN_ID, self::ROOT_ID, 2, '1/2/20'),
        ]);
        $this->pathResolver->method('lookupPaths')->willReturn([
            'Default Category/Men' => self::MEN_ID,
            'Default Category/Women' => self::WOMEN_ID,
        ]);
        $this->writer->expects(self::once())->method('move')->with(self::SHIRTS_ID, self::WOMEN_ID);

        $definition = $this->definition('Default Category/Men/Shirts')
            ->setCategoryId(self::SHIRTS_ID)
            ->setParentPath('Default Category/Women');
        $response = $this->service->sync([$definition]);

        self::assertSame(CategorySyncResultInterface::STATUS_UPDATED, $response->getResults()[0]->getStatus());
    }

    public function testMovedSubtreeAndBothParentsAreInvalidated(): void
    {
        $this->stubExistingRows([
            self::SHIRTS_ID => self::row(self::SHIRTS_ID, self::MEN_ID, 3, '1/2/10/11'),
            self::WOMEN_ID => self::row(self::WOMEN_ID, self::ROOT_ID, 2, '1/2/20'),
        ]);
        $this->categoryResource->method('getDescendantIds')->willReturn([99]);
        $this->writer->method('update')->willReturn(false);

        $touched = null;
        $this->invalidationHandler->method('execute')
            ->willReturnCallback(function (array $ids, array $removed = []) use (&$touched): void {
                $touched = $ids;
            });

        $definition = (new CategoryDefinition())
            ->setCategoryId(self::SHIRTS_ID)
            ->setName('Shirts')
            ->setParentCategoryId(self::WOMEN_ID);
        $this->service->sync([$definition]);

        // The subtree was re-pathed, and both parents' children lists changed.
        self::assertNotNull($touched);
        foreach ([self::SHIRTS_ID, 99, self::MEN_ID, self::WOMEN_ID] as $expected) {
            self::assertContains($expected, $touched);
        }
    }

    public function testASecondStructuralChangeInsideAMovedSubtreeIsRefused(): void
    {
        $this->stubExistingRows([
            self::MEN_ID => self::row(self::MEN_ID, self::ROOT_ID, 2, '1/2/10'),
            self::SHIRTS_ID => self::row(self::SHIRTS_ID, self::MEN_ID, 3, '1/2/10/11'),
            self::WOMEN_ID => self::row(self::WOMEN_ID, self::ROOT_ID, 2, '1/2/20'),
            self::OUTDOOR_ID => self::row(self::OUTDOOR_ID, 1, 1, '1/7'),
        ]);
        // Shirts is inside the subtree that the first entry moves.
        $this->categoryResource->method('getDescendantIds')->willReturnCallback(
            static fn (array $ids): array => $ids === [self::MEN_ID] ? [self::SHIRTS_ID] : []
        );
        $this->writer->method('update')->willReturn(false);
        // Only the first move is applied.
        $this->writer->expects(self::once())->method('move');

        $response = $this->service->sync([
            (new CategoryDefinition())
                ->setCategoryId(self::MEN_ID)
                ->setName('Men')
                ->setParentCategoryId(self::WOMEN_ID),
            (new CategoryDefinition())
                ->setCategoryId(self::SHIRTS_ID)
                ->setName('Shirts')
                ->setParentCategoryId(self::OUTDOOR_ID),
        ]);

        $byId = [];
        foreach ($response->getResults() as $result) {
            $byId[$result->getEntityId()] = $result;
        }
        self::assertSame(CategorySyncResultInterface::STATUS_UPDATED, $byId[self::MEN_ID]->getStatus());
        // Core memoizes children per request, so regenerating rewrites for the
        // second move would use the pre-move tree.
        self::assertSame(
            CategorySyncResultInterface::REASON_STALE_PARENT_PATH,
            $byId[self::SHIRTS_ID]->getReason()
        );
    }

    public function testMoveInvalidatesTheResolversPathCache(): void
    {
        $this->stubExistingRows([
            self::SHIRTS_ID => self::row(self::SHIRTS_ID, self::MEN_ID, 3, '1/2/10/11'),
            self::WOMEN_ID => self::row(self::WOMEN_ID, self::ROOT_ID, 2, '1/2/20'),
        ]);
        $this->writer->method('update')->willReturn(false);
        // Every cached path under the old and the new location now resolves to
        // the wrong node.
        $this->pathResolver->expects(self::once())->method('forgetAllPaths');

        $definition = (new CategoryDefinition())
            ->setCategoryId(self::SHIRTS_ID)
            ->setName('Shirts')
            ->setParentCategoryId(self::WOMEN_ID);
        $this->service->sync([$definition]);
    }

    // --- Destination collisions -------------------------------------------

    public function testCreateIsRefusedWhenASiblingAlreadyUsesTheDerivedSlug(): void
    {
        $this->categoryResource->method('getChildIdsByParentIds')->willReturn([]);
        $this->pathResolver->method('lookupPaths')->willReturn(['Default Category/Men' => self::MEN_ID]);
        $this->writer->method('findNewChildConflict')
            ->willReturn(['kind' => 'url_key', 'value' => 'clearance', 'category_id' => 99]);
        $this->writer->expects(self::never())->method('create');

        $response = $this->service->sync([$this->definition('Default Category/Men/Clearance')]);

        $result = $response->getResults()[0];
        self::assertSame(CategorySyncResultInterface::REASON_DESTINATION_URL_KEY_TAKEN, $result->getReason());
        // Nothing was created, so there is no ID to report.
        self::assertNull($result->getEntityId());
        self::assertStringContainsString('99', $result->getMessages()[0]);
    }

    public function testCreateProceedsWhenTheSlugIsFree(): void
    {
        $this->categoryResource->method('getChildIdsByParentIds')->willReturn([]);
        $this->pathResolver->method('lookupPaths')->willReturn(['Default Category/Men' => self::MEN_ID]);
        $this->writer->method('findNewChildConflict')->willReturn(null);
        $this->writer->expects(self::once())->method('create')
            ->with(self::MEN_ID, 'Clearance')
            ->willReturn(53);

        $response = $this->service->sync([$this->definition('Default Category/Men/Clearance')]);

        self::assertSame(1, $response->getCreated());
    }

    public function testTheCreateCollisionCheckIsAskedAboutTheResolvedParentAndLeafName(): void
    {
        $this->categoryResource->method('getChildIdsByParentIds')->willReturn([]);
        $this->pathResolver->method('lookupPaths')->willReturn(['Default Category/Men' => self::MEN_ID]);
        $this->writer->expects(self::once())->method('findNewChildConflict')
            ->with(self::MEN_ID, 'Clearance', self::anything())
            ->willReturn(null);
        $this->writer->method('create')->willReturn(53);

        $this->service->sync([$this->definition('Default Category/Men/Clearance')]);
    }

    public function testAnExistingSiblingNameIsUpdatedAndNeverCollisionChecked(): void
    {
        // A name collision cannot reach the create branch: the sibling is found
        // and updated instead, so there is nothing to check.
        $this->categoryResource->method('getChildIdsByParentIds')
            ->willReturn([self::MEN_ID => ['Clearance' => [53]]]);
        $this->stubExistingRows([53 => self::row(53, self::MEN_ID, 3, '1/2/10/53')]);
        $this->pathResolver->method('lookupPaths')->willReturn(['Default Category/Men' => self::MEN_ID]);
        $this->writer->method('update')->willReturn(true);
        $this->writer->expects(self::never())->method('create');
        $this->writer->expects(self::never())->method('findNewChildConflict');

        $response = $this->service->sync([$this->definition('Default Category/Men/Clearance')]);

        self::assertSame(1, $response->getUpdated());
    }

    public function testMoveIsRefusedWhenTheDestinationAlreadyHasThatName(): void
    {
        $this->stubExistingRows([
            self::SHIRTS_ID => self::row(self::SHIRTS_ID, self::MEN_ID, 3, '1/2/10/11'),
            self::WOMEN_ID => self::row(self::WOMEN_ID, self::ROOT_ID, 2, '1/2/20'),
        ]);
        $this->writer->method('findSiblingConflict')
            ->willReturn(['kind' => 'name', 'value' => 'Shirts', 'category_id' => 33]);
        // Nothing in catalog_category_entity forbids duplicate sibling names, so
        // the write would succeed and leave the path ambiguous forever.
        $this->writer->expects(self::never())->method('move');

        $definition = (new CategoryDefinition())
            ->setCategoryId(self::SHIRTS_ID)
            ->setName('Shirts')
            ->setParentCategoryId(self::WOMEN_ID);
        $response = $this->service->sync([$definition]);

        $result = $response->getResults()[0];
        self::assertSame(CategorySyncResultInterface::REASON_DESTINATION_NAME_TAKEN, $result->getReason());
        self::assertSame(self::SHIRTS_ID, $result->getEntityId());
        // The conflicting ID is what makes the refusal actionable.
        self::assertStringContainsString('33', $result->getMessages()[0]);
    }

    public function testMoveIsRefusedWhenTheDestinationAlreadyUsesThatUrlKey(): void
    {
        $this->stubExistingRows([
            self::SHIRTS_ID => self::row(self::SHIRTS_ID, self::MEN_ID, 3, '1/2/10/11'),
            self::WOMEN_ID => self::row(self::WOMEN_ID, self::ROOT_ID, 2, '1/2/20'),
        ]);
        $this->writer->method('findSiblingConflict')
            ->willReturn(['kind' => 'url_key', 'value' => 'shirts', 'category_id' => 33]);
        $this->writer->expects(self::never())->method('move');

        $definition = (new CategoryDefinition())
            ->setCategoryId(self::SHIRTS_ID)
            ->setName('Shirts')
            ->setParentCategoryId(self::WOMEN_ID);
        $response = $this->service->sync([$definition]);

        self::assertSame(
            CategorySyncResultInterface::REASON_DESTINATION_URL_KEY_TAKEN,
            $response->getResults()[0]->getReason()
        );
    }

    public function testTheMoveCollisionCheckAsksAboutTheDestinationParent(): void
    {
        $this->stubExistingRows([
            self::SHIRTS_ID => self::row(self::SHIRTS_ID, self::MEN_ID, 3, '1/2/10/11'),
            self::WOMEN_ID => self::row(self::WOMEN_ID, self::ROOT_ID, 2, '1/2/20'),
        ]);
        // Destination parent, and flagged as a move — a move collides on the name
        // the category already has, which the payload alone cannot reveal.
        $this->writer->expects(self::once())->method('findSiblingConflict')
            ->with(self::SHIRTS_ID, self::WOMEN_ID, 'Shirts', self::anything(), true)
            ->willReturn(null);

        $definition = (new CategoryDefinition())
            ->setCategoryId(self::SHIRTS_ID)
            ->setName('Shirts')
            ->setParentCategoryId(self::WOMEN_ID);
        $this->service->sync([$definition]);
    }

    public function testRenameIsRefusedWhenASiblingAlreadyHasThatName(): void
    {
        $this->stubExistingRows([
            self::SHIRTS_ID => self::row(self::SHIRTS_ID, self::MEN_ID, 3, '1/2/10/11'),
        ]);
        $this->writer->method('findSiblingConflict')
            ->willReturn(['kind' => 'name', 'value' => 'Coats', 'category_id' => 44]);
        $this->writer->expects(self::never())->method('update');

        $definition = (new CategoryDefinition())->setCategoryId(self::SHIRTS_ID)->setName('Coats');
        $response = $this->service->sync([$definition]);

        self::assertSame(
            CategorySyncResultInterface::REASON_DESTINATION_NAME_TAKEN,
            $response->getResults()[0]->getReason()
        );
    }

    public function testTheRenameCollisionCheckAsksAboutTheCurrentParent(): void
    {
        $this->stubExistingRows([
            self::SHIRTS_ID => self::row(self::SHIRTS_ID, self::MEN_ID, 3, '1/2/10/11'),
        ]);
        $this->writer->expects(self::once())->method('findSiblingConflict')
            ->with(self::SHIRTS_ID, self::MEN_ID, 'Coats', self::anything(), false)
            ->willReturn(null);

        $definition = (new CategoryDefinition())->setCategoryId(self::SHIRTS_ID)->setName('Coats');
        $this->service->sync([$definition]);
    }

    public function testAPathIdentifiedEntryWithACollidingUrlKeyIsRefused(): void
    {
        $this->categoryResource->method('getChildIdsByParentIds')
            ->willReturn([self::MEN_ID => ['Shirts' => [self::SHIRTS_ID]]]);
        $this->pathResolver->method('lookupPaths')->willReturn(['Default Category/Men' => self::MEN_ID]);
        $this->writer->method('findSiblingConflict')
            ->willReturn(['kind' => 'url_key', 'value' => 'tents', 'category_id' => self::TENTS_ID]);
        $this->writer->expects(self::never())->method('update');

        // A path-identified entry cannot rename, but it can still hand over a
        // url_key a sibling already owns.
        $definition = $this->definition('Default Category/Men/Shirts')->setUrlKey('tents');
        $response = $this->service->sync([$definition]);

        self::assertSame(
            CategorySyncResultInterface::REASON_DESTINATION_URL_KEY_TAKEN,
            $response->getResults()[0]->getReason()
        );
    }

    public function testAMoveIsCollisionCheckedOnceNotTwice(): void
    {
        $this->stubExistingRows([
            self::SHIRTS_ID => self::row(self::SHIRTS_ID, self::MEN_ID, 3, '1/2/10/11'),
            self::WOMEN_ID => self::row(self::WOMEN_ID, self::ROOT_ID, 2, '1/2/20'),
        ]);
        // The move already checked the destination; re-checking the same parent
        // afterwards would be a wasted pair of queries per entry.
        $this->writer->expects(self::once())->method('findSiblingConflict')->willReturn(null);

        $definition = (new CategoryDefinition())
            ->setCategoryId(self::SHIRTS_ID)
            ->setName('Shirts')
            ->setParentCategoryId(self::WOMEN_ID);
        $this->service->sync([$definition]);
    }

    public function testStoreScopedWritesSkipTheCollisionCheck(): void
    {
        $this->storeId = 1;
        $this->stubExistingRows([
            self::SHIRTS_ID => self::row(self::SHIRTS_ID, self::MEN_ID, 3, '1/2/10/11'),
        ]);
        // Path resolution matches store-0 names throughout the module, so a
        // store-scoped rename cannot create a store-0 ambiguity.
        $this->writer->expects(self::never())->method('findSiblingConflict');

        $definition = (new CategoryDefinition())->setCategoryId(self::SHIRTS_ID)->setName('Coats');
        $this->service->sync([$definition]);
    }

    // --- Deleting ---------------------------------------------------------

    public function testDeleteByPathRemovesTheCategory(): void
    {
        $this->categoryResource->method('getChildIdsByParentIds')
            ->willReturn([self::MEN_ID => ['Shirts' => [self::SHIRTS_ID]]]);
        $this->pathResolver->method('lookupPaths')->willReturn(['Default Category/Men' => self::MEN_ID]);
        $this->stubExistingRows([
            self::SHIRTS_ID => self::row(self::SHIRTS_ID, self::MEN_ID, 3, '1/2/10/11'),
        ]);
        $this->writer->expects(self::once())->method('delete')->with(self::SHIRTS_ID);

        $definition = $this->definition('Default Category/Men/Shirts')->setDelete(1);
        $response = $this->service->sync([$definition]);

        $result = $response->getResults()[0];
        self::assertSame(CategorySyncResultInterface::STATUS_DELETED, $result->getStatus());
        self::assertSame(self::SHIRTS_ID, $result->getEntityId());
        self::assertSame(1, $response->getDeleted());
    }

    public function testDeleteByCategoryIdRemovesTheCategory(): void
    {
        $this->stubExistingRows([
            self::SHIRTS_ID => self::row(self::SHIRTS_ID, self::MEN_ID, 3, '1/2/10/11'),
        ]);
        $this->writer->expects(self::once())->method('delete')->with(self::SHIRTS_ID);

        $definition = (new CategoryDefinition())
            ->setCategoryId(self::SHIRTS_ID)
            ->setName('Shirts')
            ->setDelete(1);
        $response = $this->service->sync([$definition]);

        self::assertSame(CategorySyncResultInterface::STATUS_DELETED, $response->getResults()[0]->getStatus());
    }

    public function testDeletingAnAbsentCategoryIsUnchangedSoAReplayIsFree(): void
    {
        $this->categoryResource->method('getChildIdsByParentIds')->willReturn([]);
        $this->pathResolver->method('lookupPaths')->willReturn(['Default Category/Men' => self::MEN_ID]);
        $this->writer->expects(self::never())->method('delete');

        $definition = $this->definition('Default Category/Men/Shirts')->setDelete(1);
        $response = $this->service->sync([$definition]);

        $result = $response->getResults()[0];
        self::assertSame(CategorySyncResultInterface::STATUS_UNCHANGED, $result->getStatus());
        self::assertSame(CategorySyncResultInterface::REASON_ALREADY_ABSENT, $result->getReason());
        self::assertSame(1, $response->getUnchanged());
    }

    public function testDeletingAnUnknownCategoryIdIsAlsoAlreadyAbsent(): void
    {
        $this->stubExistingRows([]);
        $this->writer->expects(self::never())->method('delete');

        $definition = (new CategoryDefinition())->setCategoryId(4242)->setName('Gone')->setDelete(1);
        $response = $this->service->sync([$definition]);

        self::assertSame(
            CategorySyncResultInterface::REASON_ALREADY_ABSENT,
            $response->getResults()[0]->getReason()
        );
    }

    public function testDeletingACategoryWithChildrenNeedsTheOptIn(): void
    {
        $this->stubExistingRows([
            self::MEN_ID => self::row(self::MEN_ID, self::ROOT_ID, 2, '1/2/10'),
        ]);
        $this->categoryResource->method('getDescendantIds')->willReturn([self::SHIRTS_ID, 99]);
        $this->writer->expects(self::never())->method('delete');

        $definition = (new CategoryDefinition())->setCategoryId(self::MEN_ID)->setName('Men')->setDelete(1);
        $response = $this->service->sync([$definition]);

        $result = $response->getResults()[0];
        self::assertSame(CategorySyncResultInterface::REASON_HAS_CHILDREN, $result->getReason());
        self::assertSame(self::MEN_ID, $result->getEntityId());
        // The count is what makes the refusal actionable.
        self::assertStringContainsString('2 categories beneath it', $result->getMessages()[0]);
    }

    public function testDeleteChildrenOptInRemovesTheWholeSubtree(): void
    {
        $this->stubExistingRows([
            self::MEN_ID => self::row(self::MEN_ID, self::ROOT_ID, 2, '1/2/10'),
        ]);
        $this->categoryResource->method('getDescendantIds')->willReturn([self::SHIRTS_ID, 99]);
        $this->writer->expects(self::once())->method('delete')->with(self::MEN_ID);

        $removed = null;
        $this->invalidationHandler->method('execute')
            ->willReturnCallback(function (array $ids, array $removedIds = []) use (&$removed): void {
                $removed = $removedIds;
            });

        $definition = (new CategoryDefinition())
            ->setCategoryId(self::MEN_ID)
            ->setName('Men')
            ->setDelete(1)
            ->setDeleteChildren(1);
        $response = $this->service->sync([$definition]);

        self::assertSame(CategorySyncResultInterface::STATUS_DELETED, $response->getResults()[0]->getStatus());
        // Captured before the delete — afterwards the rows are gone and nothing
        // could rebuild this list.
        self::assertSame([self::MEN_ID, self::SHIRTS_ID, 99], $removed);
    }

    public function testDeletesRunDeepestFirstSoBothEntriesReportTheirOwnRemoval(): void
    {
        $this->stubExistingRows([
            self::MEN_ID => self::row(self::MEN_ID, self::ROOT_ID, 2, '1/2/10'),
            self::SHIRTS_ID => self::row(self::SHIRTS_ID, self::MEN_ID, 3, '1/2/10/11'),
        ]);
        $this->categoryResource->method('getDescendantIds')->willReturnCallback(
            static fn (array $ids): array => $ids === [self::MEN_ID] ? [self::SHIRTS_ID] : []
        );

        $deleted = [];
        $this->writer->method('delete')->willReturnCallback(
            static function (int $id) use (&$deleted): void {
                $deleted[] = $id;
            }
        );

        // Parent sent first in the payload; depth decides the order, not order.
        $response = $this->service->sync([
            (new CategoryDefinition())
                ->setCategoryId(self::MEN_ID)
                ->setName('Men')
                ->setDelete(1)
                ->setDeleteChildren(1),
            (new CategoryDefinition())
                ->setCategoryId(self::SHIRTS_ID)
                ->setName('Shirts')
                ->setDelete(1),
        ]);

        self::assertSame([self::SHIRTS_ID, self::MEN_ID], $deleted);
        self::assertSame(2, $response->getDeleted());
    }

    public function testDeletesDisabledIsRefused(): void
    {
        $service = $this->buildService($this->configMock(allowDelete: false));
        $this->writer->expects(self::never())->method('delete');

        $definition = (new CategoryDefinition())->setCategoryId(self::MEN_ID)->setName('Men')->setDelete(1);
        $response = $service->sync([$definition]);

        $result = $response->getResults()[0];
        self::assertSame(CategorySyncResultInterface::STATUS_SKIPPED, $result->getStatus());
        self::assertSame(CategorySyncResultInterface::REASON_DELETE_DISABLED, $result->getReason());
    }

    public function testDeletingTheTreeRootIsRefused(): void
    {
        $this->stubExistingRows([
            CategoryModel::TREE_ROOT_ID => self::row(CategoryModel::TREE_ROOT_ID, 0, 0, '1'),
        ]);
        $this->writer->expects(self::never())->method('delete');

        $definition = (new CategoryDefinition())
            ->setCategoryId(CategoryModel::TREE_ROOT_ID)
            ->setName('Root Catalog')
            ->setDelete(1);
        $response = $this->service->sync([$definition]);

        self::assertSame(
            CategorySyncResultInterface::REASON_ROOT_NOT_WRITABLE,
            $response->getResults()[0]->getReason()
        );
    }

    public function testDeletingAStoreGroupRootIsRefused(): void
    {
        $this->stubExistingRows([
            self::ROOT_ID => self::row(self::ROOT_ID, 1, 1, '1/2'),
        ]);
        $this->categoryResource->method('getStoreGroupRootCategoryIds')->willReturn([self::ROOT_ID => true]);
        $this->writer->expects(self::never())->method('delete');

        $definition = (new CategoryDefinition())
            ->setCategoryId(self::ROOT_ID)
            ->setName('Default Category')
            ->setDelete(1)
            ->setDeleteChildren(1);
        $response = $this->service->sync([$definition]);

        self::assertSame(
            CategorySyncResultInterface::REASON_ROOT_IN_USE,
            $response->getResults()[0]->getReason()
        );
    }

    public function testDeleteIsRefusedAtStoreScope(): void
    {
        $this->storeId = 1;
        $this->stubExistingRows([
            self::SHIRTS_ID => self::row(self::SHIRTS_ID, self::MEN_ID, 3, '1/2/10/11'),
        ]);
        $this->writer->expects(self::never())->method('delete');

        $definition = (new CategoryDefinition())
            ->setCategoryId(self::SHIRTS_ID)
            ->setName('Shirts')
            ->setDelete(1);
        $response = $this->service->sync([$definition]);

        self::assertSame(
            CategorySyncResultInterface::REASON_STORE_SCOPE_STRUCTURAL_CHANGE,
            $response->getResults()[0]->getReason()
        );
    }

    public function testDeletingACategoryInAnotherStoresTreeReportsWrongStoreRoot(): void
    {
        $this->storeId = 1;
        $this->storeRootId = self::ROOT_ID;
        $this->stubExistingRows([
            self::TENTS_ID => self::row(self::TENTS_ID, self::OUTDOOR_ID, 2, '1/7/12'),
        ]);
        $this->writer->expects(self::never())->method('delete');

        $definition = (new CategoryDefinition())
            ->setCategoryId(self::TENTS_ID)
            ->setName('Tents')
            ->setDelete(1);
        $response = $this->service->sync([$definition]);

        // Ahead of the store-scope refusal: which category is wrong-rooted is
        // the more specific finding.
        self::assertSame(
            CategorySyncResultInterface::REASON_WRONG_STORE_ROOT,
            $response->getResults()[0]->getReason()
        );
    }

    public function testAmbiguousDeletePathIsRefusedRatherThanGuessed(): void
    {
        $this->categoryResource->method('getChildIdsByParentIds')
            ->willReturn([self::MEN_ID => ['Shirts' => [self::SHIRTS_ID, 44]]]);
        $this->pathResolver->method('lookupPaths')->willReturn(['Default Category/Men' => self::MEN_ID]);
        $this->writer->expects(self::never())->method('delete');

        $definition = $this->definition('Default Category/Men/Shirts')->setDelete(1);
        $response = $this->service->sync([$definition]);

        self::assertSame(
            CategorySyncResultInterface::REASON_AMBIGUOUS_PATH,
            $response->getResults()[0]->getReason()
        );
    }

    public function testDeleteAndUpdateOfTheSameCategoryCollapseWithTheLastWinning(): void
    {
        $this->stubExistingRows([
            self::SHIRTS_ID => self::row(self::SHIRTS_ID, self::MEN_ID, 3, '1/2/10/11'),
        ]);
        $this->writer->expects(self::once())->method('delete')->with(self::SHIRTS_ID);
        $this->writer->expects(self::never())->method('update');

        $response = $this->service->sync([
            (new CategoryDefinition())->setCategoryId(self::SHIRTS_ID)->setName('Shirts')->setIsActive(0),
            (new CategoryDefinition())->setCategoryId(self::SHIRTS_ID)->setName('Shirts')->setDelete(1),
        ]);

        self::assertCount(1, $response->getResults());
        self::assertSame(CategorySyncResultInterface::STATUS_DELETED, $response->getResults()[0]->getStatus());
    }

    public function testDeletesRunAfterCreatesSoAParentCanBeReplacedInOneRequest(): void
    {
        $this->categoryResource->method('getChildIdsByParentIds')->willReturn([]);
        $this->pathResolver->method('lookupPaths')->willReturn(['Default Category/Men' => self::MEN_ID]);
        $this->stubExistingRows([
            self::TENTS_ID => self::row(self::TENTS_ID, self::MEN_ID, 3, '1/2/10/12'),
        ]);

        $order = [];
        $this->writer->method('create')->willReturnCallback(
            static function () use (&$order): int {
                $order[] = 'create';
                return self::SHIRTS_ID;
            }
        );
        $this->writer->method('delete')->willReturnCallback(
            static function () use (&$order): void {
                $order[] = 'delete';
            }
        );

        $this->service->sync([
            (new CategoryDefinition())->setCategoryId(self::TENTS_ID)->setName('Tents')->setDelete(1),
            $this->definition('Default Category/Men/Shirts'),
        ]);

        self::assertSame(['create', 'delete'], $order);
    }

    public function testADeleteFailureAbortsTheRestWhenContinueOnErrorIsOff(): void
    {
        $service = $this->buildService($this->configMock(continueOnError: false));
        $this->stubExistingRows([
            self::SHIRTS_ID => self::row(self::SHIRTS_ID, self::MEN_ID, 3, '1/2/10/11'),
            self::TENTS_ID => self::row(self::TENTS_ID, self::MEN_ID, 3, '1/2/10/12'),
        ]);
        $this->writer->method('delete')->willThrowException(new \RuntimeException('constraint violation'));

        $response = $service->sync([
            (new CategoryDefinition())->setCategoryId(self::SHIRTS_ID)->setName('Shirts')->setDelete(1),
            (new CategoryDefinition())->setCategoryId(self::TENTS_ID)->setName('Tents')->setDelete(1),
        ]);

        self::assertSame(1, $response->getFailed());
        self::assertSame(1, $response->getSkipped());
        $reasons = array_map(
            static fn ($r): ?string => $r->getReason(),
            $response->getResults()
        );
        self::assertContains(CategorySyncResultInterface::REASON_ABORTED, $reasons);
    }

    public function testDeleteInvalidatesTheResolversPathCache(): void
    {
        $this->stubExistingRows([
            self::SHIRTS_ID => self::row(self::SHIRTS_ID, self::MEN_ID, 3, '1/2/10/11'),
        ]);
        $this->pathResolver->expects(self::once())->method('forgetAllPaths');

        $definition = (new CategoryDefinition())
            ->setCategoryId(self::SHIRTS_ID)
            ->setName('Shirts')
            ->setDelete(1);
        $this->service->sync([$definition]);
    }

    public function testDeleteReportsThatProductsSurvive(): void
    {
        $this->stubExistingRows([
            self::SHIRTS_ID => self::row(self::SHIRTS_ID, self::MEN_ID, 3, '1/2/10/11'),
        ]);

        $definition = (new CategoryDefinition())
            ->setCategoryId(self::SHIRTS_ID)
            ->setName('Shirts')
            ->setDelete(1);
        $response = $this->service->sync([$definition]);

        self::assertNotEmpty(array_filter(
            $response->getResults()[0]->getMessages(),
            static fn (string $m): bool => str_contains($m, 'products themselves')
        ));
    }

    public function testInvalidDeleteDefinitionIsReportedNotDeleted(): void
    {
        $this->writer->expects(self::never())->method('delete');

        // A delete that also sets a value: contradictory, so nothing happens.
        $definition = $this->definition('Default Category/Men')->setDelete(1)->setIsActive(0);
        $response = $this->service->sync([$definition]);

        self::assertSame(
            CategorySyncResultInterface::REASON_INVALID_DEFINITION,
            $response->getResults()[0]->getReason()
        );
    }
}
