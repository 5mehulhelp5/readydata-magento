<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use ReadyData\Import\Api\Data\AttributeSyncResultInterface;
use ReadyData\Import\Model\AttributeValidator;
use ReadyData\Import\Model\Data\AttributeDefinition;
use ReadyData\Import\Model\Exception\AttributeValidationException;

class AttributeValidatorTest extends TestCase
{
    private AttributeValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new AttributeValidator();
    }

    /**
     * @dataProvider derivedBackendTypeProvider
     */
    public function testDerivesBackendTypeFromInput(string $input, string $expected): void
    {
        $definition = (new AttributeDefinition())->setAttributeCode('some_code')->setFrontendInput($input);

        self::assertSame($expected, $this->validator->resolveBackendType($definition));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function derivedBackendTypeProvider(): array
    {
        return [
            'text' => ['text', 'varchar'],
            'textarea' => ['textarea', 'text'],
            'select' => ['select', 'int'],
            'boolean' => ['boolean', 'int'],
            'multiselect' => ['multiselect', 'text'],
            'date' => ['date', 'datetime'],
            'datetime' => ['datetime', 'datetime'],
            'price' => ['price', 'decimal'],
        ];
    }

    public function testMultiselectIsForcedToTextEvenWhenPayloadSaysVarchar(): void
    {
        $definition = (new AttributeDefinition())
            ->setAttributeCode('features')
            ->setFrontendInput('multiselect')
            ->setBackendType('varchar');

        self::assertSame('text', $this->validator->resolveBackendType($definition));
    }

    public function testSuppliedBackendTypeIsHonouredWhenValid(): void
    {
        $definition = (new AttributeDefinition())
            ->setAttributeCode('rank')
            ->setFrontendInput('text')
            ->setBackendType('int');

        self::assertSame('int', $this->validator->resolveBackendType($definition));
    }

    public function testStaticBackendTypeIsRejected(): void
    {
        $definition = (new AttributeDefinition())
            ->setAttributeCode('sku')
            ->setFrontendInput('text')
            ->setBackendType('static');

        $this->assertRejectedWith($definition, AttributeSyncResultInterface::REASON_INVALID_DEFINITION);
    }

    public function testMissingFrontendInputIsRejectedOnCreate(): void
    {
        $definition = (new AttributeDefinition())->setAttributeCode('some_code');

        $this->assertRejectedWith($definition, AttributeSyncResultInterface::REASON_INVALID_DEFINITION);
    }

    public function testMissingFrontendInputOnUpdateResolvesFromExisting(): void
    {
        $definition = (new AttributeDefinition())->setAttributeCode('some_code');

        self::assertSame(
            'varchar',
            $this->validator->resolveBackendType($definition, ['backend_type' => 'varchar'])
        );
    }

    public function testSuppliedBackendTypeWinsOverExistingOnUpdate(): void
    {
        $definition = (new AttributeDefinition())->setAttributeCode('some_code')->setBackendType('int');

        self::assertSame(
            'int',
            $this->validator->resolveBackendType($definition, ['backend_type' => 'varchar'])
        );
    }

    public function testInvalidSuppliedBackendTypeOnUpdateIsRejected(): void
    {
        $definition = (new AttributeDefinition())->setAttributeCode('some_code')->setBackendType('static');

        try {
            $this->validator->resolveBackendType($definition, ['backend_type' => 'varchar']);
            self::fail('Expected AttributeValidationException was not thrown.');
        } catch (AttributeValidationException $e) {
            self::assertSame(AttributeSyncResultInterface::REASON_INVALID_DEFINITION, $e->getReason());
        }
    }

    public function testUnsupportedInputIsRejected(): void
    {
        $definition = (new AttributeDefinition())
            ->setAttributeCode('color_swatch')
            ->setFrontendInput('swatch_visual');

        $this->assertRejectedWith($definition, AttributeSyncResultInterface::REASON_UNSUPPORTED_TYPE);
    }

    public function testMissingModelClassIsRejected(): void
    {
        $definition = (new AttributeDefinition())
            ->setAttributeCode('brand')
            ->setFrontendInput('select')
            ->setSourceModel('Acme\\Does\\Not\\Exist');

        $this->assertRejectedWith($definition, AttributeSyncResultInterface::REASON_INVALID_DEFINITION);
    }

    /**
     * @dataProvider invalidCodeProvider
     */
    public function testInvalidCodeIsRejected(string $code): void
    {
        $definition = (new AttributeDefinition())->setAttributeCode($code)->setFrontendInput('text');

        $this->assertRejectedWith($definition, AttributeSyncResultInterface::REASON_INVALID_DEFINITION);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidCodeProvider(): array
    {
        return [
            'empty' => [''],
            'leading digit' => ['1color'],
            'uppercase' => ['Color'],
            'spaces' => ['my color'],
            'too long' => [str_repeat('a', 61)],
        ];
    }

    private function assertRejectedWith(AttributeDefinition $definition, string $expectedReason): void
    {
        try {
            $this->validator->resolveBackendType($definition);
            self::fail('Expected AttributeValidationException was not thrown.');
        } catch (AttributeValidationException $e) {
            self::assertSame($expectedReason, $e->getReason());
        }
    }
}
