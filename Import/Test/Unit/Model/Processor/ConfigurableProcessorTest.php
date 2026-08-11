<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Test\Unit\Model\Processor;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReadyData\Import\Model\BatchContext;
use ReadyData\Import\Model\Cache\AttributeMetadataCache;
use ReadyData\Import\Model\Data\ConfigurableData;
use ReadyData\Import\Model\Data\Product;
use ReadyData\Import\Model\Processor\ConfigurableProcessor;
use ReadyData\Import\Model\Processor\EntityProcessor;
use ReadyData\Import\Model\ResourceModel\Configurable;
use ReadyData\Import\Model\ResourceModel\ProductEntity;

class ConfigurableProcessorTest extends TestCase
{
    private Configurable&MockObject $configurable;
    private AttributeMetadataCache&MockObject $metadataCache;
    private ProductEntity&MockObject $productEntity;
    private ConfigurableProcessor $processor;

    protected function setUp(): void
    {
        $this->configurable = $this->createMock(Configurable::class);
        $this->metadataCache = $this->createMock(AttributeMetadataCache::class);
        $this->productEntity = $this->createMock(ProductEntity::class);
        $this->processor = new ConfigurableProcessor(
            $this->configurable,
            $this->metadataCache,
            $this->productEntity
        );
    }

    public function testCreatesSuperAttributesAndLinksChildren(): void
    {
        $context = $this->createContext('P1', ['color', 'size'], ['C1', 'C2'], 10);
        $this->metadataCache->method('get')->willReturnMap([
            ['color', $this->attr(11, 'select', 1, 'Color')],
            ['size', $this->attr(22, 'select', 1, 'Size')],
        ]);
        $this->productEntity->method('getExistingBySkus')->willReturn([
            'C1' => $this->child(101, 'simple'),
            'C2' => $this->child(102, 'simple'),
        ]);
        $this->configurable->method('getSuperAttributes')->willReturn([]);
        $this->configurable->method('getChildLinks')->willReturn([]);

        $added = [];
        $this->configurable->method('addSuperAttribute')
            ->willReturnCallback(function (...$args) use (&$added): int {
                $added[] = $args;
                return count($added);
            });
        $this->configurable->expects(self::once())->method('linkChildren')->with([
            ['parent_id' => 10, 'child_id' => 101],
            ['parent_id' => 10, 'child_id' => 102],
        ]);
        $this->configurable->expects(self::once())->method('unlinkChildren')->with([]);
        $this->configurable->expects(self::never())->method('removeSuperAttributes');

        $this->processor->process($context);

        self::assertSame([[10, 11, 0, 'Color'], [10, 22, 1, 'Size']], $added);
        self::assertSame([], $context->getMessages('P1'));
    }

    public function testReplaceRemovesUndesiredChildAndAttribute(): void
    {
        $context = $this->createContext('P1', ['color'], ['C1'], 10);
        $this->metadataCache->method('get')->willReturn($this->attr(11, 'select', 1, 'Color'));
        $this->productEntity->method('getExistingBySkus')->willReturn([
            'C1' => $this->child(101, 'simple'),
        ]);
        // Current state has an extra attribute (size=22 => psa 999) and an extra child (102).
        $this->configurable->method('getSuperAttributes')->willReturn([10 => [11 => 500, 22 => 999]]);
        $this->configurable->method('getChildLinks')->willReturn([10 => [101, 102]]);
        $this->configurable->method('addSuperAttribute')->willReturn(1);

        $this->configurable->expects(self::once())->method('removeSuperAttributes')->with([999]);
        $this->configurable->expects(self::once())->method('linkChildren')->with([]);
        $this->configurable->expects(self::once())->method('unlinkChildren')
            ->with([['parent_id' => 10, 'child_id' => 102]]);

        $this->processor->process($context);

        self::assertSame([], $context->getMessages('P1'));
    }

    public function testUnresolvedChildWithholdsRemovalsAndWarns(): void
    {
        $context = $this->createContext('P1', ['color'], ['C1', 'MISSING'], 10);
        $this->metadataCache->method('get')->willReturn($this->attr(11, 'select', 1, 'Color'));
        $this->productEntity->method('getExistingBySkus')->willReturn([
            'C1' => $this->child(101, 'simple'),
        ]);
        $this->configurable->method('getSuperAttributes')->willReturn([10 => [11 => 500, 22 => 999]]);
        $this->configurable->method('getChildLinks')->willReturn([10 => [101, 102]]);

        // Additive: the resolvable child that is already linked yields no insert;
        // nothing is removed despite 102/999 no longer being desired.
        $this->configurable->expects(self::never())->method('removeSuperAttributes');
        $this->configurable->expects(self::once())->method('linkChildren')->with([]);
        $this->configurable->expects(self::once())->method('unlinkChildren')->with([]);

        $this->processor->process($context);

        $messages = $context->getMessages('P1');
        self::assertStringContainsString('Child SKU "MISSING" not found', $messages[0]);
        self::assertStringContainsString('applied additively', $messages[1]);
        self::assertFalse($context->isFailed('P1'));
    }

    public function testNonGlobalOrNonSelectSuperAttributeIsSkippedAndWarns(): void
    {
        $context = $this->createContext('P1', ['color', 'notes'], ['C1'], 10);
        $this->metadataCache->method('get')->willReturnMap([
            ['color', $this->attr(11, 'select', 0, 'Color')],  // store-scoped select
            ['notes', $this->attr(22, 'text', 1, 'Notes')],     // not a select
        ]);
        $this->productEntity->method('getExistingBySkus')->willReturn([
            'C1' => $this->child(101, 'simple'),
        ]);
        $this->configurable->method('getSuperAttributes')->willReturn([]);
        $this->configurable->method('getChildLinks')->willReturn([]);

        $this->configurable->expects(self::never())->method('addSuperAttribute');
        // A partial parent still links its resolvable children additively.
        $this->configurable->expects(self::once())->method('linkChildren')
            ->with([['parent_id' => 10, 'child_id' => 101]]);

        $this->processor->process($context);

        $messages = $context->getMessages('P1');
        self::assertCount(2, $messages);
        self::assertStringContainsString('global-scope select', $messages[0]);
        self::assertStringContainsString('global-scope select', $messages[1]);
    }

    public function testWrongTypeChildIsSkippedAndWarns(): void
    {
        $context = $this->createContext('P1', ['color'], ['C1'], 10);
        $this->metadataCache->method('get')->willReturn($this->attr(11, 'select', 1, 'Color'));
        $this->productEntity->method('getExistingBySkus')->willReturn([
            'C1' => $this->child(101, 'configurable'),
        ]);
        $this->configurable->method('getSuperAttributes')->willReturn([]);
        $this->configurable->method('getChildLinks')->willReturn([]);
        $this->configurable->method('addSuperAttribute')->willReturn(1);

        $this->configurable->expects(self::once())->method('linkChildren')->with([]);

        $this->processor->process($context);

        self::assertStringContainsString(
            'is not a simple/virtual product',
            $context->getMessages('P1')[0]
        );
    }

    public function testNoConfigurableBlockTouchesNothing(): void
    {
        $product = new Product();
        $product->setSku('P1');
        $product->setTypeId('configurable');
        $context = new BatchContext([$product]);
        $context->setEntityId('P1', 10);
        $context->set(EntityProcessor::CONTEXT_TYPE_IDS, ['P1' => 'configurable']);
        $context->set(EntityProcessor::CONTEXT_LINK_IDS, ['P1' => 10]);

        $this->productEntity->expects(self::never())->method('getExistingBySkus');
        $this->configurable->expects(self::never())->method('linkChildren');
        $this->configurable->expects(self::never())->method('unlinkChildren');

        $this->processor->process($context);
    }

    public function testOmittedSuperAttributesLeavesThemUntouched(): void
    {
        $configurable = new ConfigurableData();
        $configurable->setChildren(['C1']); // super_attributes omitted (null)
        $context = $this->contextFor('P1', $configurable, 10);

        $this->productEntity->method('getExistingBySkus')->willReturn([
            'C1' => $this->child(101, 'simple'),
        ]);
        $this->configurable->method('getSuperAttributes')->willReturn([10 => [11 => 500]]);
        $this->configurable->method('getChildLinks')->willReturn([]);

        // The omitted dimension is never inspected or modified.
        $this->metadataCache->expects(self::never())->method('get');
        $this->configurable->expects(self::never())->method('addSuperAttribute');
        $this->configurable->expects(self::never())->method('removeSuperAttributes');
        $this->configurable->expects(self::once())->method('linkChildren')
            ->with([['parent_id' => 10, 'child_id' => 101]]);
        $this->configurable->expects(self::once())->method('unlinkChildren')->with([]);

        $this->processor->process($context);
    }

    public function testOmittedChildrenLeavesLinksUntouched(): void
    {
        $configurable = new ConfigurableData();
        $configurable->setSuperAttributes(['color']); // children omitted (null)
        $context = $this->contextFor('P1', $configurable, 10);

        $this->metadataCache->method('get')->willReturn($this->attr(11, 'select', 1, 'Color'));
        $this->productEntity->method('getExistingBySkus')->willReturn([]);
        $this->configurable->method('getSuperAttributes')->willReturn([]);
        $this->configurable->method('getChildLinks')->willReturn([10 => [101, 102]]);

        $this->configurable->expects(self::once())->method('addSuperAttribute')->with(10, 11, 0, 'Color');
        $this->configurable->expects(self::once())->method('linkChildren')->with([]);
        // Existing children must NOT be unlinked when the field is omitted.
        $this->configurable->expects(self::once())->method('unlinkChildren')->with([]);

        $this->processor->process($context);
    }

    public function testWithheldAttributeRemovalIsAnnouncedWithoutChildRemoval(): void
    {
        $configurable = new ConfigurableData();
        $configurable->setSuperAttributes(['color', 'BADCODE'])->setChildren(['C1']);
        $context = $this->contextFor('P1', $configurable, 10);

        $this->metadataCache->method('get')->willReturnMap([
            ['color', $this->attr(11, 'select', 1, 'Color')],
            ['BADCODE', null],
        ]);
        $this->productEntity->method('getExistingBySkus')->willReturn([
            'C1' => $this->child(101, 'simple'),
        ]);
        // size(22) would be dropped; child 101 already linked (no child removal).
        $this->configurable->method('getSuperAttributes')->willReturn([10 => [11 => 500, 22 => 999]]);
        $this->configurable->method('getChildLinks')->willReturn([10 => [101]]);
        $this->configurable->method('addSuperAttribute')->willReturn(1);

        // Partial (BADCODE) withholds the size removal — and says so, even though
        // there is nothing to unlink on the children side.
        $this->configurable->expects(self::never())->method('removeSuperAttributes');
        $this->configurable->expects(self::once())->method('linkChildren')->with([]);
        $this->configurable->expects(self::once())->method('unlinkChildren')->with([]);

        $this->processor->process($context);

        $messages = $context->getMessages('P1');
        self::assertStringContainsString('Unknown super attribute "BADCODE"', $messages[0]);
        self::assertStringContainsString('applied additively', $messages[1]);
    }

    public function testNonConfigurableTypeIsIgnored(): void
    {
        $configurable = new ConfigurableData();
        $configurable->setSuperAttributes(['color'])->setChildren(['C1']);
        $product = new Product();
        $product->setSku('P1');
        $product->setTypeId('simple');
        $product->setConfigurable($configurable);
        $context = new BatchContext([$product]);
        $context->setEntityId('P1', 10);
        $context->set(EntityProcessor::CONTEXT_TYPE_IDS, ['P1' => 'simple']);
        $context->set(EntityProcessor::CONTEXT_LINK_IDS, ['P1' => 10]);

        $this->productEntity->expects(self::never())->method('getExistingBySkus');
        $this->configurable->expects(self::never())->method('linkChildren');

        $this->processor->process($context);
    }

    /**
     * @return array{attribute_id: int, attribute_code: string, backend_type: string,
     *     frontend_input: string, frontend_label: string, is_global: int, is_required: int}
     */
    private function attr(int $id, string $input, int $isGlobal, string $label): array
    {
        return [
            'attribute_id' => $id,
            'attribute_code' => 'attr' . $id,
            'backend_type' => 'int',
            'frontend_input' => $input,
            'frontend_label' => $label,
            'is_global' => $isGlobal,
            'is_required' => 0,
        ];
    }

    /**
     * @return array{entity_id: int, link_id: int, attribute_set_id: int, type_id: string}
     */
    private function child(int $entityId, string $typeId): array
    {
        return [
            'entity_id' => $entityId,
            'link_id' => $entityId,
            'attribute_set_id' => 4,
            'type_id' => $typeId,
        ];
    }

    /**
     * @param string[] $superAttributes
     * @param string[] $children
     */
    private function createContext(
        string $sku,
        array $superAttributes,
        array $children,
        int $entityId
    ): BatchContext {
        $configurable = new ConfigurableData();
        $configurable->setSuperAttributes($superAttributes)->setChildren($children);

        return $this->contextFor($sku, $configurable, $entityId);
    }

    private function contextFor(string $sku, ConfigurableData $configurable, int $entityId): BatchContext
    {
        $product = new Product();
        $product->setSku($sku);
        $product->setTypeId('configurable');
        $product->setConfigurable($configurable);

        $context = new BatchContext([$product]);
        $context->setEntityId($sku, $entityId);
        $context->set(EntityProcessor::CONTEXT_TYPE_IDS, [$sku => 'configurable']);
        $context->set(EntityProcessor::CONTEXT_LINK_IDS, [$sku => $entityId]);

        return $context;
    }
}
