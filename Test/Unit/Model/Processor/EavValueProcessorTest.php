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
use ReadyData\Import\Model\Data\CustomAttribute;
use ReadyData\Import\Model\Data\Product;
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
    ];

    private AttributeMetadataCache&MockObject $attributeMetadataCache;
    private EavValue&MockObject $eavValue;
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

        $this->processor = new EavValueProcessor(
            $this->attributeMetadataCache,
            $this->createMock(AttributeOption::class),
            $this->eavValue,
            $productEntity,
            $this->createMock(UrlKeyGenerator::class)
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

    /**
     * @param array<string, string> $customAttributes code => value
     * @param string[] $clearAttributes
     */
    private function createContext(array $customAttributes, array $clearAttributes = []): BatchContext
    {
        $product = new Product();
        $product->setSku('SKU-1');
        $product->setCustomAttributes(array_map(
            static function (string $code, string $value): CustomAttribute {
                $attribute = new CustomAttribute();
                $attribute->setAttributeCode($code);
                $attribute->setValue($value);

                return $attribute;
            },
            array_keys($customAttributes),
            $customAttributes
        ));
        if ($clearAttributes) {
            $product->setClearAttributes($clearAttributes);
        }

        $context = new BatchContext([$product]);
        $context->set(EntityProcessor::CONTEXT_LINK_IDS, ['SKU-1' => 10]);
        $context->markExisting('SKU-1');

        return $context;
    }
}
