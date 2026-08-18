<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Test\Unit\Model\Media;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReadyData\Import\Model\Cache\AttributeMetadataCache;
use ReadyData\Import\Model\Media\MediaReferenceChecker;
use ReadyData\Import\Model\ResourceModel\EavValue;
use ReadyData\Import\Model\ResourceModel\ProductMediaGallery;

class MediaReferenceCheckerTest extends TestCase
{
    private const ROLES = ['image', 'small_image', 'thumbnail', 'swatch_image'];

    private ProductMediaGallery&MockObject $gallery;
    private EavValue&MockObject $eavValue;
    private AttributeMetadataCache&MockObject $attributeMetadataCache;

    protected function setUp(): void
    {
        $this->gallery = $this->createMock(ProductMediaGallery::class);
        $this->eavValue = $this->createMock(EavValue::class);
        $this->attributeMetadataCache = $this->createMock(AttributeMetadataCache::class);
    }

    public function testAFileNothingPointsAtIsReportedUnreferenced(): void
    {
        $this->gallery->method('findReferencedFiles')->willReturn([]);
        $this->stubRoles(['image' => 90, 'small_image' => 91, 'thumbnail' => 92, 'swatch_image' => 93]);
        $this->eavValue->method('findValuesInUse')->willReturn([]);

        self::assertSame(['/a/a/one.jpg'], $this->checker()->getUnreferenced(['/a/a/one.jpg']));
    }

    public function testAFileStillInSomeProductsGalleryIsExcluded(): void
    {
        $this->gallery->method('findReferencedFiles')->willReturn(['/a/a/one.jpg']);
        $this->stubRoles(['image' => 90, 'small_image' => 91, 'thumbnail' => 92, 'swatch_image' => 93]);
        $this->eavValue->method('findValuesInUse')->willReturn([]);

        self::assertSame(
            ['/b/b/two.jpg'],
            $this->checker()->getUnreferenced(['/a/a/one.jpg', '/b/b/two.jpg'])
        );
    }

    /**
     * The case the gallery pass alone gets wrong: a base image can point at a file
     * whose gallery row hangs off a different product, so the role attributes are
     * a reference in their own right.
     */
    public function testAFileHeldOnlyByARoleAttributeIsExcluded(): void
    {
        $this->gallery->method('findReferencedFiles')->willReturn([]);
        $this->stubRoles(['image' => 90, 'small_image' => 91, 'thumbnail' => 92, 'swatch_image' => 93]);
        $this->eavValue->expects(self::once())->method('findValuesInUse')
            ->with('varchar', [90, 91, 92, 93], ['/a/a/one.jpg', '/b/b/two.jpg'])
            ->willReturn(['/a/a/one.jpg']);

        self::assertSame(
            ['/b/b/two.jpg'],
            $this->checker()->getUnreferenced(['/a/a/one.jpg', '/b/b/two.jpg'])
        );
    }

    /**
     * The role pass only ever sees what the gallery pass could not account for —
     * the point of ordering the two.
     */
    public function testTheRolePassIsSkippedWhenTheGalleryAccountsForEverything(): void
    {
        $this->gallery->method('findReferencedFiles')->willReturn(['/a/a/one.jpg', '/b/b/two.jpg']);
        $this->attributeMetadataCache->expects(self::never())->method('warm');
        $this->eavValue->expects(self::never())->method('findValuesInUse');

        self::assertSame([], $this->checker()->getUnreferenced(['/a/a/one.jpg', '/b/b/two.jpg']));
    }

    public function testTheRolePassOnlyAsksAboutWhatTheGalleryLeftOver(): void
    {
        $this->gallery->method('findReferencedFiles')->willReturn(['/a/a/one.jpg']);
        $this->stubRoles(['image' => 90, 'small_image' => 91, 'thumbnail' => 92, 'swatch_image' => 93]);
        $this->eavValue->expects(self::once())->method('findValuesInUse')
            ->with('varchar', [90, 91, 92, 93], ['/b/b/two.jpg'])
            ->willReturn([]);

        self::assertSame(
            ['/b/b/two.jpg'],
            $this->checker()->getUnreferenced(['/a/a/one.jpg', '/b/b/two.jpg'])
        );
    }

    /**
     * A role attribute whose values live in another table gets its own query
     * rather than being asked for against the wrong one.
     */
    public function testRolesAreGroupedByTheirBackendType(): void
    {
        $this->gallery->method('findReferencedFiles')->willReturn([]);
        $this->stubRoles(
            ['image' => 90, 'small_image' => 91, 'thumbnail' => 92, 'swatch_image' => 93],
            ['image' => 'varchar', 'small_image' => 'varchar', 'thumbnail' => 'varchar', 'swatch_image' => 'text']
        );

        $asked = [];
        $this->eavValue->method('findValuesInUse')->willReturnCallback(
            function (string $backendType, array $attributeIds) use (&$asked): array {
                $asked[$backendType] = $attributeIds;
                return [];
            }
        );

        $this->checker()->getUnreferenced(['/a/a/one.jpg']);

        self::assertSame(['varchar' => [90, 91, 92], 'text' => [93]], $asked);
    }

    /**
     * A role code the store never installed narrows the check; it must not widen
     * it into reporting everything as unreferenced, nor query attribute_id NULL.
     */
    public function testAnUnknownRoleCodeIsDroppedRatherThanQueried(): void
    {
        $this->gallery->method('findReferencedFiles')->willReturn([]);
        $this->stubRoles(['image' => 90, 'small_image' => 91, 'thumbnail' => 92]);
        $this->eavValue->expects(self::once())->method('findValuesInUse')
            ->with('varchar', [90, 91, 92], ['/a/a/one.jpg'])
            ->willReturn([]);

        self::assertSame(['/a/a/one.jpg'], $this->checker()->getUnreferenced(['/a/a/one.jpg']));
    }

    public function testEmptyAndDuplicatePathsAreNeverQueried(): void
    {
        $this->gallery->expects(self::once())->method('findReferencedFiles')
            ->with(['/a/a/one.jpg'])
            ->willReturn([]);
        $this->stubRoles(['image' => 90, 'small_image' => 91, 'thumbnail' => 92, 'swatch_image' => 93]);
        $this->eavValue->method('findValuesInUse')->willReturn([]);

        self::assertSame(
            ['/a/a/one.jpg'],
            $this->checker()->getUnreferenced(['/a/a/one.jpg', '', '/a/a/one.jpg'])
        );
    }

    /**
     * Paths are deduplicated by being used as array keys, and PHP casts a
     * numeric-looking key to int on the way in. Nothing in a stored path is ever
     * numeric, but the contract says string[] and a third-party caller is not
     * bound by the event's shape.
     */
    public function testANumericLookingPathComesBackAsAString(): void
    {
        $this->gallery->method('findReferencedFiles')->willReturn([]);
        $this->stubRoles(['image' => 90, 'small_image' => 91, 'thumbnail' => 92, 'swatch_image' => 93]);
        $this->eavValue->method('findValuesInUse')->willReturn([]);

        self::assertSame(['123'], $this->checker()->getUnreferenced(['123']));
    }

    public function testNothingIsQueriedForAnEmptySet(): void
    {
        $this->gallery->expects(self::never())->method('findReferencedFiles');
        $this->eavValue->expects(self::never())->method('findValuesInUse');

        self::assertSame([], $this->checker()->getUnreferenced([]));
        self::assertSame([], $this->checker()->getUnreferenced(['']));
    }

    public function testIsReferencedInvertsTheBatchAnswer(): void
    {
        $this->gallery->method('findReferencedFiles')->willReturn(['/a/a/one.jpg']);

        self::assertTrue($this->checker()->isReferenced('/a/a/one.jpg'));
    }

    public function testIsReferencedIsFalseForAnUnreferencedFile(): void
    {
        $this->gallery->method('findReferencedFiles')->willReturn([]);
        $this->stubRoles(['image' => 90, 'small_image' => 91, 'thumbnail' => 92, 'swatch_image' => 93]);
        $this->eavValue->method('findValuesInUse')->willReturn([]);

        self::assertFalse($this->checker()->isReferenced('/a/a/one.jpg'));
    }

    /**
     * An empty path is not a file, and must not be answered by a query whose
     * IN () list would be empty.
     */
    public function testIsReferencedIsFalseForAnEmptyPathWithoutQuerying(): void
    {
        $this->gallery->expects(self::never())->method('findReferencedFiles');

        self::assertFalse($this->checker()->isReferenced(''));
    }

    /**
     * With no role list configured the check degrades to the gallery pass alone —
     * narrower, but never wrong in the other direction.
     */
    public function testAnEmptyRoleListLeavesTheGalleryPassAsTheWholeCheck(): void
    {
        $this->gallery->method('findReferencedFiles')->willReturn([]);
        $this->attributeMetadataCache->expects(self::never())->method('warm');
        $this->eavValue->expects(self::never())->method('findValuesInUse');

        $checker = new MediaReferenceChecker(
            $this->gallery,
            $this->eavValue,
            $this->attributeMetadataCache,
            []
        );

        self::assertSame(['/a/a/one.jpg'], $checker->getUnreferenced(['/a/a/one.jpg']));
    }

    private function checker(): MediaReferenceChecker
    {
        return new MediaReferenceChecker(
            $this->gallery,
            $this->eavValue,
            $this->attributeMetadataCache,
            self::ROLES
        );
    }

    /**
     * @param array<string, int> $idsByCode codes absent from this map do not exist
     * @param array<string, string> $backendTypesByCode defaults to varchar
     */
    private function stubRoles(array $idsByCode, array $backendTypesByCode = []): void
    {
        $this->attributeMetadataCache->method('get')->willReturnCallback(
            static function (string $code) use ($idsByCode, $backendTypesByCode): ?array {
                if (!isset($idsByCode[$code])) {
                    return null;
                }

                return [
                    'attribute_id' => $idsByCode[$code],
                    'attribute_code' => $code,
                    'backend_type' => $backendTypesByCode[$code] ?? 'varchar',
                    'frontend_input' => 'media_image',
                    'frontend_label' => $code,
                    'is_global' => 0,
                    'is_required' => 0,
                    'apply_to' => '',
                ];
            }
        );
    }
}
