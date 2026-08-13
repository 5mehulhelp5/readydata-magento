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
use ReadyData\Import\Model\Config;
use ReadyData\Import\Model\Data\CustomAttribute;
use ReadyData\Import\Model\Data\Product;
use ReadyData\Import\Model\Data\ProductStoreValues;
use ReadyData\Import\Model\ImportLocks;
use ReadyData\Import\Model\Processor\AttributeProcessor;
use ReadyData\Import\Model\ResourceModel\AttributeOption;

/**
 * The option lock predicate, and the guard behind it.
 *
 * The predicate answers "will this batch create an option", not "could it" —
 * the difference being every push of a feed whose option labels were created
 * the first time it ran, which is nearly all of them.
 */
class AttributeProcessorTest extends TestCase
{
    private const COLOR_ID = 77;

    private AttributeMetadataCache&MockObject $metadataCache;
    private AttributeOption&MockObject $attributeOption;

    /** @var array<string, int> labels the option table already holds, lowercased */
    private array $existingOptions = [];

    protected function setUp(): void
    {
        $this->existingOptions = ['red' => 501];

        $this->metadataCache = $this->createMock(AttributeMetadataCache::class);
        $this->metadataCache->method('get')->willReturnCallback(
            static fn (string $code): ?array => match ($code) {
                'color' => [
                    'attribute_id' => self::COLOR_ID,
                    'frontend_input' => 'select',
                    'backend_type' => 'int',
                ],
                'features' => [
                    'attribute_id' => 88,
                    'frontend_input' => 'multiselect',
                    'backend_type' => 'text',
                ],
                'description' => [
                    'attribute_id' => 99,
                    'frontend_input' => 'textarea',
                    'backend_type' => 'text',
                ],
                'status' => [
                    'attribute_id' => 96,
                    'frontend_input' => 'select',
                    'backend_type' => 'int',
                ],
                default => null,
            }
        );

        $this->attributeOption = $this->createMock(AttributeOption::class);
        $this->attributeOption->method('getOptionId')->willReturnCallback(
            fn (int $attributeId, string $label): ?int => $this->existingOptions[mb_strtolower($label)] ?? null
        );
    }

    private function processor(bool $createMissingOptions = true): AttributeProcessor
    {
        $config = $this->createMock(Config::class);
        $config->method('isCreateMissingOptions')->willReturn($createMissingOptions);

        return new AttributeProcessor($this->metadataCache, $this->attributeOption, $config);
    }

    /**
     * @param array<string, string> $customAttributes code => value
     */
    private function context(array $customAttributes, bool $holdsLock = false): BatchContext
    {
        $product = (new Product())->setSku('SKU-1');
        $attributes = [];
        foreach ($customAttributes as $code => $value) {
            $attributes[] = (new CustomAttribute())->setAttributeCode($code)->setValue($value);
        }
        $product->setCustomAttributes($attributes);

        $context = new BatchContext([$product]);
        if ($holdsLock) {
            $context->setHeldLocks([ImportLocks::ATTRIBUTE_OPTIONS]);
        }

        return $context;
    }

    /**
     * The case the old predicate got wrong: a feed sends `color: Red` on every
     * push, and `Red` was created by the first one. Resolving an option is a
     * read; only the insert is a race.
     */
    public function testALabelThatAlreadyExistsTakesNoLock(): void
    {
        self::assertSame([], $this->processor()->requiredLocks($this->context(['color' => 'Red'])));
    }

    public function testAMissingLabelTakesTheOptionLock(): void
    {
        self::assertSame(
            [ImportLocks::ATTRIBUTE_OPTIONS],
            $this->processor()->requiredLocks($this->context(['color' => 'Chartreuse']))
        );
    }

    public function testNothingIsTakenWhenAutoCreationIsOff(): void
    {
        // Nothing is ever created, so there is nothing to serialize on.
        $this->attributeOption->expects(self::never())->method('getOptionId');

        self::assertSame(
            [],
            $this->processor(createMissingOptions: false)->requiredLocks($this->context(['color' => 'Chartreuse']))
        );
    }

    /**
     * A multiselect value is a comma-joined list, and the option table holds the
     * parts. Checking the whole string would find "Red,Blue" missing and take
     * the lock for two labels that are both already there — or worse, find it
     * present and skip a lock the create then needed.
     */
    public function testMultiselectValuesAreSplitBeforeTheyAreLookedUp(): void
    {
        $this->existingOptions = ['red' => 501, 'blue' => 502];

        self::assertSame([], $this->processor()->requiredLocks($this->context(['features' => 'Red,Blue'])));
        self::assertSame(
            [ImportLocks::ATTRIBUTE_OPTIONS],
            $this->processor()->requiredLocks($this->context(['features' => 'Red,Green']))
        );
    }

    public function testLabelsAreTrimmedTheSameWayTheCreateTrimsThem(): void
    {
        // "  Red  " is the option "Red"; a predicate that did not trim would
        // report it missing and take a lock the create does not need.
        self::assertSame([], $this->processor()->requiredLocks($this->context(['color' => '  Red  '])));
    }

    /**
     * @dataProvider valuesThatCannotCreateAnOption
     */
    public function testValuesThatCannotCreateAnOptionTakeNoLock(string $code, string $value): void
    {
        self::assertSame([], $this->processor()->requiredLocks($this->context([$code => $value])));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function valuesThatCannotCreateAnOption(): array
    {
        return [
            // Not a select at all — the value is just text.
            'text attribute' => ['description', 'Anything at all'],
            // Options come from a static PHP source, not eav_attribute_option.
            'static-source select' => ['status', '1'],
            // Unknown code: no metadata, so nothing to create an option on.
            'unknown attribute' => ['nope', 'Whatever'],
            'empty value' => ['color', ''],
        ];
    }

    /**
     * A scoped block's custom attributes only ever resolve labels they did not
     * create, so locking for them buys nothing. The harvest and the predicate
     * read the same field for exactly that reason.
     */
    public function testCustomAttributesInsideAStoreValuesBlockAreNotConsulted(): void
    {
        $product = (new Product())->setSku('SKU-1')->setStoreValues([
            (new ProductStoreValues())->setStoreId(3)->setCustomAttributes([
                (new CustomAttribute())->setAttributeCode('color')->setValue('Chartreuse'),
            ]),
        ]);

        self::assertSame([], $this->processor()->requiredLocks(new BatchContext([$product])));
    }

    public function testOptionsAreCreatedWhenTheBatchHoldsTheLock(): void
    {
        $this->attributeOption->expects(self::once())->method('createOptions')
            ->with(self::COLOR_ID, ['Chartreuse'])
            ->willReturn(['chartreuse' => 900]);

        $context = $this->context(['color' => 'Chartreuse'], holdsLock: true);
        $this->processor()->process($context);

        self::assertSame(
            ['color' => ['chartreuse' => 900]],
            $context->get(AttributeProcessor::CONTEXT_CREATED_OPTIONS)
        );
    }

    /**
     * The window the predicate cannot close: the label was there when the lock
     * decision was made and is gone now, so this batch holds nothing. Creating
     * it here is the unguarded insert the lock exists to prevent — the label is
     * left unresolved instead, EavValueProcessor reports it as an unknown
     * option, and the retry's predicate takes the lock.
     */
    public function testNothingIsCreatedWhenTheBatchDoesNotHoldTheLock(): void
    {
        $this->attributeOption->expects(self::never())->method('createOptions');

        $context = $this->context(['color' => 'Chartreuse'], holdsLock: false);
        $this->processor()->process($context);

        self::assertNull($context->get(AttributeProcessor::CONTEXT_CREATED_OPTIONS));
    }

    /**
     * Warmed whether or not anything is created: EavValueProcessor resolves
     * every label through this memo, including the ones already there.
     */
    public function testTheOptionMemoIsWarmedEvenWhenNothingIsCreated(): void
    {
        $this->attributeOption->expects(self::atLeastOnce())->method('warm')
            ->with([self::COLOR_ID]);

        $this->processor()->process($this->context(['color' => 'Red'], holdsLock: false));
    }

    /**
     * Every code in the payload, including one carrying an empty value: the rest
     * of the pipeline needs the metadata to write or refuse it, and only the
     * lock predicate cares about the values.
     */
    public function testMetadataIsWarmedForEveryCodeIncludingValuelessOnes(): void
    {
        $this->metadataCache->expects(self::atLeastOnce())->method('warm')
            ->with(self::callback(
                static fn (array $codes): bool => in_array('description', $codes, true)
                    && in_array('name', $codes, true)
            ));

        $this->processor()->process($this->context(['description' => '']));
    }
}
