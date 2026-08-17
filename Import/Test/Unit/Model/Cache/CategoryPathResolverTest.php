<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Test\Unit\Model\Cache;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReadyData\Import\Logger\Logger;
use ReadyData\Import\Model\Cache\CategoryPathResolver;
use ReadyData\Import\Model\Cache\RootCategoryRegistry;
use ReadyData\Import\Model\Category\CategoryWriter;
use ReadyData\Import\Model\ResourceModel\Category as CategoryResource;

class CategoryPathResolverTest extends TestCase
{
    private const ROOT_ID = 2;
    private const MEN_ID = 10;

    private CategoryResource&MockObject $categoryResource;
    private CategoryWriter&MockObject $categoryWriter;
    private AdapterInterface&MockObject $connection;
    private ResourceConnection&MockObject $resourceConnection;
    private CategoryPathResolver $resolver;

    /**
     * @var int[]|null category IDs that have since been deleted, for the
     *      re-verification pass. Null means "the tree still holds everything".
     */
    private ?array $vanishedIds = null;

    protected function setUp(): void
    {
        $this->vanishedIds = null;

        $this->categoryResource = $this->createMock(CategoryResource::class);
        $this->categoryResource->method('getRootCategoryIds')->willReturn(['Default Category' => [self::ROOT_ID]]);
        // Every cached ID is re-verified on every call, so the tree has to be
        // able to answer for the ones this resolver has already handed out.
        $this->categoryResource->method('getExistingByIds')
            ->willReturnCallback(fn (array $ids): array => $this->stillExisting($ids));

        $this->categoryWriter = $this->createMock(CategoryWriter::class);

        $this->connection = $this->createMock(AdapterInterface::class);
        // Not in a transaction: resolvePaths() refuses to create inside one.
        $this->connection->method('getTransactionLevel')->willReturn(0);
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->resourceConnection->method('getConnection')->willReturn($this->connection);

        $this->resolver = $this->buildResolver();
    }

    private function buildResolver(): CategoryPathResolver
    {
        return new CategoryPathResolver(
            $this->categoryResource,
            $this->categoryWriter,
            new RootCategoryRegistry($this->categoryResource),
            $this->createMock(Logger::class),
            $this->resourceConnection
        );
    }

    public function testLookupPathsResolvesExistingPathsWithoutCreatingAnything(): void
    {
        $this->categoryResource->method('getChildrenByParentIds')
            ->willReturn([self::ROOT_ID => ['Men' => self::MEN_ID]]);
        $this->categoryWriter->expects(self::never())->method('createBare');

        $resolved = $this->resolver->lookupPaths(['Default Category/Men' => ['Default Category', 'Men']]);

        self::assertSame(['Default Category/Men' => self::MEN_ID], $resolved);
    }

    public function testLookupPathsOmitsMissingPathsInsteadOfCreatingThem(): void
    {
        $this->categoryResource->method('getChildrenByParentIds')->willReturn([]);
        $this->categoryWriter->expects(self::never())->method('createBare');

        $resolved = $this->resolver->lookupPaths(['Default Category/Ghost' => ['Default Category', 'Ghost']]);

        self::assertSame([], $resolved);
    }

    public function testLookupPathsOmitsUnknownRoots(): void
    {
        $this->categoryResource->method('getChildrenByParentIds')->willReturn([]);

        $resolved = $this->resolver->lookupPaths(['Typo/Men' => ['Typo', 'Men']]);

        self::assertSame([], $resolved);
    }

    public function testResolvePathsStillCreatesMissingSubtrees(): void
    {
        $this->categoryResource->method('getChildrenByParentIds')->willReturn([]);
        $this->categoryWriter->method('createBare')->willReturn(self::MEN_ID);

        $result = $this->resolver->resolvePaths(['Default Category/Men' => ['Default Category', 'Men']]);

        self::assertSame(self::MEN_ID, $result['Default Category/Men']['id']);
    }

    public function testCreationIsDelegatedToTheWriterSoTheDefaultsCannotDrift(): void
    {
        $this->categoryResource->method('getChildrenByParentIds')->willReturn([]);
        // Building the model here instead would mean two copies of the
        // defaults, the store-0 emulation and the required-attribute
        // zero-fill, and a category auto-created by a product import would
        // slowly stop resembling one the category endpoint created.
        $this->categoryWriter->expects(self::once())->method('createBare')
            ->with(self::ROOT_ID, 'Men')
            ->willReturn(self::MEN_ID);

        $this->resolver->resolvePaths(['Default Category/Men' => ['Default Category', 'Men']]);
    }

    public function testDeeperChainIsCreatedParentFirst(): void
    {
        $this->categoryResource->method('getChildrenByParentIds')->willReturn([]);

        $created = [];
        $this->categoryWriter->method('createBare')
            ->willReturnCallback(function (int $parentId, string $name) use (&$created): int {
                $created[] = [$parentId, $name];
                return $name === 'Men' ? self::MEN_ID : 11;
            });

        $result = $this->resolver->resolvePaths([
            'Default Category/Men/Shirts' => ['Default Category', 'Men', 'Shirts'],
        ]);

        self::assertSame([[self::ROOT_ID, 'Men'], [self::MEN_ID, 'Shirts']], $created);
        self::assertSame(11, $result['Default Category/Men/Shirts']['id']);
    }

    /**
     * A creation failure is reported against the path, not thrown. This used to
     * throw, and had to: creation ran inside the caller's transaction, and a
     * nested repository rollback leaves that transaction unusable, so the only
     * safe move was to reach its rollback handler. Creation now happens before
     * any transaction opens, so the failure can be handed back — which is what
     * lets the product import warn on one path instead of losing the batch.
     */
    public function testACreationFailureIsReportedAgainstThePath(): void
    {
        $this->categoryResource->method('getChildrenByParentIds')->willReturn([]);
        $this->categoryWriter->method('createBare')
            ->willThrowException(new \RuntimeException('url key already exists'));

        $result = $this->resolver->resolvePaths(['Default Category/Men' => ['Default Category', 'Men']]);

        self::assertNull($result['Default Category/Men']['id']);
        self::assertStringContainsString('url key already exists', $result['Default Category/Men']['message']);
    }

    /**
     * The user-visible win: one bad path no longer costs the others their
     * resolution. Every path in the call used to be lost to the throw, and with
     * it the whole batch.
     */
    public function testAFailureOnOnePathLeavesTheOthersResolved(): void
    {
        $this->categoryResource->method('getChildrenByParentIds')->willReturn([
            self::ROOT_ID => ['Men' => self::MEN_ID],
        ]);
        $this->categoryWriter->method('createBare')
            ->willThrowException(new \RuntimeException('url key already exists'));

        $result = $this->resolver->resolvePaths([
            'Default Category/Men' => ['Default Category', 'Men'],
            'Default Category/Women' => ['Default Category', 'Women'],
        ]);

        self::assertSame(self::MEN_ID, $result['Default Category/Men']['id']);
        self::assertNull($result['Default Category/Women']['id']);
    }

    /**
     * The precondition the per-path reporting above depends on. Inside a
     * transaction, a nested repository save that fails writes no rollback SQL at
     * all — it only flags the connection — so its partial rows stay live and the
     * caller's COMMIT dies with an unrelated error. Reporting instead of throwing
     * would make that silent, so the resolver refuses to run there.
     */
    public function testResolvePathsRefusesToRunInsideATransaction(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('getTransactionLevel')->willReturn(1);
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->resourceConnection->method('getConnection')->willReturn($this->connection);
        $resolver = $this->buildResolver();

        $this->categoryWriter->expects(self::never())->method('createBare');
        $this->expectException(LocalizedException::class);

        $resolver->resolvePaths(['Default Category/Men' => ['Default Category', 'Men']]);
    }

    /**
     * A chain that fails halfway keeps what it created — those saves committed on
     * their own, outside any transaction, so there is nothing to roll them back.
     * A retry completes the chain rather than duplicating it.
     */
    public function testAPartiallyCreatedChainKeepsTheSegmentsItManagedToCreate(): void
    {
        $this->categoryResource->method('getChildrenByParentIds')->willReturn([]);
        $created = [];
        $this->categoryWriter->method('createBare')
            ->willReturnCallback(function (int $parentId, string $name) use (&$created): int {
                if ($name === 'Shirts') {
                    throw new \RuntimeException('required attribute missing');
                }
                $created[] = $name;

                return self::MEN_ID;
            });

        $result = $this->resolver->resolvePaths([
            'Default Category/Men/Shirts' => ['Default Category', 'Men', 'Shirts'],
        ]);
        self::assertNull($result['Default Category/Men/Shirts']['id']);

        // "Men" was created and cached, so asking for it alone resolves from the
        // cache — no second create, which is what makes a retry converge.
        $resolvedMen = $this->resolver->lookupPaths(['Default Category/Men' => ['Default Category', 'Men']]);
        self::assertSame(self::MEN_ID, $resolvedMen['Default Category/Men']);
        self::assertSame(['Men'], $created);
    }

    /**
     * The common failure — two differently named siblings deriving one slug — is
     * pre-checked, so it becomes a message naming the other category instead of a
     * deep repository exception that names neither.
     */
    public function testASlugCollisionIsPreCheckedAndNamesTheOtherCategory(): void
    {
        $this->categoryResource->method('getChildrenByParentIds')->willReturn([]);
        $this->categoryWriter->method('findNewChildConflict')
            ->willReturn(['kind' => 'url_key', 'value' => 'men', 'category_id' => 77]);
        $this->categoryWriter->expects(self::never())->method('createBare');

        $result = $this->resolver->resolvePaths(['Default Category/Men' => ['Default Category', 'Men']]);

        self::assertNull($result['Default Category/Men']['id']);
        self::assertStringContainsString('category ID 77', $result['Default Category/Men']['message']);
    }

    /**
     * A category created moments ago is empty, so nothing can collide beneath it.
     * Asking anyway would cost one query per segment of every new chain.
     */
    public function testTheSlugPreCheckIsSkippedForParentsThisChainJustCreated(): void
    {
        $this->categoryResource->method('getChildrenByParentIds')->willReturn([]);
        $this->categoryWriter->method('createBare')
            ->willReturnCallback(static fn (int $parentId, string $name): int => $name === 'Men' ? 10 : 11);

        $this->categoryWriter->expects(self::once())->method('findNewChildConflict')
            ->with(self::ROOT_ID, 'Men')
            ->willReturn(null);

        $result = $this->resolver->resolvePaths([
            'Default Category/Men/Shirts' => ['Default Category', 'Men', 'Shirts'],
        ]);

        self::assertSame(11, $result['Default Category/Men/Shirts']['id']);
    }

    /**
     * Two roots may share a name, and then one path key means two different
     * categories. A flat cache would serve the first root's ID to the second
     * root's path — placing a subtree in the wrong catalog, which Magento does
     * not cheaply undo.
     */
    public function testTwoRootsSharingANameKeepSeparateCaches(): void
    {
        $resolver = $this->twoRootResolver($calls);

        $paths = ['Shop/Men' => ['Shop', 'Men']];
        self::assertSame(['Shop/Men' => 10], $resolver->lookupPaths($paths, 2));
        self::assertSame(['Shop/Men' => 20], $resolver->lookupPaths($paths, 3));
        // Each root was walked once; neither answer came from the other's cache.
        self::assertSame(2, $calls);

        // And both are now cached independently.
        self::assertSame(['Shop/Men' => 10], $resolver->lookupPaths($paths, 2));
        self::assertSame(['Shop/Men' => 20], $resolver->lookupPaths($paths, 3));
        self::assertSame(2, $calls);
    }

    public function testWithoutAPinAnAmbiguousRootNameStillTakesTheLowestId(): void
    {
        // The historical read behaviour, unchanged: a pin is what a caller uses
        // to stop relying on it.
        $resolver = $this->twoRootResolver($calls);

        self::assertSame(['Shop/Men' => 10], $resolver->lookupPaths(['Shop/Men' => ['Shop', 'Men']]));
    }

    public function testAPinThatContradictsThePathIsRefused(): void
    {
        $this->categoryResource->method('getChildrenByParentIds')->willReturn([]);

        $result = $this->resolver->resolvePaths(
            ['Default Category/Men' => ['Default Category', 'Men']],
            99
        );

        self::assertNull($result['Default Category/Men']['id']);
        self::assertSame(
            'Root category ID 99 does not exist.',
            $result['Default Category/Men']['message']
        );
        $this->categoryWriter->expects(self::never())->method('createBare');
    }

    public function testAPinNamingADifferentRootIsRefusedRatherThanFollowed(): void
    {
        $resolver = $this->twoRootResolver($calls, ['Shop' => [2], 'Outdoor' => [3]]);

        // Following the pin would file the category under "Outdoor" though the
        // path said "Shop"; ignoring it would drop the more specific statement.
        $result = $resolver->resolvePaths(['Shop/Men' => ['Shop', 'Men']], 3);

        self::assertNull($result['Shop/Men']['id']);
        self::assertSame(
            'Path starts with root "Shop" but root category ID 3 is named "Outdoor".',
            $result['Shop/Men']['message']
        );
    }

    /**
     * A resolver over two roots whose children are distinct, counting tree
     * queries so a test can tell a cache hit from a walk.
     *
     * @param int|null $calls out-param: tree queries performed
     * @param array<string, int[]>|null $roots defaults to two roots named "Shop"
     */
    private function twoRootResolver(?int &$calls, ?array $roots = null): CategoryPathResolver
    {
        $calls = 0;
        $categoryResource = $this->createMock(CategoryResource::class);
        $categoryResource->method('getRootCategoryIds')->willReturn($roots ?? ['Shop' => [2, 3]]);
        $categoryResource->method('getExistingByIds')
            ->willReturnCallback(fn (array $ids): array => $this->stillExisting($ids));
        $categoryResource->method('getChildrenByParentIds')
            ->willReturnCallback(function (array $parentIds) use (&$calls): array {
                $calls++;

                return array_intersect_key(
                    [2 => ['Men' => 10], 3 => ['Men' => 20]],
                    array_flip($parentIds)
                );
            });

        return new CategoryPathResolver(
            $categoryResource,
            $this->categoryWriter,
            new RootCategoryRegistry($categoryResource),
            $this->createMock(Logger::class),
            $this->resourceConnection
        );
    }

    /**
     * What {@see CategoryPathResolver::getExistingByIds()} answers for the
     * re-verification pass: every ID asked about, minus the ones a test has
     * declared deleted.
     *
     * @param int[] $ids
     * @return array<int, array{entity_id: int, parent_id: int, level: int, path: string}>
     */
    private function stillExisting(array $ids): array
    {
        $rows = [];
        foreach ($ids as $id) {
            if ($this->vanishedIds !== null && in_array($id, $this->vanishedIds, true)) {
                continue;
            }
            $rows[$id] = ['entity_id' => $id, 'parent_id' => self::ROOT_ID, 'level' => 2, 'path' => '1/2/' . $id];
        }

        return $rows;
    }

    /**
     * The product import releases its locks between batches, so a category sync
     * can commit a delete in that window. A cached ID that is no longer in the
     * tree has to be dropped and the path resolved again — writing a product
     * assignment against it would fail on the catalog_category_product foreign
     * key, for something that is recoverable.
     */
    public function testACategoryDeletedByAnotherRequestIsEvictedAndResolvedAgain(): void
    {
        $children = [self::ROOT_ID => ['Men' => self::MEN_ID]];
        // By reference: the tree changes underneath us halfway through the test.
        $this->categoryResource->method('getChildrenByParentIds')
            ->willReturnCallback(static function () use (&$children): array {
                return $children;
            });

        $paths = ['Default Category/Men' => ['Default Category', 'Men']];
        self::assertSame(['Default Category/Men' => self::MEN_ID], $this->resolver->lookupPaths($paths));

        // Another request deletes it, and the tree now holds a different
        // category under that name.
        $this->vanishedIds = [self::MEN_ID];
        $children = [self::ROOT_ID => ['Men' => 99]];

        self::assertSame(['Default Category/Men' => 99], $this->resolver->lookupPaths($paths));
    }

    public function testForgetDropsACachedPathSoItIsLookedUpAgain(): void
    {
        $calls = 0;
        $this->categoryResource->method('getChildrenByParentIds')
            ->willReturnCallback(function () use (&$calls): array {
                $calls++;
                return [self::ROOT_ID => ['Men' => self::MEN_ID]];
            });

        $paths = ['Default Category/Men' => ['Default Category', 'Men']];
        $this->resolver->lookupPaths($paths);
        // Second call is served from the request cache.
        $this->resolver->lookupPaths($paths);
        self::assertSame(1, $calls);

        // After a rename the cached entry points at a category that no longer
        // answers to this path.
        $this->resolver->forget('Default Category/Men');
        $this->resolver->lookupPaths($paths);

        self::assertSame(2, $calls);
    }

    /**
     * A root a caller created mid-request would otherwise stay invisible: the
     * name => ID map is memoized for the life of the shared registry, and no
     * path cache entry covers a root for forget() to drop. The resolver reads
     * the map through the registry, so invalidating it there is enough.
     */
    public function testARootAddedMidRequestBecomesVisibleOnceTheRegistryIsInvalidated(): void
    {
        $rootCalls = 0;
        $categoryResource = $this->createMock(CategoryResource::class);
        $categoryResource->method('getRootCategoryIds')
            ->willReturnCallback(function () use (&$rootCalls): array {
                $rootCalls++;
                return ['Default Category' => [self::ROOT_ID]];
            });
        $categoryResource->method('getChildrenByParentIds')
            ->willReturn([self::ROOT_ID => ['Men' => self::MEN_ID]]);
        $resolver = new CategoryPathResolver(
            $categoryResource,
            $this->categoryWriter,
            $registry = new RootCategoryRegistry($categoryResource),
            $this->createMock(Logger::class),
            $this->resourceConnection
        );

        $paths = ['Default Category/Men' => ['Default Category', 'Men']];
        $resolver->lookupPaths($paths);
        $resolver->forget('Default Category/Men');
        $resolver->lookupPaths($paths);
        self::assertSame(1, $rootCalls);

        $registry->forget();
        $resolver->forget('Default Category/Men');
        $resolver->lookupPaths($paths);

        self::assertSame(2, $rootCalls);
    }

    public function testForgetPathsUnderRootDropsEverythingCachedBelowARenamedRoot(): void
    {
        $calls = 0;
        $this->categoryResource->method('getChildrenByParentIds')
            ->willReturnCallback(function () use (&$calls): array {
                $calls++;
                return [self::ROOT_ID => ['Men' => self::MEN_ID]];
            });

        $paths = ['Default Category/Men' => ['Default Category', 'Men']];
        $this->resolver->lookupPaths($paths);
        self::assertSame(1, $calls);

        // The root was renamed, so every path that starts with its old name is
        // stale — not just the root's own entry.
        $this->resolver->forgetPathsUnderRoot('Default Category');
        $this->resolver->lookupPaths($paths);

        self::assertSame(2, $calls);
    }

    public function testRootOnlyPathIsReportedWithoutAProductSpecificMessage(): void
    {
        $result = $this->resolver->resolvePaths(['Default Category' => ['Default Category']]);

        self::assertNull($result['Default Category']['id']);
        self::assertStringNotContainsString('products', $result['Default Category']['message']);
    }
}
