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

    public function testDeleteFlagMustBeZeroOrOne(): void
    {
        $definition = (new CategoryDefinition())->setPath('Default Category/Men')->setDelete(2);

        $this->assertRejects($definition, CategorySyncResultInterface::REASON_INVALID_DEFINITION);
    }

    public function testDeleteChildrenFlagMustBeZeroOrOne(): void
    {
        $definition = (new CategoryDefinition())
            ->setPath('Default Category/Men')
            ->setDelete(1)
            ->setDeleteChildren(7);

        $this->assertRejects($definition, CategorySyncResultInterface::REASON_INVALID_DEFINITION);
    }

    public function testPlainDeleteIsAccepted(): void
    {
        $definition = (new CategoryDefinition())->setPath('Default Category/Men')->setDelete(1);

        self::assertSame(['Default Category', 'Men'], $this->validator->validate($definition));
    }

    public function testDeleteWithDeleteChildrenIsAccepted(): void
    {
        $definition = (new CategoryDefinition())
            ->setPath('Default Category/Men')
            ->setDelete(1)
            ->setDeleteChildren(1);

        self::assertSame(['Default Category', 'Men'], $this->validator->validate($definition));
    }

    public function testDeleteWithANameToCrossCheckIsAccepted(): void
    {
        // A bare category_id needs a corroborating field, and for a delete the
        // name is the only one available — it is checked, not written.
        $definition = (new CategoryDefinition())->setCategoryId(42)->setName('Clearance')->setDelete(1);

        self::assertSame([], $this->validator->validate($definition));
    }

    /**
     * @dataProvider deleteConflictProvider
     */
    public function testDeleteCannotAlsoSetValues(callable $mutate): void
    {
        $definition = (new CategoryDefinition())->setPath('Default Category/Men')->setDelete(1);
        $mutate($definition);

        $this->assertRejects($definition, CategorySyncResultInterface::REASON_INVALID_DEFINITION);
    }

    /**
     * @return array<string, array{callable}>
     */
    public static function deleteConflictProvider(): array
    {
        return [
            'url_key' => [static fn (CategoryDefinition $d) => $d->setUrlKey('mens')],
            'is_active' => [static fn (CategoryDefinition $d) => $d->setIsActive(0)],
            'include_in_menu' => [static fn (CategoryDefinition $d) => $d->setIncludeInMenu(1)],
            'is_anchor' => [static fn (CategoryDefinition $d) => $d->setIsAnchor(1)],
            'position' => [static fn (CategoryDefinition $d) => $d->setPosition(3)],
            'parent_path' => [static fn (CategoryDefinition $d) => $d->setParentPath('Default Category/Women')],
            'parent_category_id' => [static fn (CategoryDefinition $d) => $d->setParentCategoryId(9)],
            'custom_attributes' => [
                static fn (CategoryDefinition $d) => $d->setCustomAttributes([
                    (new CustomAttribute())->setAttributeCode('description')->setValue('x'),
                ]),
            ],
            'clear_attributes' => [static fn (CategoryDefinition $d) => $d->setClearAttributes(['meta_title'])],
        ];
    }

    public function testDeleteChildrenWithoutDeleteIsRejected(): void
    {
        $definition = (new CategoryDefinition())
            ->setPath('Default Category/Men')
            ->setDeleteChildren(1);

        $this->assertRejects($definition, CategorySyncResultInterface::REASON_INVALID_DEFINITION);
    }

    public function testNoParentPathMeansNoDestination(): void
    {
        $definition = (new CategoryDefinition())->setPath('Default Category/Men');

        self::assertSame([], $this->validator->validateParent($definition));
    }

    public function testBlankParentPathMeansNoDestination(): void
    {
        $definition = (new CategoryDefinition())->setPath('Default Category/Men')->setParentPath('   ');

        self::assertSame([], $this->validator->validateParent($definition));
    }

    public function testParentPathIsParsedWithTheSameGrammarAsPath(): void
    {
        $definition = (new CategoryDefinition())->setParentPath('Default Category/Wo\\/Men');

        self::assertSame(['Default Category', 'Wo/Men'], $this->validator->validateParent($definition));
    }

    public function testSingleSegmentParentPathNamesARoot(): void
    {
        $definition = (new CategoryDefinition())->setParentPath('Outdoor Catalog');

        self::assertSame(['Outdoor Catalog'], $this->validator->validateParent($definition));
    }

    public function testBareNumericParentPathIsRejected(): void
    {
        // parent_category_id is the field for that, so digits here can only be a
        // mistake — same reasoning as the entry's own path.
        $definition = (new CategoryDefinition())->setParentPath('42');

        try {
            $this->validator->validateParent($definition);
            self::fail('Expected the parent path to be rejected.');
        } catch (CategoryValidationException $e) {
            self::assertSame(CategorySyncResultInterface::REASON_INVALID_DEFINITION, $e->getReason());
        }
    }

    public function testEscapedNumericParentPathNamesACategoryCalledANumber(): void
    {
        $definition = (new CategoryDefinition())->setParentPath('\\42');

        self::assertSame(['42'], $this->validator->validateParent($definition));
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
