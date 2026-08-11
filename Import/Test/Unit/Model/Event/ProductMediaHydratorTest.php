<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Test\Unit\Model\Event;

use Magento\Catalog\Model\Product;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReadyData\Import\Logger\Logger;
use ReadyData\Import\Model\Cache\AttributeMetadataCache;
use ReadyData\Import\Model\Event\ProductMediaHydrator;
use ReadyData\Import\Model\ResourceModel\EavValue;
use ReadyData\Import\Model\ResourceModel\ProductEntity;
use ReadyData\Import\Model\ResourceModel\ProductMediaGallery;

class ProductMediaHydratorTest extends TestCase
{
    private const GALLERY_ID = 90;

    private const ATTRIBUTE_IDS = [
        'media_gallery' => self::GALLERY_ID,
        'image' => 91,
        'small_image' => 92,
        'thumbnail' => 93,
        'swatch_image' => 94,
        'image_label' => 95,
        'small_image_label' => 96,
        'thumbnail_label' => 97,
    ];

    private ProductMediaGallery&MockObject $productMediaGallery;
    private EavValue&MockObject $eavValue;
    private ProductEntity&MockObject $productEntity;
    private AttributeMetadataCache&MockObject $attributeMetadataCache;
    private Logger&MockObject $logger;

    /**
     * @var string[] attribute codes this store is pretending not to have
     */
    private array $missingAttributes = [];

    protected function setUp(): void
    {
        $this->productMediaGallery = $this->createMock(ProductMediaGallery::class);
        $this->eavValue = $this->createMock(EavValue::class);
        $this->productEntity = $this->createMock(ProductEntity::class);
        $this->productEntity->method('getLinkField')->willReturn('entity_id');
        $this->logger = $this->createMock(Logger::class);

        $this->attributeMetadataCache = $this->createMock(AttributeMetadataCache::class);
        $this->attributeMetadataCache->method('get')->willReturnCallback(
            fn (string $code): ?array => in_array($code, $this->missingAttributes, true)
                || !isset(self::ATTRIBUTE_IDS[$code])
                    ? null
                    : ['attribute_id' => self::ATTRIBUTE_IDS[$code], 'attribute_code' => $code]
        );
    }

    public function testGalleryShapeMirrorsCore(): void
    {
        $this->stubGallery([10 => [
            $this->galleryRow(501, '/f/r/front.jpg', ['label' => 'Front', 'position' => 1]),
        ]]);
        $product = $this->hydrateOne(10);

        $gallery = $product->getData('media_gallery');
        self::assertSame(['images', 'values'], array_keys($gallery));
        self::assertSame([], $gallery['values']);
        // assertSame pins the key order too, which is what a consumer indexing
        // by position in the row would depend on.
        self::assertSame([
            501 => [
                'value_id' => 501,
                'file' => '/f/r/front.jpg',
                'media_type' => 'image',
                'entity_id' => 10,
                'label' => 'Front',
                'position' => 1,
                'disabled' => 0,
                'label_default' => 'Front',
                'position_default' => 1,
                'disabled_default' => 0,
            ],
        ], $gallery['images']);
    }

    public function testGalleryDisabledRowsAreDropped(): void
    {
        // Core's select carries a hard "gallery.disabled = 0".
        $this->stubGallery([10 => [
            $this->galleryRow(501, '/a/a/a.jpg'),
            $this->galleryRow(502, '/b/b/b.jpg', ['gallery_disabled' => 1]),
        ]]);

        self::assertSame([501], array_keys($this->hydrateOne(10)->getData('media_gallery')['images']));
    }

    public function testLegacyNullPathRowsAreDropped(): void
    {
        $this->stubGallery([10 => [
            $this->galleryRow(501, ''),
            $this->galleryRow(502, '/b/b/b.jpg'),
        ]]);

        self::assertSame([502], array_keys($this->hydrateOne(10)->getData('media_gallery')['images']));
    }

    public function testPositionSortPutsNullsLast(): void
    {
        $this->stubGallery([10 => [
            $this->galleryRow(501, '/a/a/a.jpg', ['position' => null]),
            $this->galleryRow(502, '/b/b/b.jpg', ['position' => 5]),
            $this->galleryRow(503, '/c/c/c.jpg', ['position' => 1]),
        ]]);

        self::assertSame([503, 502, 501], array_keys($this->hydrateOne(10)->getData('media_gallery')['images']));
    }

    public function testProductWithoutGalleryRowsStillGetsAnEmptyImagesArray(): void
    {
        // Not left absent and not null: an empty array is what makes
        // getMediaGalleryEntries() return [] rather than blowing up.
        $this->stubGallery([]);

        self::assertSame(['images' => [], 'values' => []], $this->hydrateOne(10)->getData('media_gallery'));
    }

    public function testVideoKeysAreSetOnlyForVideoRowsCarryingARecord(): void
    {
        $this->stubGallery([10 => [
            $this->galleryRow(501, '/v/i/vid.jpg', [
                'media_type' => 'external-video',
                'position' => 1,
                'video' => [
                    'provider' => 'youtube',
                    'url' => 'https://youtu.be/abc',
                    'title' => 'Clip',
                    'description' => null,
                    'metadata' => null,
                ],
            ]),
            // external-video with no _value_video record: a left join miss, so
            // core would carry no video keys either.
            $this->galleryRow(502, '/v/i/orphan.jpg', ['media_type' => 'external-video', 'position' => 2]),
        ]]);

        $images = $this->hydrateOne(10)->getData('media_gallery')['images'];
        self::assertSame([
            'video_provider' => 'youtube',
            'video_url' => 'https://youtu.be/abc',
            'video_title' => 'Clip',
            'video_description' => null,
            'video_metadata' => null,
        ], array_intersect_key($images[501], array_flip([
            'video_provider',
            'video_url',
            'video_title',
            'video_description',
            'video_metadata',
        ])));
        self::assertSame('external-video', $images[502]['media_type']);
        self::assertArrayNotHasKey('video_provider', $images[502]);
    }

    public function testRowsCarryTheLinkFieldOnEnterprise(): void
    {
        $productEntity = $this->createMock(ProductEntity::class);
        $productEntity->method('getLinkField')->willReturn('row_id');
        $this->stubGallery([77 => [$this->galleryRow(501, '/a/a/a.jpg')]]);

        $product = $this->productObject();
        $this->hydrator($productEntity)->hydrate([77 => $product], 1);

        $row = $product->getData('media_gallery')['images'][501];
        self::assertSame(77, $row['row_id']);
        self::assertArrayNotHasKey('entity_id', $row);
    }

    public function testStoreScopedRoleBeatsTheDefaultOne(): void
    {
        $this->stubGallery([]);
        $this->stubRoles([10 => [
            self::ATTRIBUTE_IDS['image'] => [0 => '/d/e/default.jpg', 3 => '/s/t/store.jpg'],
        ]]);

        self::assertSame('/s/t/store.jpg', $this->hydrateOne(10, storeId: 3)->getData('image'));
    }

    public function testRoleFallsBackToTheDefaultScope(): void
    {
        $this->stubGallery([]);
        $this->stubRoles([10 => [
            self::ATTRIBUTE_IDS['image'] => [0 => '/d/e/default.jpg', 3 => '/s/t/store.jpg'],
        ]]);

        self::assertSame('/d/e/default.jpg', $this->hydrateOne(10, storeId: 1)->getData('image'));
    }

    public function testRoleWithoutAStoredRowKeepsThePayloadValue(): void
    {
        // The dispatcher has already put the custom_attributes values on the
        // object; dropping one because the DB has no row would lose data.
        $this->stubGallery([]);
        $this->stubRoles([]);

        $product = $this->productObject();
        $product->setData('image', '/p/a/payload.jpg');
        $this->hydrator()->hydrate([10 => $product], 1);

        self::assertSame('/p/a/payload.jpg', $product->getData('image'));
    }

    public function testRolesAndLabelsAreReadTogetherInOneCall(): void
    {
        $this->stubGallery([]);
        $this->eavValue->expects(self::once())
            ->method('getValuesForStores')
            ->with(
                'varchar',
                array_values(array_diff(self::ATTRIBUTE_IDS, [self::GALLERY_ID])),
                [10]
            )
            ->willReturn([10 => [
                self::ATTRIBUTE_IDS['image'] => [0 => '/a/a/a.jpg'],
                self::ATTRIBUTE_IDS['image_label'] => [0 => 'Front'],
            ]]);

        $product = $this->hydrateOne(10);
        self::assertSame('/a/a/a.jpg', $product->getData('image'));
        self::assertSame('Front', $product->getData('image_label'));
    }

    public function testMissingRoleAttributeIsLeftOutOfTheRead(): void
    {
        $this->missingAttributes = ['swatch_image'];
        $this->stubGallery([]);
        $captured = null;
        $this->eavValue->method('getValuesForStores')->willReturnCallback(
            function (string $type, array $attributeIds) use (&$captured): array {
                $captured = $attributeIds;

                return [];
            }
        );

        $product = $this->hydrateOne(10);

        self::assertNotContains(self::ATTRIBUTE_IDS['swatch_image'], $captured);
        self::assertFalse($product->hasData('swatch_image'));
    }

    public function testGalleryAttributeIsLockedAgainstObserverWrites(): void
    {
        // Locking is what stops Gallery\CreateHandler from writing store-scoped
        // gallery_value rows if an observer saves the product.
        $this->stubGallery([10 => [$this->galleryRow(501, '/a/a/a.jpg')]]);
        $product = $this->hydrateOne(10);

        self::assertTrue($product->isLockedAttribute('media_gallery'));

        $product->setData('media_gallery', ['images' => [], 'values' => []]);
        self::assertSame([501], array_keys($product->getData('media_gallery')['images']));
    }

    public function testMissingGalleryAttributeSkipsTheReadAndLogs(): void
    {
        $this->missingAttributes = ['media_gallery'];
        $this->productMediaGallery->expects(self::never())->method('getGallery');
        $this->eavValue->expects(self::never())->method('getValuesForStores');
        $this->logger->expects(self::once())->method('error');

        $product = $this->productObject();
        $this->hydrator()->hydrate([10 => $product], 1);

        self::assertFalse($product->hasData('media_gallery'));
    }

    public function testAResourceFailureIsLoggedAndSwallowed(): void
    {
        // The dispatcher calls this after the batch committed, from inside
        // ImportService's try block — a throw would roll back a committed
        // transaction and fail products that are persisted.
        $this->productMediaGallery->method('getGallery')->willThrowException(new \RuntimeException('deadlock'));
        $this->logger->expects(self::once())->method('error');

        $this->hydrator()->hydrate([10 => $this->productObject()], 1);
    }

    public function testNoProductsIssuesNoQueries(): void
    {
        $this->attributeMetadataCache->expects(self::never())->method('warm');
        $this->productMediaGallery->expects(self::never())->method('getGallery');
        $this->eavValue->expects(self::never())->method('getValuesForStores');

        $this->hydrator()->hydrate([], 1);
    }

    /**
     * @param array<int, array<int, array<string, mixed>>> $gallery
     */
    private function stubGallery(array $gallery): void
    {
        $this->productMediaGallery->method('getGallery')->willReturn($gallery);
    }

    /**
     * @param array<int, array<int, array<int, string>>> $values
     */
    private function stubRoles(array $values): void
    {
        $this->eavValue->method('getValuesForStores')->willReturn($values);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed> one row in ProductMediaGallery::getGallery() shape
     */
    private function galleryRow(int $valueId, string $file, array $overrides = []): array
    {
        return array_merge([
            'value_id' => $valueId,
            'file' => $file,
            'media_type' => 'image',
            'gallery_disabled' => 0,
            'label' => null,
            'position' => 1,
            'value_disabled' => 0,
            'has_value_row' => true,
            'video' => null,
        ], $overrides);
    }

    private function hydrateOne(int $linkId, int $storeId = 1): Product
    {
        $product = $this->productObject();
        $this->hydrator()->hydrate([$linkId => $product], $storeId);

        return $product;
    }

    private function productObject(): Product
    {
        return (new \ReflectionClass(Product::class))->newInstanceWithoutConstructor();
    }

    private function hydrator(?ProductEntity $productEntity = null): ProductMediaHydrator
    {
        return new ProductMediaHydrator(
            $this->productMediaGallery,
            $this->eavValue,
            $productEntity ?? $this->productEntity,
            $this->attributeMetadataCache,
            $this->logger
        );
    }
}
