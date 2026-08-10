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
use ReadyData\Import\Model\Cache\StoreWebsiteMap;
use ReadyData\Import\Model\Data\CustomAttribute;
use ReadyData\Import\Model\Data\Product;
use ReadyData\Import\Model\Data\ProductStoreValues;
use ReadyData\Import\Model\Processor\EavValueProcessor;
use ReadyData\Import\Model\Processor\EntityProcessor;
use ReadyData\Import\Model\ResourceModel\AttributeOption;
use ReadyData\Import\Model\ResourceModel\EavValue;
use ReadyData\Import\Model\ResourceModel\ProductEntity;
use ReadyData\Import\Model\UrlKeyGenerator;

class EavValueProcessorTest extends TestCase
{
    private const META = [
        'special_price' => [
            'attribute_id' => 77,
            'attribute_code' => 'special_price',
            'backend_type' => 'decimal',
            'frontend_input' => 'price',
            'is_global' => 1,
            'is_required' => 0,
        ],
        'special_from_date' => [
            'attribute_id' => 78,
            'attribute_code' => 'special_from_date',
            'backend_type' => 'datetime',
            'frontend_input' => 'date',
            'is_global' => 1,
            'is_required' => 0,
        ],
        'special_to_date' => [
            'attribute_id' => 79,
            'attribute_code' => 'special_to_date',
            'backend_type' => 'datetime',
            'frontend_input' => 'date',
            'is_global' => 1,
            'is_required' => 0,
        ],
        'store_note' => [
            'attribute_id' => 80,
            'attribute_code' => 'store_note',
            'backend_type' => 'varchar',
            'frontend_input' => 'text',
            'is_global' => 0,
            'is_required' => 0,
        ],
        'brand' => [
            'attribute_id' => 81,
            'attribute_code' => 'brand',
            'backend_type' => 'varchar',
            'frontend_input' => 'text',
            'is_global' => 2,
            'is_required' => 1,
        ],
        'name' => [
            'attribute_id' => 73,
            'attribute_code' => 'name',
            'backend_type' => 'varchar',
            'frontend_input' => 'text',
            'is_global' => 0,
            'is_required' => 1,
        ],
        'url_key' => [
            'attribute_id' => 97,
            'attribute_code' => 'url_key',
            'backend_type' => 'varchar',
            'frontend_input' => 'text',
            'is_global' => 0,
            'is_required' => 0,
        ],
    ];

    private AttributeMetadataCache&MockObject $attributeMetadataCache;
    private EavValue&MockObject $eavValue;
    private StoreWebsiteMap&MockObject $storeWebsiteMap;
    private EavValueProcessor $processor;

    /**
     * @var array<int, array{string, array}> [backendType, rows] per upsert call
     */
    private array $upserts = [];

    /**
     * @var array<int, array{string, array}> [backendType, keys] per delete call
     */
    private array $deletes = [];

    protected function setUp(): void
    {
        $this->attributeMetadataCache = $this->createMock(AttributeMetadataCache::class);
        $this->attributeMetadataCache->method('get')
            ->willReturnCallback(static fn (string $code): ?array => self::META[$code] ?? null);

        $this->eavValue = $this->createMock(EavValue::class);
        $this->eavValue->method('upsert')->willReturnCallback(
            function (string $backendType, array $rows): void {
                $this->upserts[] = [$backendType, $rows];
            }
        );
        $this->eavValue->method('delete')->willReturnCallback(
            function (string $backendType, array $keys): void {
                $this->deletes[] = [$backendType, $keys];
            }
        );

        $productEntity = $this->createMock(ProductEntity::class);
        $productEntity->method('getLinkField')->willReturn('entity_id');

        $this->storeWebsiteMap = $this->createMock(StoreWebsiteMap::class);
        // The store views this fixture Magento has. Anything else resolves to
        // null, which is how a payload naming a store view that does not exist
        // reaches the processor.
        $this->storeWebsiteMap->method('findScopeStoreId')->willReturnCallback(
            static fn (?int $storeId, ?string $code): ?int => match (true) {
                $storeId !== null => in_array($storeId, [0, 2, 3, 4, 5], true) ? $storeId : null,
                default => ['de_de' => 3, 'fr_fr' => 5][$code] ?? null,
            }
        );

        $this->processor = new EavValueProcessor(
            $this->attributeMetadataCache,
            $this->createMock(AttributeOption::class),
            $this->eavValue,
            $productEntity,
            $this->createMock(UrlKeyGenerator::class),
            $this->storeWebsiteMap
        );
    }

    public function testDatetimeValueInMysqlFormatIsWrittenVerbatim(): void
    {
        $context = $this->createContext(['special_from_date' => '2026-08-01 00:00:00']);

        $this->processor->process($context);

        self::assertSame(
            [['datetime', [
                ['entity_id' => 10, 'attribute_id' => 78, 'store_id' => 0, 'value' => '2026-08-01 00:00:00'],
            ]]],
            $this->upserts
        );
        self::assertSame([], $context->getMessages('SKU-1'));
    }

    public function testDatetimeValueWithOffsetIsNormalizedToUtc(): void
    {
        $context = $this->createContext(['special_from_date' => '2026-08-01T10:00:00+02:00']);

        $this->processor->process($context);

        self::assertSame(
            [['datetime', [
                ['entity_id' => 10, 'attribute_id' => 78, 'store_id' => 0, 'value' => '2026-08-01 08:00:00'],
            ]]],
            $this->upserts
        );
    }

    public function testUnparseableDatetimeIsSkippedWithMessage(): void
    {
        $context = $this->createContext(['special_from_date' => 'not-a-date']);

        $this->processor->process($context);

        self::assertSame([], $this->upserts);
        $messages = $context->getMessages('SKU-1');
        self::assertCount(1, $messages);
        self::assertStringContainsString('could not be resolved', $messages[0]);
        self::assertFalse($context->isFailed('SKU-1'));
    }

    public function testEmptyDatetimeIsSkippedInsteadOfBecomingNow(): void
    {
        $context = $this->createContext(['special_from_date' => ' ']);

        $this->processor->process($context);

        self::assertSame([], $this->upserts);
        self::assertStringContainsString('could not be resolved', $context->getMessages('SKU-1')[0]);
    }

    public function testNumericDecimalIsCastToFloat(): void
    {
        $context = $this->createContext(['special_price' => '9.99']);

        $this->processor->process($context);

        self::assertSame(
            [['decimal', [
                ['entity_id' => 10, 'attribute_id' => 77, 'store_id' => 0, 'value' => 9.99],
            ]]],
            $this->upserts
        );
    }

    public function testNonNumericDecimalIsSkippedInsteadOfWritingZero(): void
    {
        $context = $this->createContext(['special_price' => 'abc']);

        $this->processor->process($context);

        self::assertSame([], $this->upserts);
        $messages = $context->getMessages('SKU-1');
        self::assertCount(1, $messages);
        self::assertStringContainsString('could not be resolved', $messages[0]);
    }

    public function testClearAttributesDeletesRowsPerBackendType(): void
    {
        $context = $this->createContext(
            [],
            ['special_price', 'special_from_date', 'special_to_date']
        );

        $this->processor->process($context);

        self::assertSame([], $this->upserts);
        self::assertSame(
            [
                ['decimal', [
                    ['link_id' => 10, 'attribute_id' => 77, 'store_id' => 0],
                ]],
                ['datetime', [
                    ['link_id' => 10, 'attribute_id' => 78, 'store_id' => 0],
                    ['link_id' => 10, 'attribute_id' => 79, 'store_id' => 0],
                ]],
            ],
            $this->deletes
        );
    }

    public function testAttributeBothWrittenAndClearedKeepsTheWrittenValue(): void
    {
        $context = $this->createContext(['special_price' => '5.50'], ['special_price']);

        $this->processor->process($context);

        self::assertSame(
            [['decimal', [
                ['entity_id' => 10, 'attribute_id' => 77, 'store_id' => 0, 'value' => 5.5],
            ]]],
            $this->upserts
        );
        self::assertSame([], $this->deletes);
        self::assertStringContainsString('the write wins', $context->getMessages('SKU-1')[0]);
    }

    public function testGlobalAttributeWritesDefaultRowRegardlessOfRequestStore(): void
    {
        $context = $this->createContext(['special_price' => '9.99'], [], storeId: 3);

        $this->processor->process($context);

        self::assertSame(
            [['decimal', [
                ['entity_id' => 10, 'attribute_id' => 77, 'store_id' => 0, 'value' => 9.99],
            ]]],
            $this->upserts
        );
    }

    public function testStoreScopedAttributeWritesRequestStoreRowOnly(): void
    {
        $context = $this->createContext(['store_note' => 'hello'], [], storeId: 3);

        $this->processor->process($context);

        self::assertSame(
            [['varchar', [
                ['entity_id' => 10, 'attribute_id' => 80, 'store_id' => 3, 'value' => 'hello'],
            ]]],
            $this->upserts
        );
    }

    public function testWebsiteScopedAttributeFansOutToAllStoreViewsOfWebsite(): void
    {
        $this->storeWebsiteMap->method('getWebsiteStoreIds')->with(3)->willReturn([2, 3, 4]);
        $context = $this->createContext(['brand' => 'Acme'], [], storeId: 3);

        $this->processor->process($context);

        self::assertSame(
            [['varchar', [
                ['entity_id' => 10, 'attribute_id' => 81, 'store_id' => 2, 'value' => 'Acme'],
                ['entity_id' => 10, 'attribute_id' => 81, 'store_id' => 3, 'value' => 'Acme'],
                ['entity_id' => 10, 'attribute_id' => 81, 'store_id' => 4, 'value' => 'Acme'],
            ]]],
            $this->upserts
        );
    }

    public function testWebsiteScopedAttributeAtAdminScopeWritesDefaultRowOnly(): void
    {
        $this->storeWebsiteMap->expects(self::never())->method('getWebsiteStoreIds');
        $context = $this->createContext(['brand' => 'Acme'], [], storeId: 0);

        $this->processor->process($context);

        self::assertSame(
            [['varchar', [
                ['entity_id' => 10, 'attribute_id' => 81, 'store_id' => 0, 'value' => 'Acme'],
            ]]],
            $this->upserts
        );
    }

    public function testNewProductGetsSingleDefaultFallbackRow(): void
    {
        $this->storeWebsiteMap->method('getWebsiteStoreIds')->with(3)->willReturn([2, 3]);
        $context = $this->createContext(['brand' => 'Acme'], [], storeId: 3, existing: false);

        $this->processor->process($context);

        self::assertSame(
            [['varchar', [
                ['entity_id' => 10, 'attribute_id' => 81, 'store_id' => 2, 'value' => 'Acme'],
                ['entity_id' => 10, 'attribute_id' => 81, 'store_id' => 3, 'value' => 'Acme'],
                ['entity_id' => 10, 'attribute_id' => 81, 'store_id' => 0, 'value' => 'Acme'],
            ]]],
            $this->upserts
        );
    }

    public function testWebsiteScopedClearDeletesAllStoreViewsOfWebsite(): void
    {
        $this->storeWebsiteMap->method('getWebsiteStoreIds')->with(3)->willReturn([2, 3]);
        $context = $this->createContext([], ['brand'], storeId: 3);

        $this->processor->process($context);

        self::assertSame([], $this->upserts);
        self::assertSame(
            [['varchar', [
                ['link_id' => 10, 'attribute_id' => 81, 'store_id' => 2],
                ['link_id' => 10, 'attribute_id' => 81, 'store_id' => 3],
            ]]],
            $this->deletes
        );
        self::assertSame([], $context->getMessages('SKU-1'));
    }

    public function testRequiredWebsiteScopedAttributeCannotBeClearedAtDefaultScope(): void
    {
        $context = $this->createContext([], ['brand'], storeId: 0);

        $this->processor->process($context);

        self::assertSame([], $this->deletes);
        $messages = $context->getMessages('SKU-1');
        self::assertCount(1, $messages);
        self::assertStringContainsString('required and cannot be cleared', $messages[0]);
    }

    public function testStoreValuesBlockWritesItsOwnScopeAlongsideTheBasePass(): void
    {
        $context = $this->createContext(
            ['brand' => 'Acme'],
            storeValues: [$this->block(3, ['store_note' => 'Hallo'])]
        );

        $this->processor->process($context);

        self::assertSame(
            [['varchar', [
                ['entity_id' => 10, 'attribute_id' => 81, 'store_id' => 0, 'value' => 'Acme'],
                ['entity_id' => 10, 'attribute_id' => 80, 'store_id' => 3, 'value' => 'Hallo'],
            ]]],
            $this->upserts
        );
        self::assertSame([], $context->getMessages('SKU-1'));
    }

    public function testStoreValuesBlockResolvesItsScopeByCode(): void
    {
        $block = new ProductStoreValues();
        $block->setStoreViewCode('de_de');
        $block->setName('Winterjacke');

        $context = $this->createContext([], storeValues: [$block]);

        $this->processor->process($context);

        self::assertSame(
            [['varchar', [
                ['entity_id' => 10, 'attribute_id' => 73, 'store_id' => 3, 'value' => 'Winterjacke'],
            ]]],
            $this->upserts
        );
    }

    public function testUnknownStoreViewSkipsOnlyThatBlock(): void
    {
        $context = $this->createContext(
            ['brand' => 'Acme'],
            storeValues: [$this->block(99, ['store_note' => 'lost'])]
        );

        $this->processor->process($context);

        self::assertSame(
            [['varchar', [
                ['entity_id' => 10, 'attribute_id' => 81, 'store_id' => 0, 'value' => 'Acme'],
            ]]],
            $this->upserts
        );
        self::assertSame(
            ['Store values for store view ID 99 were skipped: no such store view.'],
            $context->getMessages('SKU-1')
        );
        self::assertFalse($context->isFailed('SKU-1'));
    }

    public function testGlobalAttributeInAScopedBlockIsRefusedInsteadOfWrittenAtDefaultScope(): void
    {
        $context = $this->createContext(
            ['special_price' => '9.99'],
            storeValues: [$this->block(3, ['special_price' => '7.99'])]
        );

        $this->processor->process($context);

        // Only the product's own value reaches store 0; the scoped one would
        // have overwritten it from a block that named store 3.
        self::assertSame(
            [['decimal', [
                ['entity_id' => 10, 'attribute_id' => 77, 'store_id' => 0, 'value' => 9.99],
            ]]],
            $this->upserts
        );
        self::assertStringContainsString(
            '[store 3] Attribute "special_price" is global',
            $context->getMessages('SKU-1')[0]
        );
    }

    public function testUrlKeyInAScopedBlockIsRefused(): void
    {
        $context = $this->createContext([], storeValues: [$this->block(3, ['url_key' => 'winterjacke'])]);

        $this->processor->process($context);

        self::assertSame([], $this->upserts);
        self::assertStringContainsString(
            '[store 3] Attribute "url_key" cannot be set per store view',
            $context->getMessages('SKU-1')[0]
        );
    }

    public function testWebsiteScopedAttributeInAScopedBlockFansOutAcrossThatStoresWebsite(): void
    {
        $this->storeWebsiteMap->method('getWebsiteStoreIds')->with(3)->willReturn([2, 3]);
        $context = $this->createContext([], storeValues: [$this->block(3, ['brand' => 'Acme'])]);

        $this->processor->process($context);

        self::assertSame(
            [['varchar', [
                ['entity_id' => 10, 'attribute_id' => 81, 'store_id' => 2, 'value' => 'Acme'],
                ['entity_id' => 10, 'attribute_id' => 81, 'store_id' => 3, 'value' => 'Acme'],
            ]]],
            $this->upserts
        );
    }

    public function testScopedBlockNeverAddsTheDefaultFallbackRowForANewProduct(): void
    {
        $context = $this->createContext(
            [],
            storeValues: [$this->block(3, ['store_note' => 'Hallo'])],
            existing: false
        );

        $this->processor->process($context);

        // A store-0 row here would make one store view's translation the value
        // every other store view falls back to.
        self::assertSame(
            [['varchar', [
                ['entity_id' => 10, 'attribute_id' => 80, 'store_id' => 3, 'value' => 'Hallo'],
            ]]],
            $this->upserts
        );
    }

    public function testBlockAddressingTheRequestScopeMergesIntoItAndWins(): void
    {
        $context = $this->createContext(
            ['store_note' => 'from the product'],
            storeId: 3,
            storeValues: [$this->block(3, ['store_note' => 'from the block'])]
        );

        $this->processor->process($context);

        self::assertSame(
            [['varchar', [
                ['entity_id' => 10, 'attribute_id' => 80, 'store_id' => 3, 'value' => 'from the block'],
            ]]],
            $this->upserts
        );
        self::assertStringContainsString('addressed more than once', $context->getMessages('SKU-1')[0]);
    }

    public function testTwoBlocksForTheSameStoreMergeWithTheLastWinning(): void
    {
        $context = $this->createContext([], storeValues: [
            $this->block(3, ['store_note' => 'first', 'brand' => 'Acme']),
            $this->block(3, ['store_note' => 'second']),
        ]);
        $this->storeWebsiteMap->method('getWebsiteStoreIds')->with(3)->willReturn([3]);

        $this->processor->process($context);

        self::assertSame(
            [['varchar', [
                ['entity_id' => 10, 'attribute_id' => 80, 'store_id' => 3, 'value' => 'second'],
                ['entity_id' => 10, 'attribute_id' => 81, 'store_id' => 3, 'value' => 'Acme'],
            ]]],
            $this->upserts
        );
    }

    public function testScopedClearDeletesInThatScopeOnly(): void
    {
        $block = $this->block(3, []);
        $block->setClearAttributes(['store_note']);
        $context = $this->createContext([], storeValues: [$block]);

        $this->processor->process($context);

        self::assertSame([], $this->upserts);
        self::assertSame(
            [['varchar', [
                ['link_id' => 10, 'attribute_id' => 80, 'store_id' => 3],
            ]]],
            $this->deletes
        );
    }

    public function testScopedMessagesAreReadableBothFlattenedAndPerScope(): void
    {
        $context = $this->createContext(
            ['nonesuch' => 'x'],
            storeValues: [$this->block(3, ['special_price' => '7.99'])]
        );

        $this->processor->process($context);

        self::assertSame(
            [
                'Unknown attribute "nonesuch" skipped.',
                '[store 3] Attribute "special_price" is global and has no store dimension; the scoped value was'
                . ' skipped rather than written at the default scope, where it would overwrite the product\'s own'
                . ' value. Send it on the product.',
            ],
            $context->getMessages('SKU-1')
        );
        self::assertSame(['Unknown attribute "nonesuch" skipped.'], $context->getScopeMessages('SKU-1', null));
        self::assertCount(1, $context->getScopeMessages('SKU-1', 3));
        self::assertSame([], $context->getScopeMessages('SKU-1', 4));
    }

    /**
     * @param array<string, string> $customAttributes code => value
     */
    private function block(int $storeId, array $customAttributes): ProductStoreValues
    {
        $block = new ProductStoreValues();
        $block->setStoreId($storeId);
        $block->setCustomAttributes($this->customAttributes($customAttributes));

        return $block;
    }

    /**
     * @param array<string, string> $values code => value
     * @return CustomAttribute[]
     */
    private function customAttributes(array $values): array
    {
        return array_map(
            static function (string $code, string $value): CustomAttribute {
                $attribute = new CustomAttribute();
                $attribute->setAttributeCode($code);
                $attribute->setValue($value);

                return $attribute;
            },
            array_keys($values),
            $values
        );
    }

    /**
     * @param array<string, string> $customAttributes code => value
     * @param string[] $clearAttributes
     * @param ProductStoreValues[] $storeValues
     */
    private function createContext(
        array $customAttributes,
        array $clearAttributes = [],
        int $storeId = 0,
        bool $existing = true,
        array $storeValues = []
    ): BatchContext {
        $product = new Product();
        $product->setSku('SKU-1');
        $product->setCustomAttributes($this->customAttributes($customAttributes));
        if ($clearAttributes) {
            $product->setClearAttributes($clearAttributes);
        }
        if ($storeValues) {
            $product->setStoreValues($storeValues);
        }

        $context = new BatchContext([$product], $storeId);
        $context->set(EntityProcessor::CONTEXT_LINK_IDS, ['SKU-1' => 10]);
        if ($existing) {
            $context->markExisting('SKU-1');
        }

        return $context;
    }
}
