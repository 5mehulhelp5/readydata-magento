<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Test\Unit\Model\Processor;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReadyData\Import\Model\BatchContext;
use ReadyData\Import\Model\Cache\CategoryPathResolver;
use ReadyData\Import\Model\Category\PathParser;
use ReadyData\Import\Model\Config;
use ReadyData\Import\Model\Data\Product;
use ReadyData\Import\Model\Processor\CategoryLinkProcessor;
use ReadyData\Import\Model\ResourceModel\Category as CategoryResource;
use ReadyData\Import\Model\ResourceModel\CategoryLink;

class CategoryLinkProcessorTest extends TestCase
{
    private CategoryLink&MockObject $categoryLink;
    private CategoryPathResolver&MockObject $pathResolver;
    private CategoryResource&MockObject $categoryResource;
    private Config&MockObject $config;
    private CategoryLinkProcessor $processor;

    protected function setUp(): void
    {
        $this->categoryLink = $this->createMock(CategoryLink::class);
        $this->pathResolver = $this->createMock(CategoryPathResolver::class);
        $this->categoryResource = $this->createMock(CategoryResource::class);
        $this->config = $this->createMock(Config::class);
        $this->config->method('getCategoryReplaceScope')->willReturn(Config::REPLACE_SCOPE_ALL_ROOTS);

        $this->processor = new CategoryLinkProcessor(
            $this->categoryLink,
            $this->pathResolver,
            new PathParser(),
            $this->categoryResource,
            $this->config
        );
    }

    /**
     * Builds the processor with a non-default replace scope. Separate from
     * setUp() because the Config stub cannot be re-stubbed once set.
     */
    private function processorWithScope(string $scope): CategoryLinkProcessor
    {
        $config = $this->createMock(Config::class);
        $config->method('getCategoryReplaceScope')->willReturn($scope);

        return new CategoryLinkProcessor(
            $this->categoryLink,
            $this->pathResolver,
            new PathParser(),
            $this->categoryResource,
            $config
        );
    }

    public function testReplaceInsertsNewAndDeletesRemovedLinks(): void
    {
        $context = $this->createContext(['SKU-1' => ['Default Category/Men/Shirts', '42']], ['SKU-1' => 10]);

        $this->pathResolver->method('resolvePaths')
            ->with(['Default Category/Men/Shirts' => ['Default Category', 'Men', 'Shirts']])
            ->willReturn(['Default Category/Men/Shirts' => ['id' => 5, 'message' => null]]);
        $this->pathResolver->method('validateIds')->with([42])->willReturn([42 => true]);
        $this->categoryLink->method('getAssignments')->with([10])->willReturn([10 => [42, 7]]);

        $this->categoryLink->expects(self::once())->method('unassign')
            ->with([['category_id' => 7, 'product_id' => 10]]);
        $this->categoryLink->expects(self::once())->method('assign')
            ->with([['category_id' => 5, 'product_id' => 10, 'position' => 0]]);

        $this->processor->process($context);

        self::assertSame([], $context->getMessages('SKU-1'));
        self::assertEqualsCanonicalizing(
            [5, 7],
            $context->get(CategoryLinkProcessor::CONTEXT_AFFECTED_CATEGORY_IDS)
        );
    }

    public function testEmptyArrayRemovesAllAssignments(): void
    {
        $context = $this->createContext(['SKU-1' => []], ['SKU-1' => 10]);

        $this->pathResolver->method('resolvePaths')->with([])->willReturn([]);
        $this->pathResolver->method('validateIds')->with([])->willReturn([]);
        $this->categoryLink->method('getAssignments')->willReturn([10 => [5, 7]]);

        $this->categoryLink->expects(self::once())->method('unassign')
            ->with([
                ['category_id' => 5, 'product_id' => 10],
                ['category_id' => 7, 'product_id' => 10],
            ]);
        $this->categoryLink->expects(self::once())->method('assign')->with([]);

        $this->processor->process($context);
    }

    public function testNullCategoriesTouchesNothing(): void
    {
        $product = new Product();
        $product->setSku('SKU-1');
        $context = new BatchContext([$product]);
        $context->setEntityId('SKU-1', 10);

        $this->pathResolver->expects(self::never())->method('resolvePaths');
        $this->pathResolver->expects(self::never())->method('validateIds');
        $this->categoryLink->expects(self::never())->method('getAssignments');
        $this->categoryLink->expects(self::never())->method('unassign');
        $this->categoryLink->expects(self::never())->method('assign');

        $this->processor->process($context);

        self::assertNull($context->get(CategoryLinkProcessor::CONTEXT_AFFECTED_CATEGORY_IDS));
    }

    public function testUnresolvedReferenceWithholdsDeletionsAndWarns(): void
    {
        $context = $this->createContext(['SKU-1' => ['Default Category/Nope', '5']], ['SKU-1' => 10]);

        $this->pathResolver->method('resolvePaths')->willReturn([
            'Default Category/Nope' => ['id' => null, 'message' => 'Unknown root category "Default Category" — root categories are not auto-created.'],
        ]);
        $this->pathResolver->method('validateIds')->with([5])->willReturn([5 => true]);
        $this->categoryLink->method('getAssignments')->willReturn([10 => [7]]);

        $this->categoryLink->expects(self::once())->method('unassign')->with([]);
        $this->categoryLink->expects(self::once())->method('assign')
            ->with([['category_id' => 5, 'product_id' => 10, 'position' => 0]]);

        $this->processor->process($context);

        $messages = $context->getMessages('SKU-1');
        self::assertCount(2, $messages);
        self::assertStringContainsString('Unknown root category', $messages[0]);
        self::assertStringContainsString('applied additively', $messages[1]);
        self::assertFalse($context->isFailed('SKU-1'));
    }

    public function testUnknownCategoryIdWarnsAndIsSkipped(): void
    {
        $context = $this->createContext(['SKU-1' => ['99']], ['SKU-1' => 10]);

        $this->pathResolver->method('resolvePaths')->willReturn([]);
        $this->pathResolver->method('validateIds')->with([99])->willReturn([]);
        $this->categoryLink->method('getAssignments')->willReturn([]);

        $this->categoryLink->expects(self::once())->method('unassign')->with([]);
        $this->categoryLink->expects(self::once())->method('assign')->with([]);

        $this->processor->process($context);

        $messages = $context->getMessages('SKU-1');
        self::assertCount(1, $messages);
        self::assertStringContainsString('Unknown or root category ID 99', $messages[0]);
        self::assertFalse($context->isFailed('SKU-1'));
    }

    public function testProductWithoutEntityIdIsSkipped(): void
    {
        $context = $this->createContext(['SKU-1' => ['42']], []);

        $this->pathResolver->expects(self::never())->method('validateIds');
        $this->categoryLink->expects(self::never())->method('assign');

        $this->processor->process($context);
    }

    public function testDuplicateAndEquivalentReferencesAreDeduplicated(): void
    {
        $context = $this->createContext(
            ['SKU-1' => ['Default Category/Men', 'Default Category/Men/', '42', ' 42 ']],
            ['SKU-1' => 10]
        );

        $this->pathResolver->method('resolvePaths')
            ->with(['Default Category/Men' => ['Default Category', 'Men']])
            ->willReturn(['Default Category/Men' => ['id' => 42, 'message' => null]]);
        $this->pathResolver->method('validateIds')->with([42])->willReturn([42 => true]);
        $this->categoryLink->method('getAssignments')->willReturn([]);

        $this->categoryLink->expects(self::once())->method('assign')
            ->with([['category_id' => 42, 'product_id' => 10, 'position' => 0]]);

        $this->processor->process($context);
    }

    public function testEscapedSlashResolvesAsOneSegmentUnderTheCanonicalKey(): void
    {
        $context = $this->createContext(['SKU-1' => ['Default Category/Wo\/Men']], ['SKU-1' => 10]);

        $this->pathResolver->method('resolvePaths')
            ->with(['Default Category/Wo\/Men' => ['Default Category', 'Wo/Men']])
            ->willReturn(['Default Category/Wo\/Men' => ['id' => 5, 'message' => null]]);
        $this->pathResolver->method('validateIds')->with([])->willReturn([]);
        $this->categoryLink->method('getAssignments')->willReturn([]);

        $this->categoryLink->expects(self::once())->method('assign')
            ->with([['category_id' => 5, 'product_id' => 10, 'position' => 0]]);

        $this->processor->process($context);

        self::assertSame([], $context->getMessages('SKU-1'));
    }

    public function testEscapedAndUnescapedSlashAreDistinctPaths(): void
    {
        $context = $this->createContext(['SKU-1' => ['a\/b', 'a/b']], ['SKU-1' => 10]);

        $this->pathResolver->method('resolvePaths')
            ->with([
                'a\/b' => ['a/b'],
                'a/b' => ['a', 'b'],
            ])
            ->willReturn([
                'a\/b' => ['id' => null, 'message' => 'Cannot assign products to the root category "a/b".'],
                'a/b' => ['id' => 6, 'message' => null],
            ]);
        $this->pathResolver->method('validateIds')->with([])->willReturn([]);
        $this->categoryLink->method('getAssignments')->willReturn([]);

        $this->categoryLink->expects(self::once())->method('assign')
            ->with([['category_id' => 6, 'product_id' => 10, 'position' => 0]]);

        $this->processor->process($context);
    }

    /**
     * The whole point of the replace scope: two root trees fed by two sources.
     * A feed that owns the Outdoor tree must not delete the Default tree's
     * links just because its own payload does not mention them.
     */
    public function testPayloadRootsModeLeavesOtherRootTreesAlone(): void
    {
        $context = $this->createContext(['SKU-1' => ['Outdoor Catalog/Jackets']], ['SKU-1' => 10]);

        $this->pathResolver->method('resolvePaths')
            ->willReturn(['Outdoor Catalog/Jackets' => ['id' => 30, 'message' => null]]);
        $this->pathResolver->method('validateIds')->willReturn([]);
        // 21 is under Default Category (root 20), 31 under Outdoor (root 29).
        $this->categoryLink->method('getAssignments')->willReturn([10 => [21, 31]]);
        $this->stubRoots([21 => 20, 31 => 29, 30 => 29]);

        // Only the Outdoor link goes; the Default Category link survives.
        $this->categoryLink->expects(self::once())->method('unassign')
            ->with([['category_id' => 31, 'product_id' => 10]]);
        $this->categoryLink->expects(self::once())->method('assign')
            ->with([['category_id' => 30, 'product_id' => 10, 'position' => 0]]);

        $this->processorWithScope(Config::REPLACE_SCOPE_PAYLOAD_ROOTS)->process($context);

        self::assertStringContainsString(
            'limited to root category 29; 1 existing assignment(s) outside it were kept',
            $context->getMessages('SKU-1')[0]
        );
    }

    public function testAllRootsModeStillReplacesAcrossTheWholeCatalog(): void
    {
        $context = $this->createContext(['SKU-1' => ['Outdoor Catalog/Jackets']], ['SKU-1' => 10]);

        $this->pathResolver->method('resolvePaths')
            ->willReturn(['Outdoor Catalog/Jackets' => ['id' => 30, 'message' => null]]);
        $this->pathResolver->method('validateIds')->willReturn([]);
        $this->categoryLink->method('getAssignments')->willReturn([10 => [21, 31]]);
        // No root lookup happens at all in the default configuration.
        $this->categoryResource->expects(self::never())->method('getAncestry');

        $this->categoryLink->expects(self::once())->method('unassign')->with([
            ['category_id' => 21, 'product_id' => 10],
            ['category_id' => 31, 'product_id' => 10],
        ]);

        $this->processor->process($context);

        self::assertSame([], $context->getMessages('SKU-1'));
    }

    public function testAnExplicitScopeOverridesTheConfiguration(): void
    {
        $context = $this->createContext(['SKU-1' => []], ['SKU-1' => 10], ['SKU-1' => [20]]);

        $this->pathResolver->method('resolvePaths')->willReturn([]);
        $this->pathResolver->method('validateIds')->willReturn([]);
        $this->categoryLink->method('getAssignments')->willReturn([10 => [21, 31]]);
        $this->stubRoots([21 => 20, 31 => 29]);
        $this->categoryResource->method('getRootCategoryIds')
            ->willReturn(['Default Category' => [20], 'Outdoor Catalog' => [29]]);

        // "categories": [] clears the named root and only the named root — the
        // only way to empty a tree under payload-roots.
        $this->categoryLink->expects(self::once())->method('unassign')
            ->with([['category_id' => 21, 'product_id' => 10]]);

        $this->processorWithScope(Config::REPLACE_SCOPE_PAYLOAD_ROOTS)->process($context);
    }

    /**
     * An empty payload names no roots, so it removes nothing — the edge that
     * makes `"categories": []` mean something different under payload-roots.
     */
    public function testEmptyCategoriesUnderPayloadRootsRemovesNothing(): void
    {
        $context = $this->createContext(['SKU-1' => []], ['SKU-1' => 10]);

        $this->pathResolver->method('resolvePaths')->willReturn([]);
        $this->pathResolver->method('validateIds')->willReturn([]);
        $this->categoryLink->method('getAssignments')->willReturn([10 => [21]]);
        $this->stubRoots([21 => 20]);

        $this->categoryLink->expects(self::once())->method('unassign')->with([]);

        $this->processorWithScope(Config::REPLACE_SCOPE_PAYLOAD_ROOTS)->process($context);

        self::assertStringContainsString(
            'limited to root categories: none; 1 existing assignment(s) outside it were kept',
            $context->getMessages('SKU-1')[0]
        );
    }

    public function testAnExplicitEmptyScopeMakesTheProductPurelyAdditive(): void
    {
        $context = $this->createContext(['SKU-1' => []], ['SKU-1' => 10], ['SKU-1' => []]);

        $this->pathResolver->method('resolvePaths')->willReturn([]);
        $this->pathResolver->method('validateIds')->willReturn([]);
        $this->categoryLink->method('getAssignments')->willReturn([10 => [21]]);
        $this->stubRoots([21 => 20]);

        $this->categoryLink->expects(self::once())->method('unassign')->with([]);

        $this->processor->process($context);
    }

    public function testANonRootInTheScopeIsReportedAndIgnored(): void
    {
        $context = $this->createContext(['SKU-1' => []], ['SKU-1' => 10], ['SKU-1' => [21, 20]]);

        $this->pathResolver->method('resolvePaths')->willReturn([]);
        $this->pathResolver->method('validateIds')->willReturn([]);
        $this->categoryLink->method('getAssignments')->willReturn([10 => [21, 31]]);
        $this->stubRoots([21 => 20, 31 => 29]);
        $this->categoryResource->method('getRootCategoryIds')
            ->willReturn(['Default Category' => [20], 'Outdoor Catalog' => [29]]);

        // 21 is a category but not a root, so it narrows nothing; 20 still applies.
        $this->categoryLink->expects(self::once())->method('unassign')
            ->with([['category_id' => 21, 'product_id' => 10]]);

        $this->processor->process($context);

        self::assertStringContainsString(
            'Ignored 21 in categories_replace_scope: not a root category.',
            $context->getMessages('SKU-1')[0]
        );
    }

    /**
     * A link whose category vanished between the assignment read and now cannot
     * be placed in a tree, so it cannot be shown to be in scope. Keeping it is
     * the conservative half of "don't delete what you can't reason about".
     */
    public function testALinkWithNoResolvableRootIsKept(): void
    {
        $context = $this->createContext(['SKU-1' => []], ['SKU-1' => 10], ['SKU-1' => [20]]);

        $this->pathResolver->method('resolvePaths')->willReturn([]);
        $this->pathResolver->method('validateIds')->willReturn([]);
        $this->categoryLink->method('getAssignments')->willReturn([10 => [21, 99]]);
        $this->stubRoots([21 => 20]);
        $this->categoryResource->method('getRootCategoryIds')->willReturn(['Default Category' => [20]]);

        $this->categoryLink->expects(self::once())->method('unassign')
            ->with([['category_id' => 21, 'product_id' => 10]]);

        $this->processor->process($context);
    }

    /**
     * The additive safety valve outranks the scope: an unresolved reference
     * withholds every deletion, so the scope never gets to permit one.
     */
    public function testAnUnresolvedReferenceStillWithholdsEveryDeletion(): void
    {
        $context = $this->createContext(['SKU-1' => ['Outdoor Catalog/Nope']], ['SKU-1' => 10], ['SKU-1' => [29]]);

        $this->pathResolver->method('resolvePaths')->willReturn([
            'Outdoor Catalog/Nope' => ['id' => null, 'message' => 'Unknown root category "Outdoor Catalog".'],
        ]);
        $this->pathResolver->method('validateIds')->willReturn([]);
        $this->categoryLink->method('getAssignments')->willReturn([10 => [31]]);
        $this->stubRoots([31 => 29]);
        $this->categoryResource->method('getRootCategoryIds')->willReturn(['Outdoor Catalog' => [29]]);

        $this->categoryLink->expects(self::once())->method('unassign')->with([]);

        $this->processor->process($context);

        self::assertStringContainsString('applied additively', $context->getMessages('SKU-1')[1]);
    }

    /**
     * Stubs getAncestry() from a flat category => root map, shaping the reply
     * the way the resource model does (level 1 = the root itself).
     *
     * @param array<int, int> $rootByCategoryId
     */
    private function stubRoots(array $rootByCategoryId): void
    {
        $this->categoryResource->method('getAncestry')->willReturnCallback(
            static function (array $categoryIds) use ($rootByCategoryId): array {
                $ancestry = [];
                foreach ($categoryIds as $categoryId) {
                    if (!isset($rootByCategoryId[$categoryId])) {
                        continue;
                    }
                    $root = $rootByCategoryId[$categoryId];
                    $ancestry[$categoryId] = $root === $categoryId
                        ? ['level' => 1, 'ancestors' => []]
                        : ['level' => 2, 'ancestors' => [$root]];
                }

                return $ancestry;
            }
        );
    }

    /**
     * @param array<string, string[]> $categoriesBySku
     * @param array<string, int> $entityIds
     * @param array<string, int[]> $replaceScopes per-SKU categories_replace_scope
     */
    private function createContext(
        array $categoriesBySku,
        array $entityIds,
        array $replaceScopes = []
    ): BatchContext {
        $products = [];
        foreach ($categoriesBySku as $sku => $categories) {
            $product = new Product();
            $product->setSku($sku);
            $product->setCategories($categories);
            if (array_key_exists($sku, $replaceScopes)) {
                $product->setCategoriesReplaceScope($replaceScopes[$sku]);
            }
            $products[] = $product;
        }

        $context = new BatchContext($products);
        foreach ($entityIds as $sku => $entityId) {
            $context->setEntityId($sku, $entityId);
        }

        return $context;
    }
}
