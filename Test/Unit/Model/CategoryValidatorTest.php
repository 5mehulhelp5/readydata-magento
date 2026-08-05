<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use ReadyData\Import\Api\Data\CategorySyncResultInterface;
use ReadyData\Import\Model\Category\PathParser;
use ReadyData\Import\Model\CategoryValidator;
use ReadyData\Import\Model\Data\CategoryDefinition;
use ReadyData\Import\Model\Data\CustomAttribute;
use ReadyData\Import\Model\Exception\CategoryValidationException;

class CategoryValidatorTest extends TestCase
{
    private CategoryValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new CategoryValidator(new PathParser());
    }

    public function testReturnsParsedSegments(): void
    {
        $definition = (new CategoryDefinition())->setPath('Default Category/Men/Shirts');

        self::assertSame(['Default Category', 'Men', 'Shirts'], $this->validator->validate($definition));
    }

    public function testEscapedSeparatorStaysOneSegment(): void
    {
        $definition = (new CategoryDefinition())->setPath('Default Category/Wo\\/Men');

        self::assertSame(['Default Category', 'Wo/Men'], $this->validator->validate($definition));
    }

    public function testCategoryIdAloneIsEnoughWhenANameIsGiven(): void
    {
        $definition = (new CategoryDefinition())->setCategoryId(42)->setName('Shirts');

        self::assertSame([], $this->validator->validate($definition));
    }

    public function testNeitherPathNorCategoryIdIsRejected(): void
    {
        $this->assertRejects(
            (new CategoryDefinition())->setName('Shirts'),
            CategorySyncResultInterface::REASON_INVALID_DEFINITION
        );
    }

    public function testCategoryIdWithoutANameOrPathIsRejected(): void
    {
        $this->assertRejects(
            (new CategoryDefinition())->setCategoryId(42),
            CategorySyncResultInterface::REASON_INVALID_DEFINITION
        );
    }

    public function testBareNumericPathIsRejectedAsAnAmbiguousIdReference(): void
    {
        $this->assertRejects(
            (new CategoryDefinition())->setPath('42'),
            CategorySyncResultInterface::REASON_INVALID_DEFINITION
        );
    }

    public function testEmptyNameIsRejected(): void
    {
        $this->assertRejects(
            (new CategoryDefinition())->setPath('Default Category/Men')->setName('   '),
            CategorySyncResultInterface::REASON_INVALID_DEFINITION
        );
    }

    /**
     * @dataProvider flagProvider
     */
    public function testFlagsMustBeZeroOrOne(string $setter): void
    {
        $definition = (new CategoryDefinition())->setPath('Default Category/Men');
        $definition->{$setter}(7);

        $this->assertRejects($definition, CategorySyncResultInterface::REASON_INVALID_DEFINITION);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function flagProvider(): array
    {
        return [
            'is_active' => ['setIsActive'],
            'include_in_menu' => ['setIncludeInMenu'],
            'is_anchor' => ['setIsAnchor'],
        ];
    }

    /**
     * @dataProvider structuralAttributeProvider
     */
    public function testStructuralAttributesCannotBeSet(string $code): void
    {
        $definition = (new CategoryDefinition())
            ->setPath('Default Category/Men')
            ->setCustomAttributes([(new CustomAttribute())->setAttributeCode($code)->setValue('1')]);

        $this->assertRejects($definition, CategorySyncResultInterface::REASON_INVALID_DEFINITION);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function structuralAttributeProvider(): array
    {
        return [
            'path' => ['path'],
            'level' => ['level'],
            'parent_id' => ['parent_id'],
            'children_count' => ['children_count'],
            'url_path' => ['url_path'],
            'row_id' => ['row_id'],
        ];
    }

    /**
     * @dataProvider protectedClearProvider
     */
    public function testProtectedAttributesCannotBeCleared(string $code): void
    {
        $definition = (new CategoryDefinition())
            ->setPath('Default Category/Men')
            ->setClearAttributes([$code]);

        $this->assertRejects($definition, CategorySyncResultInterface::REASON_PROTECTED_ATTRIBUTE);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function protectedClearProvider(): array
    {
        return [
            // Clearing the name or url_key strands the category's rewrites and
            // its descendants' url_path with nothing left to repair them.
            'name' => ['name'],
            'url_key' => ['url_key'],
            'is_active' => ['is_active'],
            'url_path' => ['url_path'],
        ];
    }

    public function testOrdinaryAttributesAreAccepted(): void
    {
        $definition = (new CategoryDefinition())
            ->setPath('Default Category/Men')
            ->setCustomAttributes([
                (new CustomAttribute())->setAttributeCode('description')->setValue('<p>Hi</p>'),
                (new CustomAttribute())->setAttributeCode('display_mode')->setValue('PRODUCTS'),
            ])
            ->setClearAttributes(['meta_title', 'meta_keywords']);

        self::assertSame(['Default Category', 'Men'], $this->validator->validate($definition));
    }

    private function assertRejects(CategoryDefinition $definition, string $expectedReason): void
    {
        try {
            $this->validator->validate($definition);
            self::fail('Expected the definition to be rejected.');
        } catch (CategoryValidationException $e) {
            self::assertSame($expectedReason, $e->getReason());
        }
    }
}
