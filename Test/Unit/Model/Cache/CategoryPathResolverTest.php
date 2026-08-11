<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Test\Unit\Model\Cache;

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

        $this->resolver = new CategoryPathResolver(
            $this->categoryResource,
            $this->categoryWriter,
            new RootCategoryRegistry($this->categoryResource),
            $this->createMock(Logger::class)
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

    public function testCreationFailureIsRethrownRatherThanSwallowed(): void
    {
        $this->categoryResource->method('getChildrenByParentIds')->willReturn([]);
        $this->categoryWriter->method('createBare')
            ->willThrowException(new \RuntimeException('url key already exists'));

        // Swallowing it would leave the caller's transaction flagged as
        // partially rolled back, so its commit would fail with an unrelated
        // "Partial rollback is not supported" instead of this message.
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('url key already exists');

        $this->resolver->resolvePaths(['Default Category/Men' => ['Default Category', 'Men']]);
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
            $this->createMock(Logger::class)
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
            $this->createMock(Logger::class)
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
