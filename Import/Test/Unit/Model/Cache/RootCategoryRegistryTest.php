<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Test\Unit\Model\Cache;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReadyData\Import\Model\Cache\RootCategoryRegistry;
use ReadyData\Import\Model\ResourceModel\Category as CategoryResource;

class RootCategoryRegistryTest extends TestCase
{
    private CategoryResource&MockObject $categoryResource;

    protected function setUp(): void
    {
        $this->categoryResource = $this->createMock(CategoryResource::class);
    }

    /**
     * @param array<string, int[]> $roots
     */
    private function registry(array $roots): RootCategoryRegistry
    {
        $this->categoryResource->method('getRootCategoryIds')->willReturn($roots);

        return new RootCategoryRegistry($this->categoryResource);
    }

    public function testAnUnambiguousNameResolvesForReadsAndWritesAlike(): void
    {
        $registry = $this->registry(['Default Category' => [2]]);

        foreach ([true, false] as $refuseAmbiguity) {
            $root = $registry->resolve('Default Category', null, $refuseAmbiguity);
            self::assertSame(RootCategoryRegistry::OUTCOME_OK, $root['outcome']);
            self::assertSame(2, $root['id']);
        }
    }

    /**
     * The policy split the whole class exists for: a read may settle a duplicate
     * name by taking the lowest entity ID, a write may not.
     */
    public function testADuplicateNameIsSettledForAReadAndRefusedForAWrite(): void
    {
        $registry = $this->registry(['Shop' => [2, 8]]);

        $read = $registry->resolve('Shop', null, false);
        self::assertSame(RootCategoryRegistry::OUTCOME_OK, $read['outcome']);
        self::assertSame(2, $read['id']);

        $write = $registry->resolve('Shop', null, true);
        self::assertSame(RootCategoryRegistry::OUTCOME_AMBIGUOUS, $write['outcome']);
        self::assertNull($write['id']);
        // The candidates travel with the refusal so the caller can name them.
        self::assertSame([2, 8], $write['candidates']);
    }

    public function testAPinPicksOneOfTwoRootsSharingAName(): void
    {
        $root = $this->registry(['Shop' => [2, 8]])->resolve('Shop', 8, true);

        self::assertSame(RootCategoryRegistry::OUTCOME_OK, $root['outcome']);
        self::assertSame(8, $root['id']);
    }

    public function testAPinThatIsNotARootIsRefused(): void
    {
        $root = $this->registry(['Default Category' => [2]])->resolve('Default Category', 99, true);

        self::assertSame(RootCategoryRegistry::OUTCOME_PIN_NOT_ROOT, $root['outcome']);
        self::assertNull($root['id']);
        self::assertNull($root['pinnedName']);
    }

    /**
     * Following the pin would file the category in a catalog the path did not
     * name; following the name would ignore the more specific statement.
     */
    public function testAPinNamingADifferentRootThanThePathIsRefusedWithBothNames(): void
    {
        $root = $this->registry([
            'Default Category' => [2],
            'Outdoor Catalog' => [8],
        ])->resolve('Default Category', 8, true);

        self::assertSame(RootCategoryRegistry::OUTCOME_PIN_NAME_MISMATCH, $root['outcome']);
        self::assertNull($root['id']);
        self::assertSame('Outdoor Catalog', $root['pinnedName']);
    }

    public function testAnUnknownNameIsReportedAsSuch(): void
    {
        $root = $this->registry(['Default Category' => [2]])->resolve('Nowhere', null, true);

        self::assertSame(RootCategoryRegistry::OUTCOME_UNKNOWN_NAME, $root['outcome']);
        self::assertSame([], $root['candidates']);
    }

    public function testIsRootAndNameOfCoverEveryCandidateNotJustTheLowest(): void
    {
        $registry = $this->registry(['Shop' => [2, 8]]);

        self::assertTrue($registry->isRoot(2));
        self::assertTrue($registry->isRoot(8));
        self::assertFalse($registry->isRoot(10));
        self::assertSame('Shop', $registry->nameOf(8));
        self::assertNull($registry->nameOf(10));
    }

    public function testTheMapIsReadOncePerRequestAndAgainAfterForget(): void
    {
        $calls = 0;
        $this->categoryResource->method('getRootCategoryIds')
            ->willReturnCallback(function () use (&$calls): array {
                $calls++;
                return ['Default Category' => [2]];
            });
        $registry = new RootCategoryRegistry($this->categoryResource);

        $registry->resolve('Default Category', null, true);
        $registry->isRoot(2);
        self::assertSame(1, $calls);

        // What a caller says after creating, renaming or removing a root.
        $registry->forget();
        $registry->resolve('Default Category', null, true);

        self::assertSame(2, $calls);
    }
}
