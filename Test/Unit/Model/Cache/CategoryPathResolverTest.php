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
use ReadyData\Import\Model\Category\CategoryWriter;
use ReadyData\Import\Model\ResourceModel\Category as CategoryResource;

class CategoryPathResolverTest extends TestCase
{
    private const ROOT_ID = 2;
    private const MEN_ID = 10;

    private CategoryResource&MockObject $categoryResource;
    private CategoryWriter&MockObject $categoryWriter;
    private CategoryPathResolver $resolver;

    protected function setUp(): void
    {
        $this->categoryResource = $this->createMock(CategoryResource::class);
        $this->categoryResource->method('getRootCategories')->willReturn(['Default Category' => self::ROOT_ID]);

        $this->categoryWriter = $this->createMock(CategoryWriter::class);

        $this->resolver = new CategoryPathResolver(
            $this->categoryResource,
            $this->categoryWriter,
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

    public function testForgetRootsRereadsTheRootMap(): void
    {
        // A root a caller created mid-request would otherwise stay invisible:
        // this map is memoized for the life of the shared instance, and no path
        // cache entry covers a root for forget() to drop.
        $rootCalls = 0;
        $categoryResource = $this->createMock(CategoryResource::class);
        $categoryResource->method('getRootCategories')
            ->willReturnCallback(function () use (&$rootCalls): array {
                $rootCalls++;
                return ['Default Category' => self::ROOT_ID];
            });
        $categoryResource->method('getChildrenByParentIds')
            ->willReturn([self::ROOT_ID => ['Men' => self::MEN_ID]]);
        $resolver = new CategoryPathResolver(
            $categoryResource,
            $this->categoryWriter,
            $this->createMock(Logger::class)
        );

        $paths = ['Default Category/Men' => ['Default Category', 'Men']];
        $resolver->lookupPaths($paths);
        $resolver->forget('Default Category/Men');
        $resolver->lookupPaths($paths);
        self::assertSame(1, $rootCalls);

        $resolver->forgetRoots();
        $resolver->forget('Default Category/Men');
        $resolver->lookupPaths($paths);

        self::assertSame(2, $rootCalls);
    }

    public function testForgetRootsDropsThePathsCachedUnderARenamedRoot(): void
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
        $this->resolver->forgetRoots('Default Category');
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
