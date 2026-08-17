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
use ReadyData\Import\Model\Cache\RootCategoryRegistry;
use ReadyData\Import\Model\Category\PathParser;
use ReadyData\Import\Model\Config;
use ReadyData\Import\Model\Data\Product;
use ReadyData\Import\Model\ImportLocks;
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
            new RootCategoryRegistry($this->categoryResource),
            $this->config
        );
    }

    /**
     * Run both of the step's phases, in the order ImportService runs them.
     *
     * Path resolution moved out of process() and into prepareUnderLocks(), which
     * runs under the batch's locks but before its transaction opens — creating a
     * category goes through the repository, and that cannot nest inside the
     * batch's transaction. Driving both here rather than seeding the resolved map
     * onto each context by hand keeps every test exercising the real hand-off, so
     * the map's shape cannot drift between the two phases unnoticed.
     */
    private function resolveAndProcess(CategoryLinkProcessor $processor, BatchContext $context): void
    {
        $processor->prepareUnderLocks($context);
        $processor->process($context);
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
            new RootCategoryRegistry($this->categoryResource),
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

        $this->resolveAndProcess($this->processor, $context);

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

        $this->resolveAndProcess($this->processor, $context);
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

        $this->resolveAndProcess($this->processor, $context);

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

        $this->resolveAndProcess($this->processor, $context);

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

        $this->resolveAndProcess($this->processor, $context);

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

        $this->resolveAndProcess($this->processor, $context);
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

        $this->resolveAndProcess($this->processor, $context);
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

        $this->resolveAndProcess($this->processor, $context);

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

        $this->resolveAndProcess($this->processor, $context);
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

        $this->resolveAndProcess($this->processorWithScope(Config::REPLACE_SCOPE_PAYLOAD_ROOTS), $context);

        self::assertStringContainsString(
            'limited to root categories 29; 1 existing assignment(s) outside them were kept',
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

        $this->resolveAndProcess($this->processor, $context);

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

        $this->resolveAndProcess($this->processorWithScope(Config::REPLACE_SCOPE_PAYLOAD_ROOTS), $context);
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

        $this->resolveAndProcess($this->processorWithScope(Config::REPLACE_SCOPE_PAYLOAD_ROOTS), $context);

        self::assertStringContainsString(
            'limited to no root categories, so nothing was removed; 1 existing assignment(s) were kept',
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

        $this->resolveAndProcess($this->processor, $context);
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

        $this->resolveAndProcess($this->processor, $context);

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

        $this->resolveAndProcess($this->processor, $context);
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

        $this->resolveAndProcess($this->processor, $context);

        self::assertStringContainsString('applied additively', $context->getMessages('SKU-1')[1]);
    }

    /**
     * Without the pin reaching the resolver, a path under one of two same-named
     * roots resolves to the lowest ID — assigning the product in the wrong
     * catalog, with no error anywhere.
     */
    public function testTheRequestRootPinReachesThePathResolver(): void
    {
        $context = new BatchContext(
            [(new Product())->setSku('SKU-1')->setCategories(['Shop/Men'])],
            0,
            29
        );
        $context->setEntityId('SKU-1', 10);
        $context->setHeldLocks([ImportLocks::CATEGORY_TREE]);

        $this->pathResolver->expects(self::once())->method('resolvePaths')
            ->with(['Shop/Men' => ['Shop', 'Men']], 29)
            ->willReturn(['Shop/Men' => ['id' => 31, 'message' => null]]);
        $this->pathResolver->method('validateIds')->willReturn([]);
        $this->categoryLink->method('getAssignments')->willReturn([]);

        $this->resolveAndProcess($this->processor, $context);
    }

    /**
     * The pin reaches the non-creating walk too. Losing it there would resolve
     * the path under whichever root the name happens to pick — the same
     * wrong-catalog assignment, arrived at by the quiet path.
     */
    public function testTheRequestRootPinAlsoReachesTheLockFreeLookup(): void
    {
        $context = new BatchContext(
            [(new Product())->setSku('SKU-1')->setCategories(['Shop/Men'])],
            0,
            29
        );
        $context->setEntityId('SKU-1', 10);

        $this->pathResolver->expects(self::never())->method('resolvePaths');
        $this->pathResolver->expects(self::once())->method('lookupPaths')
            ->with(['Shop/Men' => ['Shop', 'Men']], 29)
            ->willReturn(['Shop/Men' => 31]);
        $this->pathResolver->method('validateIds')->willReturn([]);
        $this->categoryLink->method('getAssignments')->willReturn([]);

        $this->resolveAndProcess($this->processor, $context);

        self::assertSame([], $context->getMessages('SKU-1'));
    }

    /**
     * The predicate: a batch whose every path is already there creates nothing,
     * so it takes nothing. This is the case a steady-state feed is in on every
     * push, and the one the old "a categories field is present" test got wrong —
     * measured at 322 ms of hold and 572 ms of a competitor's wait, for nothing.
     */
    public function testNoLockIsTakenWhenEveryPathAlreadyResolves(): void
    {
        $context = $this->createContext(
            ['SKU-1' => ['Default Category/Men', '42']],
            ['SKU-1' => 10],
            holdsTreeLock: false
        );

        $this->pathResolver->method('lookupPaths')
            ->with(['Default Category/Men' => ['Default Category', 'Men']])
            ->willReturn(['Default Category/Men' => 5]);

        self::assertSame([], $this->processor->requiredLocks($context));
    }

    public function testTheTreeLockIsTakenWhenAPathHasToBeCreated(): void
    {
        $context = $this->createContext(
            ['SKU-1' => ['Default Category/Men/New Thing']],
            ['SKU-1' => 10],
            holdsTreeLock: false
        );

        $this->pathResolver->method('lookupPaths')->willReturn([]);

        // The rewrite lock comes with it: the repository save that creates a
        // category makes core's category-rewrite observer claim a request path,
        // in the same namespace and with the same default ".html" suffix as the
        // product rewrites this import writes.
        self::assertSame(
            [ImportLocks::CATEGORY_TREE, ImportLocks::URL_REWRITE],
            $this->processor->requiredLocks($context)
        );
    }

    /**
     * Neither a numeric ID nor an empty array can bring a category into
     * existence, so neither is worth serializing on. `[]` still removes every
     * link — deleting is not a read-then-create.
     *
     * @dataProvider referencesThatCreateNothing
     */
    public function testReferencesThatCannotCreateTakeNoLock(array $categories): void
    {
        $context = $this->createContext(['SKU-1' => $categories], ['SKU-1' => 10], holdsTreeLock: false);

        $this->pathResolver->expects(self::never())->method('lookupPaths');

        self::assertSame([], $this->processor->requiredLocks($context));
    }

    /**
     * @return array<string, array{string[]}>
     */
    public static function referencesThatCreateNothing(): array
    {
        return [
            'numeric IDs only' => [['42', '7']],
            'empty array' => [[]],
        ];
    }

    /**
     * The window the predicate cannot close: the path resolved when the lock
     * decision was made and does not now, so this batch holds nothing. Creating
     * it here is the unguarded read-then-create the lock exists to prevent, so
     * the product is reported and applied additively instead — its existing
     * links survive, and the retry's predicate takes the lock.
     */
    public function testAPathThatVanishesAfterTheLockDecisionIsReportedNotCreated(): void
    {
        $context = $this->createContext(
            ['SKU-1' => ['Default Category/Men']],
            ['SKU-1' => 10],
            holdsTreeLock: false
        );

        $this->pathResolver->expects(self::never())->method('resolvePaths');
        $this->pathResolver->method('lookupPaths')->willReturn([]);
        $this->categoryLink->method('getAssignments')->willReturn([10 => [7]]);

        // Additive: the link it could not resolve is not a reason to drop the
        // links it already has.
        $this->categoryLink->expects(self::once())->method('unassign')->with([]);

        $this->resolveAndProcess($this->processor, $context);

        $messages = $context->getMessages('SKU-1');
        self::assertStringContainsString('stopped resolving', $messages[0]);
        self::assertFalse($context->isFailed('SKU-1'));
    }

    /**
     * The defect this phase split exists to fix. A category that cannot be
     * created — its derived slug is taken, a required attribute has no default —
     * used to throw out of process(), which rolled the whole batch back and
     * failed every other product in it. It is now one product's warning, applied
     * additively, with the rest of the batch untouched.
     */
    public function testACreationFailureIsAPerProductWarningRatherThanABatchFailure(): void
    {
        $context = $this->createContext(
            ['SKU-1' => ['Default Category/Men/New Thing'], 'SKU-2' => ['42']],
            ['SKU-1' => 10, 'SKU-2' => 11]
        );

        $this->pathResolver->method('resolvePaths')->willReturn([
            'Default Category/Men/New Thing' => [
                'id' => null,
                'message' => 'Category "Default Category/Men/New Thing" was not created:'
                    . ' its URL key "new-thing" is already used by category ID 77.',
            ],
        ]);
        $this->pathResolver->method('validateIds')->willReturn([42 => true]);
        $this->categoryLink->method('getAssignments')->willReturn([10 => [7], 11 => []]);

        // SKU-1 keeps the links it had; SKU-2 is written in full.
        $this->categoryLink->expects(self::once())->method('unassign')->with([]);
        $this->categoryLink->expects(self::once())->method('assign')
            ->with([['category_id' => 42, 'product_id' => 11, 'position' => 0]]);

        $this->resolveAndProcess($this->processor, $context);

        $messages = $context->getMessages('SKU-1');
        self::assertStringContainsString('already used by category ID 77', $messages[0]);
        self::assertStringContainsString('applied additively', $messages[1]);
        self::assertFalse($context->isFailed('SKU-1'));
        self::assertFalse($context->isFailed('SKU-2'));
    }

    /**
     * Paths are resolved in the earlier phase, so a category can be deleted
     * between the resolve and the write. Writing the stale ID would fail on the
     * catalog_category_product foreign key and roll the batch back — the same
     * failure class the split removes — so it is reported instead.
     */
    public function testACategoryThatVanishedBetweenThePhasesIsReportedNotWritten(): void
    {
        $context = $this->createContext(['SKU-1' => ['Default Category/Men']], ['SKU-1' => 10]);

        $this->pathResolver->method('resolvePaths')
            ->willReturn(['Default Category/Men' => ['id' => 5, 'message' => null]]);
        $this->pathResolver->method('validateIds')->willReturn([]);
        $this->pathResolver->method('findVanished')->with([5])->willReturn([5 => true]);
        $this->categoryLink->method('getAssignments')->willReturn([10 => [7]]);

        $this->categoryLink->expects(self::once())->method('assign')->with([]);
        $this->categoryLink->expects(self::once())->method('unassign')->with([]);

        $this->resolveAndProcess($this->processor, $context);

        $messages = $context->getMessages('SKU-1');
        self::assertStringContainsString('was removed before its links could be written', $messages[0]);
        self::assertFalse($context->isFailed('SKU-1'));
    }

    /**
     * The resolution belongs to the phase that runs before the transaction;
     * process() only reads it. If process() ever resolves again it would be doing
     * so inside the transaction, which is what the resolver now refuses outright.
     */
    public function testProcessReadsTheResolutionRatherThanRepeatingIt(): void
    {
        $context = $this->createContext(['SKU-1' => ['Default Category/Men']], ['SKU-1' => 10]);

        $this->pathResolver->expects(self::once())->method('resolvePaths')
            ->willReturn(['Default Category/Men' => ['id' => 5, 'message' => null]]);
        $this->pathResolver->method('validateIds')->willReturn([]);
        $this->categoryLink->method('getAssignments')->willReturn([10 => []]);

        $this->processor->prepareUnderLocks($context);
        self::assertSame(
            ['Default Category/Men' => ['id' => 5, 'message' => null]],
            $context->get(CategoryLinkProcessor::CONTEXT_RESOLVED_PATHS)
        );

        $this->processor->process($context);

        self::assertSame([], $context->getMessages('SKU-1'));
    }

    /**
     * A payload that names no paths still marks the phase as having run, so a
     * missing bag key can only ever mean the phase was skipped.
     */
    public function testThePhaseAlwaysPublishesItsResultEvenWhenThereIsNothingToResolve(): void
    {
        $context = $this->createContext(['SKU-1' => ['42']], ['SKU-1' => 10]);

        $this->pathResolver->expects(self::never())->method('resolvePaths');

        $this->processor->prepareUnderLocks($context);

        self::assertSame([], $context->get(CategoryLinkProcessor::CONTEXT_RESOLVED_PATHS));
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
    /**
     * @param bool $holdsTreeLock whether the batch reserved the right to create
     *        categories. True by default because these tests are about link
     *        semantics, and a batch that names a path needing creation is
     *        exactly the batch whose predicate takes the lock; the lock-free
     *        walk has its own tests.
     */
    private function createContext(
        array $categoriesBySku,
        array $entityIds,
        array $replaceScopes = [],
        bool $holdsTreeLock = true
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
        if ($holdsTreeLock) {
            $context->setHeldLocks([ImportLocks::CATEGORY_TREE]);
        }

        return $context;
    }
}
