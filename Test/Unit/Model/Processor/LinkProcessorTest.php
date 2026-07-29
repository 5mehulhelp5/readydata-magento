<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Test\Unit\Model\Processor;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReadyData\Import\Model\BatchContext;
use ReadyData\Import\Model\Data\Product;
use ReadyData\Import\Model\Data\ProductLinks;
use ReadyData\Import\Model\Processor\EntityProcessor;
use ReadyData\Import\Model\Processor\LinkProcessor;
use ReadyData\Import\Model\ResourceModel\ProductEntity;
use ReadyData\Import\Model\ResourceModel\ProductLink;

class LinkProcessorTest extends TestCase
{
    /** Position attribute IDs as core seeds them: one per link type. */
    private const POSITION_ATTRIBUTE_IDS = [
        ProductLink::TYPE_RELATED => 11,
        ProductLink::TYPE_UP_SELL => 44,
        ProductLink::TYPE_CROSS_SELL => 55,
    ];

    private ProductLink&MockObject $productLink;
    private ProductEntity&MockObject $productEntity;
    private LinkProcessor $processor;

    protected function setUp(): void
    {
        $this->productLink = $this->createMock(ProductLink::class);
        $this->productEntity = $this->createMock(ProductEntity::class);
        $this->processor = new LinkProcessor($this->productLink, $this->productEntity);
    }

    public function testCreatesLinksForAllThreeTypesWithPositions(): void
    {
        $links = (new ProductLinks())
            ->setRelated(['T1', 'T2'])
            ->setUpSell(['T3'])
            ->setCrossSell(['T4']);
        $context = $this->contextFor('P1', $links, 10);

        $this->productEntity->method('getExistingBySkus')->willReturn([
            'T1' => $this->target(101),
            'T2' => $this->target(102),
            'T3' => $this->target(103),
            'T4' => $this->target(104),
        ]);
        // Empty before the insert; the refresh read picks up the new link IDs.
        $this->productLink->expects(self::exactly(2))->method('getLinks')
            ->willReturnOnConsecutiveCalls([], [
                10 => [
                    ProductLink::TYPE_RELATED => [
                        101 => $this->linkRow(1001, null),
                        102 => $this->linkRow(1002, null),
                    ],
                    ProductLink::TYPE_UP_SELL => [103 => $this->linkRow(1003, null)],
                    ProductLink::TYPE_CROSS_SELL => [104 => $this->linkRow(1004, null)],
                ],
            ]);
        $this->productLink->method('getPositionAttributeIds')->willReturn(self::POSITION_ATTRIBUTE_IDS);

        $this->productLink->expects(self::once())->method('deleteLinks')->with([]);
        $this->productLink->expects(self::once())->method('insertLinks')->with([
            $this->linkTuple(ProductLink::TYPE_RELATED, 10, 101),
            $this->linkTuple(ProductLink::TYPE_RELATED, 10, 102),
            $this->linkTuple(ProductLink::TYPE_UP_SELL, 10, 103),
            $this->linkTuple(ProductLink::TYPE_CROSS_SELL, 10, 104),
        ]);
        $this->productLink->expects(self::once())->method('savePositions')->with([
            $this->positionRow(11, 1001, 0),
            $this->positionRow(11, 1002, 1),
            $this->positionRow(44, 1003, 0),
            $this->positionRow(55, 1004, 0),
        ]);

        $this->processor->process($context);

        self::assertSame([], $context->getMessages('P1'));
    }

    public function testReplaceRemovesUndesiredLinksOfThatTypeOnly(): void
    {
        // "related" present, the other two omitted, while all three exist.
        $context = $this->contextFor('P1', (new ProductLinks())->setRelated(['T1']), 10);

        $this->productEntity->method('getExistingBySkus')->willReturn(['T1' => $this->target(101)]);
        $this->productLink->expects(self::once())->method('getLinks')->willReturn([
            10 => [
                ProductLink::TYPE_RELATED => [
                    101 => $this->linkRow(1001, 0),
                    102 => $this->linkRow(1002, 1),
                ],
                ProductLink::TYPE_UP_SELL => [103 => $this->linkRow(1003, 0)],
                ProductLink::TYPE_CROSS_SELL => [104 => $this->linkRow(1004, 0)],
            ],
        ]);

        $this->productLink->expects(self::once())->method('deleteLinks')
            ->with([$this->linkTuple(ProductLink::TYPE_RELATED, 10, 102)]);
        $this->productLink->expects(self::once())->method('insertLinks')->with([]);
        // 101 is already linked at position 0 — nothing to rewrite.
        $this->productLink->expects(self::never())->method('savePositions');

        $this->processor->process($context);

        self::assertSame([], $context->getMessages('P1'));
    }

    public function testOmittedSubFieldLeavesThatTypeUntouched(): void
    {
        $context = $this->contextFor('P1', (new ProductLinks())->setCrossSell([]), 10);

        $this->productEntity->method('getExistingBySkus')->willReturn([]);
        $this->productLink->method('getLinks')->willReturn([
            10 => [
                ProductLink::TYPE_RELATED => [101 => $this->linkRow(1001, 0)],
                ProductLink::TYPE_UP_SELL => [103 => $this->linkRow(1003, 0)],
            ],
        ]);

        // Only the declared type may be touched, and it has nothing to remove.
        $this->productLink->expects(self::once())->method('deleteLinks')->with([]);
        $this->productLink->expects(self::once())->method('insertLinks')->with([]);

        $this->processor->process($context);
    }

    public function testEmptyArrayRemovesAllLinksOfThatType(): void
    {
        $context = $this->contextFor('P1', (new ProductLinks())->setRelated([]), 10);

        $this->productEntity->method('getExistingBySkus')->willReturn([]);
        $this->productLink->method('getLinks')->willReturn([
            10 => [
                ProductLink::TYPE_RELATED => [
                    101 => $this->linkRow(1001, 0),
                    102 => $this->linkRow(1002, 1),
                ],
            ],
        ]);

        $this->productLink->expects(self::once())->method('deleteLinks')->with([
            $this->linkTuple(ProductLink::TYPE_RELATED, 10, 101),
            $this->linkTuple(ProductLink::TYPE_RELATED, 10, 102),
        ]);
        $this->productLink->expects(self::once())->method('insertLinks')->with([]);
        $this->productLink->expects(self::never())->method('savePositions');

        $this->processor->process($context);
    }

    public function testUnknownTargetSkuWithholdsRemovalsAndWarns(): void
    {
        $context = $this->contextFor('P1', (new ProductLinks())->setRelated(['T1', 'MISSING']), 10);

        $this->productEntity->method('getExistingBySkus')->willReturn(['T1' => $this->target(101)]);
        $this->productLink->method('getLinks')->willReturn([
            10 => [
                ProductLink::TYPE_RELATED => [
                    101 => $this->linkRow(1001, 0),
                    102 => $this->linkRow(1002, 1),
                ],
            ],
        ]);

        // Additive: 102 is no longer desired but survives; 101 is already linked
        // at position 0, so there is nothing to write at all.
        $this->productLink->expects(self::once())->method('deleteLinks')->with([]);
        $this->productLink->expects(self::once())->method('insertLinks')->with([]);

        $this->processor->process($context);

        $messages = $context->getMessages('P1');
        self::assertStringContainsString('Linked SKU "MISSING" not found', $messages[0]);
        self::assertStringContainsString('applied additively', $messages[1]);
        self::assertFalse($context->isFailed('P1'));
    }

    public function testSafetyValveIsScopedToOneLinkType(): void
    {
        $links = (new ProductLinks())
            ->setRelated(['MISSING'])
            ->setCrossSell(['T1']);
        $context = $this->contextFor('P1', $links, 10);

        $this->productEntity->method('getExistingBySkus')->willReturn(['T1' => $this->target(101)]);
        $this->productLink->method('getLinks')->willReturn([
            10 => [
                ProductLink::TYPE_RELATED => [102 => $this->linkRow(1002, 0)],
                ProductLink::TYPE_CROSS_SELL => [
                    101 => $this->linkRow(1003, 0),
                    103 => $this->linkRow(1004, 1),
                ],
            ],
        ]);

        // The unresolved "related" SKU withholds only the related removal; the
        // clean cross-sell set still drops its obsolete link.
        $this->productLink->expects(self::once())->method('deleteLinks')
            ->with([$this->linkTuple(ProductLink::TYPE_CROSS_SELL, 10, 103)]);
        $this->productLink->expects(self::once())->method('insertLinks')->with([]);

        $this->processor->process($context);

        $messages = $context->getMessages('P1');
        self::assertCount(2, $messages);
        self::assertStringContainsString('Linked SKU "MISSING" not found', $messages[0]);
        self::assertStringContainsString('Related links applied additively', $messages[1]);
    }

    public function testSelfLinkIsSkippedAndDoesNotWithholdRemovals(): void
    {
        $context = $this->contextFor('P1', (new ProductLinks())->setRelated(['P1', 'T1']), 10);

        $this->productEntity->method('getExistingBySkus')->willReturn([
            'P1' => $this->target(10),
            'T1' => $this->target(101),
        ]);
        $this->productLink->method('getLinks')->willReturn([
            10 => [
                ProductLink::TYPE_RELATED => [
                    101 => $this->linkRow(1001, 0),
                    102 => $this->linkRow(1002, 1),
                ],
            ],
        ]);

        // The obsolete link is still removed — a self-reference must not make
        // the product permanently additive.
        $this->productLink->expects(self::once())->method('deleteLinks')
            ->with([$this->linkTuple(ProductLink::TYPE_RELATED, 10, 102)]);
        $this->productLink->expects(self::once())->method('insertLinks')->with([]);

        $this->processor->process($context);

        $messages = $context->getMessages('P1');
        self::assertCount(1, $messages);
        self::assertStringContainsString('refers to the product itself', $messages[0]);
    }

    public function testDuplicateTargetSkusAreDeduplicatedPreservingFirstPosition(): void
    {
        $context = $this->contextFor('P1', (new ProductLinks())->setRelated(['T1', 'T1', 'T2']), 10);

        $this->productEntity->method('getExistingBySkus')->willReturn([
            'T1' => $this->target(101),
            'T2' => $this->target(102),
        ]);
        $this->productLink->method('getLinks')->willReturnOnConsecutiveCalls([], [
            10 => [
                ProductLink::TYPE_RELATED => [
                    101 => $this->linkRow(1001, null),
                    102 => $this->linkRow(1002, null),
                ],
            ],
        ]);
        $this->productLink->method('getPositionAttributeIds')->willReturn(self::POSITION_ATTRIBUTE_IDS);

        $this->productLink->expects(self::once())->method('insertLinks')->with([
            $this->linkTuple(ProductLink::TYPE_RELATED, 10, 101),
            $this->linkTuple(ProductLink::TYPE_RELATED, 10, 102),
        ]);
        // Gap-free positions: the duplicate does not consume position 1.
        $this->productLink->expects(self::once())->method('savePositions')->with([
            $this->positionRow(11, 1001, 0),
            $this->positionRow(11, 1002, 1),
        ]);

        $this->processor->process($context);

        self::assertSame([], $context->getMessages('P1'));
    }

    public function testEmptyTargetSkuIsSkippedAndWarns(): void
    {
        $context = $this->contextFor('P1', (new ProductLinks())->setRelated(['', '  ', 'T1']), 10);

        $this->productEntity->expects(self::once())->method('getExistingBySkus')
            ->with(['T1'])
            ->willReturn(['T1' => $this->target(101)]);
        $this->productLink->method('getLinks')->willReturnOnConsecutiveCalls([
            10 => [ProductLink::TYPE_RELATED => [102 => $this->linkRow(1002, 0)]],
        ], [
            10 => [
                ProductLink::TYPE_RELATED => [
                    101 => $this->linkRow(1001, null),
                    102 => $this->linkRow(1002, 0),
                ],
            ],
        ]);
        $this->productLink->method('getPositionAttributeIds')->willReturn(self::POSITION_ATTRIBUTE_IDS);

        $this->productLink->expects(self::once())->method('insertLinks')
            ->with([$this->linkTuple(ProductLink::TYPE_RELATED, 10, 101)]);
        $this->productLink->expects(self::once())->method('savePositions')
            ->with([$this->positionRow(11, 1001, 0)]);
        // Empty entries are unresolvable, so removals are withheld.
        $this->productLink->expects(self::once())->method('deleteLinks')->with([]);

        $this->processor->process($context);

        $messages = $context->getMessages('P1');
        self::assertCount(3, $messages);
        self::assertSame('Empty linked SKU skipped.', $messages[0]);
        self::assertSame('Empty linked SKU skipped.', $messages[1]);
        self::assertStringContainsString('applied additively', $messages[2]);
    }

    public function testNoLinksBlockTouchesNothing(): void
    {
        $product = (new Product())->setSku('P1');
        $context = new BatchContext([$product]);
        $context->setEntityId('P1', 10);
        $context->set(EntityProcessor::CONTEXT_LINK_IDS, ['P1' => 10]);

        $this->expectNoLinkWork();

        $this->processor->process($context);
    }

    public function testEmptyLinksBlockTouchesNothing(): void
    {
        // "links": {} — all three sub-fields null, so not even a read is warranted.
        $context = $this->contextFor('P1', new ProductLinks(), 10);

        $this->expectNoLinkWork();

        $this->processor->process($context);
    }

    public function testUnchangedSetPerformsNoWrites(): void
    {
        $context = $this->contextFor('P1', (new ProductLinks())->setRelated(['T1', 'T2']), 10);

        $this->productEntity->method('getExistingBySkus')->willReturn([
            'T1' => $this->target(101),
            'T2' => $this->target(102),
        ]);
        // No inserts, so no refresh read.
        $this->productLink->expects(self::once())->method('getLinks')->willReturn([
            10 => [
                ProductLink::TYPE_RELATED => [
                    101 => $this->linkRow(1001, 0),
                    102 => $this->linkRow(1002, 1),
                ],
            ],
        ]);

        $this->productLink->expects(self::once())->method('deleteLinks')->with([]);
        $this->productLink->expects(self::once())->method('insertLinks')->with([]);
        $this->productLink->expects(self::never())->method('savePositions');

        $this->processor->process($context);
    }

    public function testPositionIsUpdatedForExistingLinkWhenOrderChanges(): void
    {
        $context = $this->contextFor('P1', (new ProductLinks())->setRelated(['T1', 'T2']), 10);

        $this->productEntity->method('getExistingBySkus')->willReturn([
            'T1' => $this->target(101),
            'T2' => $this->target(102),
        ]);
        // Stored the other way round — reorder without any structural change.
        $this->productLink->expects(self::once())->method('getLinks')->willReturn([
            10 => [
                ProductLink::TYPE_RELATED => [
                    101 => $this->linkRow(1001, 1),
                    102 => $this->linkRow(1002, 0),
                ],
            ],
        ]);
        $this->productLink->method('getPositionAttributeIds')->willReturn(self::POSITION_ATTRIBUTE_IDS);

        $this->productLink->expects(self::once())->method('insertLinks')->with([]);
        $this->productLink->expects(self::once())->method('deleteLinks')->with([]);
        $this->productLink->expects(self::once())->method('savePositions')->with([
            $this->positionRow(11, 1001, 0),
            $this->positionRow(11, 1002, 1),
        ]);

        $this->processor->process($context);
    }

    public function testUsesLinkFieldForSourceAndEntityIdForTarget(): void
    {
        // EE shape: the link field (row_id) differs from entity_id on both sides.
        $context = $this->contextFor('P1', (new ProductLinks())->setRelated(['T1']), 10, 555);

        $this->productEntity->method('getExistingBySkus')->willReturn([
            'T1' => $this->target(101, 777),
        ]);
        $this->productLink->expects(self::exactly(2))->method('getLinks')
            ->with([555], [ProductLink::TYPE_RELATED, ProductLink::TYPE_UP_SELL, ProductLink::TYPE_CROSS_SELL])
            ->willReturnOnConsecutiveCalls([], [
                555 => [ProductLink::TYPE_RELATED => [101 => $this->linkRow(1001, null)]],
            ]);
        $this->productLink->method('getPositionAttributeIds')->willReturn(self::POSITION_ATTRIBUTE_IDS);

        // product_id is the source's LINK FIELD; linked_product_id the target's ENTITY_ID.
        $this->productLink->expects(self::once())->method('insertLinks')
            ->with([$this->linkTuple(ProductLink::TYPE_RELATED, 555, 101)]);
        $this->productLink->expects(self::once())->method('savePositions')
            ->with([$this->positionRow(11, 1001, 0)]);

        $this->processor->process($context);
    }

    public function testOnlyRelatedUpSellAndCrossSellTypesAreRead(): void
    {
        $context = $this->contextFor('P1', (new ProductLinks())->setRelated([]), 10);

        $this->productEntity->method('getExistingBySkus')->willReturn([]);
        // Grouped (link type 3) is never read or written.
        $this->productLink->expects(self::once())->method('getLinks')
            ->with([10], [ProductLink::TYPE_RELATED, ProductLink::TYPE_UP_SELL, ProductLink::TYPE_CROSS_SELL])
            ->willReturn([]);

        $this->processor->process($context);
    }

    public function testMissingPositionAttributeStillLinksWithoutPositions(): void
    {
        $context = $this->contextFor('P1', (new ProductLinks())->setRelated(['T1']), 10);

        $this->productEntity->method('getExistingBySkus')->willReturn(['T1' => $this->target(101)]);
        $this->productLink->method('getLinks')->willReturnOnConsecutiveCalls([], [
            10 => [ProductLink::TYPE_RELATED => [101 => $this->linkRow(1001, null)]],
        ]);
        $this->productLink->method('getPositionAttributeIds')->willReturn([]);

        $this->productLink->expects(self::once())->method('insertLinks')
            ->with([$this->linkTuple(ProductLink::TYPE_RELATED, 10, 101)]);
        $this->productLink->expects(self::once())->method('savePositions')->with([]);

        $this->processor->process($context);
    }

    public function testProductWithoutResolvedIdsIsSkipped(): void
    {
        $product = (new Product())->setSku('P1');
        $product->setLinks((new ProductLinks())->setRelated(['T1']));
        // No entity ID and no CONTEXT_LINK_IDS entry — EntityProcessor never got there.
        $context = new BatchContext([$product]);

        $this->productEntity->method('getExistingBySkus')->willReturn(['T1' => $this->target(101)]);
        $this->productLink->expects(self::never())->method('getLinks');
        $this->productLink->expects(self::never())->method('insertLinks');
        $this->productLink->expects(self::never())->method('deleteLinks');

        $this->processor->process($context);
    }

    public function testFailedProductIsIgnored(): void
    {
        $context = $this->contextFor('P1', (new ProductLinks())->setRelated(['T1']), 10);
        $context->fail('P1', 'Earlier processor failed this product.');

        $this->expectNoLinkWork();

        $this->processor->process($context);
    }

    private function expectNoLinkWork(): void
    {
        $this->productEntity->expects(self::never())->method('getExistingBySkus');
        $this->productLink->expects(self::never())->method('getLinks');
        $this->productLink->expects(self::never())->method('insertLinks');
        $this->productLink->expects(self::never())->method('deleteLinks');
        $this->productLink->expects(self::never())->method('savePositions');
    }

    /**
     * @return array{entity_id: int, link_id: int, attribute_set_id: int, type_id: string}
     */
    private function target(int $entityId, ?int $linkId = null): array
    {
        return [
            'entity_id' => $entityId,
            'link_id' => $linkId ?? $entityId,
            'attribute_set_id' => 4,
            'type_id' => 'simple',
        ];
    }

    /**
     * @return array{link_id: int, position: int|null}
     */
    private function linkRow(int $linkId, ?int $position): array
    {
        return ['link_id' => $linkId, 'position' => $position];
    }

    /**
     * @return array{link_type_id: int, product_id: int, linked_product_id: int}
     */
    private function linkTuple(int $typeId, int $sourceLinkId, int $targetEntityId): array
    {
        return [
            'link_type_id' => $typeId,
            'product_id' => $sourceLinkId,
            'linked_product_id' => $targetEntityId,
        ];
    }

    /**
     * @return array{product_link_attribute_id: int, link_id: int, value: int}
     */
    private function positionRow(int $attributeId, int $linkId, int $value): array
    {
        return [
            'product_link_attribute_id' => $attributeId,
            'link_id' => $linkId,
            'value' => $value,
        ];
    }

    private function contextFor(
        string $sku,
        ProductLinks $links,
        int $entityId,
        ?int $linkId = null
    ): BatchContext {
        $product = (new Product())->setSku($sku);
        $product->setLinks($links);

        $context = new BatchContext([$product]);
        $context->setEntityId($sku, $entityId);
        $context->set(EntityProcessor::CONTEXT_LINK_IDS, [$sku => $linkId ?? $entityId]);

        return $context;
    }
}
