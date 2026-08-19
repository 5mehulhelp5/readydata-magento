<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Test\Unit\Model\Processor;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReadyData\Import\Api\Data\MediaEntryInterface;
use ReadyData\Import\Logger\Logger;
use ReadyData\Import\Model\BatchContext;
use ReadyData\Import\Model\Cache\AttributeMetadataCache;
use ReadyData\Import\Model\Config;
use ReadyData\Import\Model\Data\MediaEntry;
use ReadyData\Import\Model\Data\Product;
use ReadyData\Import\Model\ImportLocks;
use ReadyData\Import\Model\Media\Cleanup\MediaCleanupService;
use ReadyData\Import\Model\Media\FileResolver;
use ReadyData\Import\Model\Processor\EntityProcessor;
use ReadyData\Import\Model\Processor\MediaProcessor;
use ReadyData\Import\Model\ResourceModel\EavValue;
use ReadyData\Import\Model\ResourceModel\ProductEntity;
use ReadyData\Import\Model\ResourceModel\ProductMediaGallery;

class MediaProcessorTest extends TestCase
{
    private const GALLERY_ATTRIBUTE_ID = 90;
    private const ROLE_IDS = [
        'image' => 91,
        'small_image' => 92,
        'thumbnail' => 93,
        'swatch_image' => 94,
    ];

    private const FILE_A = '/a/a/one.jpg';
    private const FILE_B = '/b/b/two.jpg';

    private ProductMediaGallery&MockObject $gallery;
    private ProductEntity&MockObject $productEntity;
    private AttributeMetadataCache&MockObject $attributeCache;
    private EavValue&MockObject $eavValue;
    private FileResolver&MockObject $fileResolver;
    private Config&MockObject $config;
    private Logger&MockObject $logger;
    private MediaCleanupService&MockObject $mediaCleanup;
    private MediaProcessor $processor;

    protected function setUp(): void
    {
        $this->gallery = $this->createMock(ProductMediaGallery::class);
        $this->productEntity = $this->createMock(ProductEntity::class);
        $this->attributeCache = $this->createMock(AttributeMetadataCache::class);
        $this->eavValue = $this->createMock(EavValue::class);
        $this->fileResolver = $this->createMock(FileResolver::class);
        $this->config = $this->createMock(Config::class);
        $this->logger = $this->createMock(Logger::class);

        $this->productEntity->method('getLinkField')->willReturn('entity_id');
        $this->gallery->method('hasVideoTable')->willReturn(true);
        $this->attributeCache->method('get')->willReturnCallback(
            fn (string $code): ?array => $this->metaFor($code)
        );
        $this->config->method('isMediaAutoAssignRoles')->willReturn(false);
        // Cleanup is opt-in and off by default; the hooks that consult it have
        // their own tests.
        $this->mediaCleanup = $this->createMock(MediaCleanupService::class);

        $this->processor = new MediaProcessor(
            $this->gallery,
            $this->productEntity,
            $this->attributeCache,
            $this->eavValue,
            $this->fileResolver,
            $this->config,
            $this->mediaCleanup,
            $this->logger
        );
    }

    /**
     * The gallery predicate is deliberately the CONSERVATIVE one, unlike the
     * other three: answering "will a row be inserted" needs the
     * desired-versus-existing diff process() performs, per product, against link
     * IDs that do not exist yet when the locks are decided. On the one lock
     * where being wrong lists an image twice, presence of the field is the
     * affordable answer — measured at 251 ms of hold, the cheapest of the four.
     */
    public function testAMediaFieldTakesTheGalleryLock(): void
    {
        $context = $this->contextFor('P1', [$this->entry(self::FILE_A)], 10);

        self::assertSame([ImportLocks::MEDIA_GALLERY], $this->processor->requiredLocks($context));
    }

    /**
     * `[]` counts. It means "remove everything", and while a delete cannot
     * duplicate a row, the delete-then-insert of the `_value` rows in the same
     * pass can.
     */
    public function testAnEmptyMediaArrayStillTakesTheLock(): void
    {
        $context = $this->contextFor('P1', [], 10);

        self::assertSame([ImportLocks::MEDIA_GALLERY], $this->processor->requiredLocks($context));
    }

    public function testAProductWithoutAMediaFieldTakesNoLock(): void
    {
        $context = new BatchContext([(new Product())->setSku('P1')]);

        self::assertSame([], $this->processor->requiredLocks($context));
    }

    public function testCreatesGalleryWithLabelsPositionsAndRoles(): void
    {
        $context = $this->contextFor('P1', [
            $this->entry(self::FILE_A, ['label' => 'Front', 'roles' => ['image', 'small_image']]),
            $this->entry(self::FILE_B, ['label' => 'Back']),
        ], 10);

        $this->gallery->method('getGallery')->willReturn([]);
        $this->gallery->expects(self::once())->method('insertGalleryRows')->with([
            "P1\0" . self::FILE_A => $this->insertRow(self::FILE_A),
            "P1\0" . self::FILE_B => $this->insertRow(self::FILE_B),
        ])->willReturn([
            "P1\0" . self::FILE_A => 501,
            "P1\0" . self::FILE_B => 502,
        ]);
        $this->gallery->expects(self::once())->method('bindToEntities')->with([
            ['value_id' => 501, 'link_id' => 10],
            ['value_id' => 502, 'link_id' => 10],
        ]);
        $this->gallery->expects(self::once())->method('saveValues')->with([
            $this->valueRow(501, 10, 'Front', 0),
            $this->valueRow(502, 10, 'Back', 1),
        ]);
        $this->gallery->expects(self::once())->method('removeEntries')->with([]);
        $this->gallery->expects(self::once())->method('saveVideos')->with([]);
        $this->gallery->expects(self::once())->method('deleteVideos')->with([]);
        $this->gallery->expects(self::once())->method('updateGalleryRows')->with([]);

        $this->eavValue->method('getValuesForStores')->willReturn([]);
        $this->eavValue->expects(self::once())->method('upsert')->with('varchar', [
            $this->roleRow(10, 'image', self::FILE_A),
            $this->roleRow(10, 'small_image', self::FILE_A),
        ]);
        $this->eavValue->expects(self::once())->method('delete')->with('varchar', []);

        $this->processor->process($context);

        self::assertSame([], $context->getMessages('P1'));
    }

    public function testExplicitPositionOverridesPayloadOrder(): void
    {
        $context = $this->contextFor('P1', [
            $this->entry(self::FILE_A, ['position' => 7]),
            $this->entry(self::FILE_B),
        ], 10);

        $this->gallery->method('getGallery')->willReturn([]);
        $this->gallery->method('insertGalleryRows')->willReturn([
            "P1\0" . self::FILE_A => 501,
            "P1\0" . self::FILE_B => 502,
        ]);
        $this->eavValue->method('getValuesForStores')->willReturn([]);

        $this->gallery->expects(self::once())->method('saveValues')->with([
            $this->valueRow(501, 10, null, 7),
            // The ordinal counts entries, not positions: the second entry is the
            // second resolved one whatever the first declared.
            $this->valueRow(502, 10, null, 1),
        ]);

        $this->processor->process($context);
    }

    public function testNegativePositionIsClampedToZero(): void
    {
        $context = $this->contextFor('P1', [$this->entry(self::FILE_A, ['position' => -5])], 10);

        $this->gallery->method('getGallery')->willReturn([]);
        $this->gallery->method('insertGalleryRows')->willReturn(["P1\0" . self::FILE_A => 501]);
        $this->eavValue->method('getValuesForStores')->willReturn([]);

        // The column is unsigned; a negative value would abort the whole batch.
        $this->gallery->expects(self::once())->method('saveValues')
            ->with([$this->valueRow(501, 10, null, 0)]);

        $this->processor->process($context);
    }

    public function testPositionsAreGapFreeOverResolvedEntriesOnly(): void
    {
        $context = $this->contextFor('P1', [
            $this->entry(self::FILE_A),
            $this->entry('missing.jpg'),
            $this->entry(self::FILE_B),
        ], 10);

        $this->gallery->method('getGallery')->willReturn([]);
        $this->gallery->method('insertGalleryRows')->willReturn([
            "P1\0" . self::FILE_A => 501,
            "P1\0" . self::FILE_B => 502,
        ]);
        $this->eavValue->method('getValuesForStores')->willReturn([]);

        $this->gallery->expects(self::once())->method('saveValues')->with([
            $this->valueRow(501, 10, null, 0),
            $this->valueRow(502, 10, null, 1),
        ]);

        $this->processor->process($context);
    }

    public function testReplaceRemovesEntriesNotInPayload(): void
    {
        $context = $this->contextFor('P1', [$this->entry(self::FILE_A)], 10);

        $this->gallery->method('getGallery')->willReturn([
            10 => [
                $this->galleryRow(501, self::FILE_A, ['position' => 0]),
                $this->galleryRow(502, self::FILE_B, ['position' => 1]),
            ],
        ]);
        $this->eavValue->method('getValuesForStores')->willReturn([]);

        $this->gallery->expects(self::once())->method('removeEntries')
            ->with([['value_id' => 502, 'link_id' => 10]]);
        $this->gallery->expects(self::once())->method('insertGalleryRows')->with([])->willReturn([]);

        $this->processor->process($context);

        self::assertSame([], $context->getMessages('P1'));
    }

    public function testEmptyArrayRemovesEveryEntry(): void
    {
        $context = $this->contextFor('P1', [], 10);

        $this->gallery->method('getGallery')->willReturn([
            10 => [
                $this->galleryRow(501, self::FILE_A, ['position' => 0]),
                $this->galleryRow(502, self::FILE_B, ['position' => 1]),
            ],
        ]);
        $this->eavValue->method('getValuesForStores')->willReturn([]);

        $this->gallery->expects(self::once())->method('removeEntries')->with([
            ['value_id' => 501, 'link_id' => 10],
            ['value_id' => 502, 'link_id' => 10],
        ]);
        $this->gallery->expects(self::once())->method('insertGalleryRows')->with([])->willReturn([]);
        $this->gallery->expects(self::once())->method('saveValues')->with([]);

        $this->processor->process($context);
    }

    public function testOmittedMediaTouchesNothing(): void
    {
        $product = (new Product())->setSku('P1');
        $context = new BatchContext([$product]);
        $context->setEntityId('P1', 10);
        $context->set(EntityProcessor::CONTEXT_LINK_IDS, ['P1' => 10]);

        $this->expectNoGalleryWork();

        $this->processor->process($context);
    }

    public function testUnchangedGalleryPerformsNoWrites(): void
    {
        $context = $this->contextFor('P1', [
            $this->entry(self::FILE_A, ['label' => 'Front']),
            $this->entry(self::FILE_B, ['label' => 'Back', 'disabled' => true]),
        ], 10);

        $this->gallery->method('getGallery')->willReturn([
            10 => [
                $this->galleryRow(501, self::FILE_A, ['label' => 'Front', 'position' => 0]),
                $this->galleryRow(502, self::FILE_B, [
                    'label' => 'Back',
                    'position' => 1,
                    'value_disabled' => 1,
                ]),
            ],
        ]);
        $this->eavValue->method('getValuesForStores')->willReturn([]);

        $this->gallery->expects(self::once())->method('removeEntries')->with([]);
        $this->gallery->expects(self::once())->method('insertGalleryRows')->with([])->willReturn([]);
        $this->gallery->expects(self::once())->method('bindToEntities')->with([]);
        $this->gallery->expects(self::once())->method('saveValues')->with([]);
        $this->gallery->expects(self::once())->method('saveVideos')->with([]);
        $this->gallery->expects(self::once())->method('deleteVideos')->with([]);
        $this->gallery->expects(self::once())->method('updateGalleryRows')->with([]);
        $this->eavValue->expects(self::once())->method('upsert')->with('varchar', []);
        $this->eavValue->expects(self::once())->method('delete')->with('varchar', []);

        $this->processor->process($context);

        self::assertSame([], $context->getMessages('P1'));
    }

    public function testChangedLabelWritesOnlyTheValueRow(): void
    {
        $context = $this->contextFor('P1', [$this->entry(self::FILE_A, ['label' => 'New'])], 10);

        $this->gallery->method('getGallery')->willReturn([
            10 => [$this->galleryRow(501, self::FILE_A, ['label' => 'Old', 'position' => 0])],
        ]);
        $this->eavValue->method('getValuesForStores')->willReturn([]);

        $this->gallery->expects(self::once())->method('insertGalleryRows')->with([])->willReturn([]);
        $this->gallery->expects(self::once())->method('saveValues')
            ->with([$this->valueRow(501, 10, 'New', 0)]);
        $this->gallery->expects(self::once())->method('updateGalleryRows')->with([]);

        $this->processor->process($context);
    }

    public function testDisabledEntryWritesValueRowAndNormalisesTheGalleryRow(): void
    {
        $context = $this->contextFor('P1', [$this->entry(self::FILE_A, ['disabled' => true])], 10);

        $this->gallery->method('getGallery')->willReturn([
            10 => [$this->galleryRow(501, self::FILE_A, [
                'position' => 0,
                // A legacy row hidden through the main table, which no admin UI
                // can undo — the import normalises it back to 0.
                'gallery_disabled' => 1,
            ])],
        ]);
        $this->eavValue->method('getValuesForStores')->willReturn([]);

        $this->gallery->expects(self::once())->method('saveValues')
            ->with([$this->valueRow(501, 10, null, 0, 1)]);
        $this->gallery->expects(self::once())->method('updateGalleryRows')
            ->with(['image|0' => [501]]);

        $this->processor->process($context);
    }

    public function testMissingValueRowIsWrittenEvenWhenNothingElseChanged(): void
    {
        $context = $this->contextFor('P1', [$this->entry(self::FILE_A)], 10);

        $this->gallery->method('getGallery')->willReturn([
            10 => [$this->galleryRow(501, self::FILE_A, [
                'label' => null,
                'position' => null,
                'has_value_row' => false,
            ])],
        ]);
        $this->eavValue->method('getValuesForStores')->willReturn([]);

        $this->gallery->expects(self::once())->method('saveValues')
            ->with([$this->valueRow(501, 10, null, 0)]);

        $this->processor->process($context);
    }

    public function testUnresolvedFileWithholdsRemovalsAndStillInsertsResolvedOnes(): void
    {
        $context = $this->contextFor('P1', [
            $this->entry(self::FILE_A),
            $this->entry('missing.jpg'),
        ], 10);

        $this->gallery->method('getGallery')->willReturn([
            10 => [$this->galleryRow(502, self::FILE_B, ['position' => 0])],
        ]);
        $this->eavValue->method('getValuesForStores')->willReturn([]);

        // Additive: the insert happens, the stale entry survives.
        $this->gallery->expects(self::once())->method('removeEntries')->with([]);
        $this->gallery->expects(self::once())->method('insertGalleryRows')
            ->with(["P1\0" . self::FILE_A => $this->insertRow(self::FILE_A)])
            ->willReturn(["P1\0" . self::FILE_A => 501]);

        $this->processor->process($context);

        $messages = $context->getMessages('P1');
        self::assertCount(2, $messages);
        self::assertStringContainsString('was not found', $messages[0]);
        self::assertStringContainsString('applied additively', $messages[1]);
        self::assertFalse($context->isFailed('P1'));
    }

    public function testEmptyFileIsSkippedAndWithholdsRemovals(): void
    {
        $context = $this->contextFor('P1', [$this->entry('  ')], 10);

        $this->gallery->method('getGallery')->willReturn([
            10 => [$this->galleryRow(502, self::FILE_B, ['position' => 0])],
        ]);
        $this->eavValue->method('getValuesForStores')->willReturn([]);
        $this->gallery->expects(self::once())->method('removeEntries')->with([]);

        $this->processor->process($context);

        self::assertSame(
            ['Media entry with an empty file skipped.', $this->additiveMessage()],
            $context->getMessages('P1')
        );
    }

    public function testDuplicateFileKeepsTheFirstAndStillRemoves(): void
    {
        $context = $this->contextFor('P1', [
            $this->entry(self::FILE_A, ['label' => 'First']),
            $this->entry(self::FILE_A, ['label' => 'Second']),
        ], 10);

        $this->gallery->method('getGallery')->willReturn([
            10 => [$this->galleryRow(502, self::FILE_B, ['position' => 0])],
        ]);
        $this->eavValue->method('getValuesForStores')->willReturn([]);

        $this->gallery->expects(self::once())->method('insertGalleryRows')
            ->with(["P1\0" . self::FILE_A => $this->insertRow(self::FILE_A)])
            ->willReturn(["P1\0" . self::FILE_A => 501]);
        $this->gallery->expects(self::once())->method('saveValues')
            ->with([$this->valueRow(501, 10, 'First', 0)]);
        // A feed that always echoes a file twice must not be frozen out of
        // removals forever, so the duplicate does NOT trip the valve.
        $this->gallery->expects(self::once())->method('removeEntries')
            ->with([['value_id' => 502, 'link_id' => 10]]);

        $this->processor->process($context);

        self::assertSame(
            [sprintf('Duplicate media file "%s" skipped.', self::FILE_A)],
            $context->getMessages('P1')
        );
    }

    public function testLegacyDuplicateAndNullPathRowsAreRemoved(): void
    {
        $context = $this->contextFor('P1', [$this->entry(self::FILE_A)], 10);

        $this->gallery->method('getGallery')->willReturn([
            10 => [
                $this->galleryRow(501, self::FILE_A, ['position' => 0]),
                // Core's own gallery read destroys same-path duplicates, so the
                // import must not leave them behind either.
                $this->galleryRow(502, self::FILE_A, ['position' => 0]),
                $this->galleryRow(503, '', ['position' => 0]),
            ],
        ]);
        $this->eavValue->method('getValuesForStores')->willReturn([]);

        $this->gallery->expects(self::once())->method('removeEntries')->with([
            ['value_id' => 502, 'link_id' => 10],
            ['value_id' => 503, 'link_id' => 10],
        ]);

        $this->processor->process($context);
    }

    public function testExternalVideoEntryWritesItsVideoRow(): void
    {
        $context = $this->contextFor('P1', [
            $this->entry(self::FILE_A, [
                'video_url' => 'https://www.youtube.com/watch?v=abc123',
                'video_title' => 'How it fits',
                'video_description' => 'Fit guide',
                'video_metadata' => '{}',
            ]),
        ], 10);

        $this->gallery->method('getGallery')->willReturn([]);
        $this->eavValue->method('getValuesForStores')->willReturn([]);

        $this->gallery->expects(self::once())->method('insertGalleryRows')
            ->with([
                "P1\0" . self::FILE_A => $this->insertRow(
                    self::FILE_A,
                    ProductMediaGallery::MEDIA_TYPE_EXTERNAL_VIDEO
                ),
            ])
            ->willReturn(["P1\0" . self::FILE_A => 501]);
        $this->gallery->expects(self::once())->method('saveVideos')->with([[
            'value_id' => 501,
            'provider' => 'youtube',
            'url' => 'https://www.youtube.com/watch?v=abc123',
            'title' => 'How it fits',
            'description' => 'Fit guide',
            'metadata' => '{}',
        ]]);

        $this->processor->process($context);

        self::assertSame([], $context->getMessages('P1'));
    }

    public function testVideoProviderIsDerivedFromTheVimeoHost(): void
    {
        $context = $this->contextFor('P1', [
            $this->entry(self::FILE_A, ['video_url' => 'https://player.vimeo.com/video/12345']),
        ], 10);

        $this->gallery->method('getGallery')->willReturn([]);
        $this->gallery->method('insertGalleryRows')->willReturn(["P1\0" . self::FILE_A => 501]);
        $this->eavValue->method('getValuesForStores')->willReturn([]);

        $this->gallery->expects(self::once())->method('saveVideos')
            ->with([self::callbackVideo('vimeo')]);

        $this->processor->process($context);
    }

    public function testExplicitVideoProviderWins(): void
    {
        $context = $this->contextFor('P1', [
            $this->entry(self::FILE_A, [
                'video_url' => 'https://media.example.com/clip.mp4',
                'video_provider' => 'Custom',
            ]),
        ], 10);

        $this->gallery->method('getGallery')->willReturn([]);
        $this->gallery->method('insertGalleryRows')->willReturn(["P1\0" . self::FILE_A => 501]);
        $this->eavValue->method('getValuesForStores')->willReturn([]);

        $this->gallery->expects(self::once())->method('saveVideos')
            ->with([self::callbackVideo('custom')]);

        $this->processor->process($context);

        self::assertSame([], $context->getMessages('P1'));
    }

    public function testUnknownVideoProviderSkipsTheEntryAndWithholdsRemovals(): void
    {
        $context = $this->contextFor('P1', [
            $this->entry(self::FILE_A, ['video_url' => 'https://videos.example.com/clip']),
        ], 10);

        $this->gallery->method('getGallery')->willReturn([
            10 => [$this->galleryRow(502, self::FILE_B, ['position' => 0])],
        ]);
        $this->eavValue->method('getValuesForStores')->willReturn([]);

        $this->gallery->expects(self::once())->method('insertGalleryRows')->with([])->willReturn([]);
        $this->gallery->expects(self::once())->method('removeEntries')->with([]);
        $this->gallery->expects(self::once())->method('saveVideos')->with([]);

        $this->processor->process($context);

        $messages = $context->getMessages('P1');
        self::assertStringContainsString('unrecognised provider', $messages[0]);
        self::assertSame($this->additiveMessage(), $messages[1]);
    }

    public function testVideoEntryWithoutUrlIsSkipped(): void
    {
        $context = $this->contextFor('P1', [
            $this->entry(self::FILE_A, ['media_type' => ProductMediaGallery::MEDIA_TYPE_EXTERNAL_VIDEO]),
        ], 10);

        $this->gallery->method('getGallery')->willReturn([]);
        $this->eavValue->method('getValuesForStores')->willReturn([]);
        $this->gallery->expects(self::once())->method('insertGalleryRows')->with([])->willReturn([]);

        $this->processor->process($context);

        self::assertStringContainsString('has no video URL', $context->getMessages('P1')[0]);
    }

    public function testUnknownMediaTypeFallsBackToImage(): void
    {
        $context = $this->contextFor('P1', [$this->entry(self::FILE_A, ['media_type' => 'hologram'])], 10);

        $this->gallery->method('getGallery')->willReturn([]);
        $this->eavValue->method('getValuesForStores')->willReturn([]);

        $this->gallery->expects(self::once())->method('insertGalleryRows')
            ->with(["P1\0" . self::FILE_A => $this->insertRow(self::FILE_A)])
            ->willReturn(["P1\0" . self::FILE_A => 501]);

        $this->processor->process($context);

        self::assertSame(
            ['Unknown media type "hologram" treated as an image.'],
            $context->getMessages('P1')
        );
    }

    public function testMissingVideoTableDegradesToImageWithoutWithholdingRemovals(): void
    {
        $gallery = $this->createMock(ProductMediaGallery::class);
        $gallery->method('hasVideoTable')->willReturn(false);
        $processor = $this->processorWith($gallery);

        $context = $this->contextFor('P1', [
            $this->entry(self::FILE_A, ['video_url' => 'https://www.youtube.com/watch?v=abc123']),
        ], 10);

        $gallery->method('getGallery')->willReturn([
            10 => [$this->galleryRow(502, self::FILE_B, ['position' => 0])],
        ]);
        $this->eavValue->method('getValuesForStores')->willReturn([]);

        $gallery->expects(self::once())->method('insertGalleryRows')
            ->with(["P1\0" . self::FILE_A => $this->insertRow(self::FILE_A)])
            ->willReturn(["P1\0" . self::FILE_A => 501]);
        $gallery->expects(self::once())->method('saveVideos')->with([]);
        // The preview file is a perfectly good image, so the valve stays open.
        $gallery->expects(self::once())->method('removeEntries')
            ->with([['value_id' => 502, 'link_id' => 10]]);

        $processor->process($context);

        self::assertStringContainsString(
            'Magento_ProductVideo is not installed',
            $context->getMessages('P1')[0]
        );
    }

    public function testEntryThatStoppedBeingAVideoDropsItsVideoRow(): void
    {
        $context = $this->contextFor('P1', [$this->entry(self::FILE_A)], 10);

        $this->gallery->method('getGallery')->willReturn([
            10 => [$this->galleryRow(501, self::FILE_A, [
                'media_type' => ProductMediaGallery::MEDIA_TYPE_EXTERNAL_VIDEO,
                'position' => 0,
                'video' => [
                    'provider' => 'youtube',
                    'url' => 'https://youtu.be/abc',
                    'title' => null,
                    'description' => null,
                    'metadata' => null,
                ],
            ])],
        ]);
        $this->eavValue->method('getValuesForStores')->willReturn([]);

        $this->gallery->expects(self::once())->method('deleteVideos')->with([501]);
        $this->gallery->expects(self::once())->method('updateGalleryRows')
            ->with(['image|0' => [501]]);

        $this->processor->process($context);
    }

    public function testUnchangedVideoIsNotRewritten(): void
    {
        $context = $this->contextFor('P1', [
            $this->entry(self::FILE_A, [
                'video_url' => 'https://youtu.be/abc',
                'video_provider' => 'youtube',
            ]),
        ], 10);

        $this->gallery->method('getGallery')->willReturn([
            10 => [$this->galleryRow(501, self::FILE_A, [
                'media_type' => ProductMediaGallery::MEDIA_TYPE_EXTERNAL_VIDEO,
                'position' => 0,
                'video' => [
                    'provider' => 'youtube',
                    'url' => 'https://youtu.be/abc',
                    'title' => null,
                    'description' => null,
                    'metadata' => null,
                ],
            ])],
        ]);
        $this->eavValue->method('getValuesForStores')->willReturn([]);

        $this->gallery->expects(self::once())->method('saveVideos')->with([]);
        $this->gallery->expects(self::once())->method('deleteVideos')->with([]);
        $this->gallery->expects(self::once())->method('updateGalleryRows')->with([]);

        $this->processor->process($context);
    }

    public function testRoleClaimedTwiceKeepsTheFirstClaim(): void
    {
        $context = $this->contextFor('P1', [
            $this->entry(self::FILE_A, ['roles' => ['image']]),
            $this->entry(self::FILE_B, ['roles' => ['image']]),
        ], 10);

        $this->gallery->method('getGallery')->willReturn([]);
        $this->gallery->method('insertGalleryRows')->willReturn([
            "P1\0" . self::FILE_A => 501,
            "P1\0" . self::FILE_B => 502,
        ]);
        $this->eavValue->method('getValuesForStores')->willReturn([]);

        $this->eavValue->expects(self::once())->method('upsert')
            ->with('varchar', [$this->roleRow(10, 'image', self::FILE_A)]);

        $this->processor->process($context);

        self::assertSame(
            ['Media role "image" is claimed by more than one entry; the first occurrence wins.'],
            $context->getMessages('P1')
        );
    }

    public function testUnknownRoleIsSkippedWithoutWithholdingRemovals(): void
    {
        $context = $this->contextFor('P1', [$this->entry(self::FILE_A, ['roles' => ['hero_image']])], 10);

        $this->gallery->method('getGallery')->willReturn([
            10 => [$this->galleryRow(502, self::FILE_B, ['position' => 0])],
        ]);
        $this->gallery->method('insertGalleryRows')->willReturn(["P1\0" . self::FILE_A => 501]);
        $this->eavValue->method('getValuesForStores')->willReturn([]);

        $this->gallery->expects(self::once())->method('removeEntries')
            ->with([['value_id' => 502, 'link_id' => 10]]);
        $this->eavValue->expects(self::once())->method('upsert')->with('varchar', []);

        $this->processor->process($context);

        self::assertSame(['Unknown media role "hero_image" skipped.'], $context->getMessages('P1'));
    }

    public function testSwatchImageRoleWarnsWhenTheAttributeIsAbsent(): void
    {
        $attributeCache = $this->createMock(AttributeMetadataCache::class);
        $attributeCache->method('get')->willReturnCallback(
            fn (string $code): ?array => $code === 'swatch_image' ? null : $this->metaFor($code)
        );
        $processor = $this->processorWith(null, $attributeCache);

        $context = $this->contextFor('P1', [$this->entry(self::FILE_A, ['roles' => ['swatch_image']])], 10);

        $this->gallery->method('getGallery')->willReturn([]);
        $this->gallery->method('insertGalleryRows')->willReturn(["P1\0" . self::FILE_A => 501]);
        $this->eavValue->method('getValuesForStores')->willReturn([]);

        $this->eavValue->expects(self::once())->method('upsert')->with('varchar', []);

        $processor->process($context);

        self::assertSame(
            ['Media role "swatch_image" does not exist in this store; skipped.'],
            $context->getMessages('P1')
        );
    }

    public function testRolePointingAtARemovedFileIsClearedInEveryScope(): void
    {
        $context = $this->contextFor('P1', [$this->entry(self::FILE_A)], 10);

        $this->gallery->method('getGallery')->willReturn([
            10 => [
                $this->galleryRow(501, self::FILE_A, ['position' => 0]),
                $this->galleryRow(502, self::FILE_B, ['position' => 1]),
            ],
        ]);
        $this->gallery->method('insertGalleryRows')->willReturn([]);
        // "image" points at the file being removed, in the default scope and in a
        // store view; "thumbnail" points at a file that stays.
        $this->eavValue->method('getValuesForStores')->willReturn([
            10 => [
                self::ROLE_IDS['image'] => [0 => self::FILE_B, 3 => self::FILE_B],
                self::ROLE_IDS['thumbnail'] => [0 => self::FILE_A],
            ],
        ]);

        $this->eavValue->expects(self::once())->method('upsert')->with('varchar', []);
        $this->eavValue->expects(self::once())->method('delete')->with('varchar', [
            ['link_id' => 10, 'attribute_id' => self::ROLE_IDS['image'], 'store_id' => 0],
            ['link_id' => 10, 'attribute_id' => self::ROLE_IDS['image'], 'store_id' => 3],
        ]);

        $this->processor->process($context);
    }

    public function testExistingStoreScopedRoleRowIsKeptInSyncButNoneIsCreated(): void
    {
        $context = $this->contextFor('P1', [$this->entry(self::FILE_A, ['roles' => ['image', 'thumbnail']])], 10);

        $this->gallery->method('getGallery')->willReturn([
            10 => [$this->galleryRow(501, self::FILE_A, ['position' => 0])],
        ]);
        $this->gallery->method('insertGalleryRows')->willReturn([]);
        $this->eavValue->method('getValuesForStores')->willReturn([
            10 => [self::ROLE_IDS['image'] => [0 => self::FILE_B, 3 => self::FILE_B]],
        ]);

        $this->eavValue->expects(self::once())->method('upsert')->with('varchar', [
            $this->roleRow(10, 'image', self::FILE_A, 0),
            // The store view already overrode this role, so it is followed…
            $this->roleRow(10, 'image', self::FILE_A, 3),
            // …while "thumbnail" gets a default-scope row only.
            $this->roleRow(10, 'thumbnail', self::FILE_A, 0),
        ]);

        $this->processor->process($context);
    }

    public function testRoleAlreadyPointingAtTheFileIsNotRewritten(): void
    {
        $context = $this->contextFor('P1', [$this->entry(self::FILE_A, ['roles' => ['image']])], 10);

        $this->gallery->method('getGallery')->willReturn([
            10 => [$this->galleryRow(501, self::FILE_A, ['position' => 0])],
        ]);
        $this->gallery->method('insertGalleryRows')->willReturn([]);
        $this->eavValue->method('getValuesForStores')->willReturn([
            10 => [self::ROLE_IDS['image'] => [0 => self::FILE_A]],
        ]);

        $this->eavValue->expects(self::once())->method('upsert')->with('varchar', []);

        $this->processor->process($context);
    }

    public function testAutoAssignsBaseRolesToTheFirstEnabledEntry(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isMediaAutoAssignRoles')->willReturn(true);
        $processor = $this->processorWith(null, null, $config);

        $context = $this->contextFor('P1', [
            $this->entry(self::FILE_B, ['disabled' => true]),
            $this->entry(self::FILE_A),
        ], 10);

        $this->gallery->method('getGallery')->willReturn([]);
        $this->gallery->method('insertGalleryRows')->willReturn([
            "P1\0" . self::FILE_B => 502,
            "P1\0" . self::FILE_A => 501,
        ]);
        $this->eavValue->method('getValuesForStores')->willReturn([]);

        // The first ENABLED entry, and never swatch_image.
        $this->eavValue->expects(self::once())->method('upsert')->with('varchar', [
            $this->roleRow(10, 'image', self::FILE_A),
            $this->roleRow(10, 'small_image', self::FILE_A),
            $this->roleRow(10, 'thumbnail', self::FILE_A),
        ]);

        $processor->process($context);
    }

    public function testAutoAssignDoesNotOverwriteAStoredRole(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isMediaAutoAssignRoles')->willReturn(true);
        $processor = $this->processorWith(null, null, $config);

        $context = $this->contextFor('P1', [$this->entry(self::FILE_A)], 10);

        $this->gallery->method('getGallery')->willReturn([
            10 => [$this->galleryRow(501, self::FILE_A, ['position' => 0])],
        ]);
        $this->gallery->method('insertGalleryRows')->willReturn([]);
        $this->eavValue->method('getValuesForStores')->willReturn([
            10 => [
                self::ROLE_IDS['image'] => [0 => '/x/y/merchant-choice.jpg'],
                // A cleared role counts as "no role" and is filled in.
                self::ROLE_IDS['thumbnail'] => [0 => 'no_selection'],
            ],
        ]);

        $this->eavValue->expects(self::once())->method('upsert')->with('varchar', [
            $this->roleRow(10, 'small_image', self::FILE_A),
            $this->roleRow(10, 'thumbnail', self::FILE_A),
        ]);

        $processor->process($context);
    }

    public function testAutoAssignRepointsARoleWhoseFileThisImportRemoves(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isMediaAutoAssignRoles')->willReturn(true);
        $processor = $this->processorWith(null, null, $config);

        $context = $this->contextFor('P1', [$this->entry(self::FILE_B)], 10);

        $this->gallery->method('getGallery')->willReturn([
            10 => [
                $this->galleryRow(501, self::FILE_A, ['position' => 0]),
                $this->galleryRow(502, self::FILE_B, ['position' => 1]),
            ],
        ]);
        $this->gallery->method('insertGalleryRows')->willReturn([]);
        // The stored role points at the file being removed. It is stale, not a
        // choice worth preserving, so auto-assign must override it rather than
        // leave the storefront asking for a deleted file.
        $this->eavValue->method('getValuesForStores')->willReturn([
            10 => [self::ROLE_IDS['image'] => [0 => self::FILE_A]],
        ]);

        $this->eavValue->expects(self::once())->method('upsert')->with('varchar', [
            $this->roleRow(10, 'image', self::FILE_B),
            $this->roleRow(10, 'small_image', self::FILE_B),
            $this->roleRow(10, 'thumbnail', self::FILE_B),
        ]);
        $this->eavValue->expects(self::once())->method('delete')->with('varchar', []);

        $processor->process($context);
    }

    public function testAutoAssignIsSkippedWhenThePayloadDeclaresAnyRole(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isMediaAutoAssignRoles')->willReturn(true);
        $processor = $this->processorWith(null, null, $config);

        $context = $this->contextFor('P1', [$this->entry(self::FILE_A, ['roles' => ['thumbnail']])], 10);

        $this->gallery->method('getGallery')->willReturn([]);
        $this->gallery->method('insertGalleryRows')->willReturn(["P1\0" . self::FILE_A => 501]);
        $this->eavValue->method('getValuesForStores')->willReturn([]);

        $this->eavValue->expects(self::once())->method('upsert')
            ->with('varchar', [$this->roleRow(10, 'thumbnail', self::FILE_A)]);

        $processor->process($context);
    }

    public function testUsesTheLinkFieldNotTheEntityId(): void
    {
        $productEntity = $this->createMock(ProductEntity::class);
        $productEntity->method('getLinkField')->willReturn('row_id');
        $processor = $this->processorWith(null, null, null, $productEntity);

        $context = $this->contextFor('P1', [$this->entry(self::FILE_A, ['roles' => ['image']])], 10, 555);

        $this->gallery->expects(self::once())->method('getGallery')
            ->with([555], self::GALLERY_ATTRIBUTE_ID)
            ->willReturn([]);
        $this->gallery->method('insertGalleryRows')->willReturn(["P1\0" . self::FILE_A => 501]);
        $this->eavValue->method('getValuesForStores')
            ->with('varchar', array_values(self::ROLE_IDS), [555])
            ->willReturn([]);

        $this->gallery->expects(self::once())->method('bindToEntities')
            ->with([['value_id' => 501, 'link_id' => 555]]);
        $this->gallery->expects(self::once())->method('saveValues')
            ->with([$this->valueRow(501, 555, null, 0)]);
        $this->eavValue->expects(self::once())->method('upsert')->with('varchar', [[
            'row_id' => 555,
            'attribute_id' => self::ROLE_IDS['image'],
            'store_id' => 0,
            'value' => self::FILE_A,
        ]]);

        $processor->process($context);
    }

    /**
     * The read-back condition means the inserted rows cannot be identified, and
     * they are already in the table. Propagating is what lets ImportService roll
     * the batch back; swallowing it here would commit unbound orphan gallery rows
     * that every retry of the payload would duplicate.
     */
    public function testValueIdReadBackFailurePropagatesSoTheBatchRollsBack(): void
    {
        $context = $this->contextFor('P1', [
            $this->entry(self::FILE_A, ['label' => 'New']),
            $this->entry(self::FILE_B),
        ], 10);

        $this->gallery->method('getGallery')->willReturn([
            10 => [$this->galleryRow(501, self::FILE_A, ['label' => 'Old', 'position' => 0])],
        ]);
        $this->eavValue->method('getValuesForStores')->willReturn([]);

        // A concurrent writer made the read-back untrustworthy.
        $this->gallery->expects(self::once())->method('insertGalleryRows')
            ->willThrowException(new \RuntimeException('the generated ids cannot be trusted.'));
        // Nothing downstream of the insert may run on an untrusted id set.
        $this->gallery->expects(self::never())->method('bindToEntities');
        $this->gallery->expects(self::never())->method('saveValues');
        $this->gallery->expects(self::never())->method('saveVideos');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('the generated ids cannot be trusted.');

        $this->processor->process($context);
    }

    /**
     * Regression: writeRoles() used to reuse the name $stored for both the
     * batch-wide value map and one role's default-scope value, so from the SECOND
     * product on it saw no stored roles at all. Auto-assign then overwrote the
     * merchant's own choice — the single-product tests above could never catch it
     * because the shadowing only bites on the next iteration of the outer loop.
     */
    public function testStoredRolesAreHonouredForEveryProductInTheBatch(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isMediaAutoAssignRoles')->willReturn(true);
        $processor = $this->processorWith(null, null, $config);

        $context = $this->contextForMany([
            'P1' => ['entries' => [$this->entry(self::FILE_A)], 'link_id' => 10],
            'P2' => ['entries' => [$this->entry(self::FILE_A)], 'link_id' => 20],
        ]);

        $this->gallery->method('getGallery')->willReturn([
            10 => [$this->galleryRow(501, self::FILE_A, ['position' => 0])],
            20 => [$this->galleryRow(601, self::FILE_A, ['position' => 0])],
        ]);
        $this->gallery->method('insertGalleryRows')->willReturn([]);

        // BOTH products already have "image" set by the merchant; only the
        // remaining base roles may be filled in, for either of them.
        $this->eavValue->method('getValuesForStores')->willReturn([
            10 => [self::ROLE_IDS['image'] => [0 => '/x/y/p1-choice.jpg']],
            20 => [self::ROLE_IDS['image'] => [0 => '/x/y/p2-choice.jpg']],
        ]);

        $this->eavValue->expects(self::once())->method('upsert')->with('varchar', [
            $this->roleRow(10, 'small_image', self::FILE_A),
            $this->roleRow(10, 'thumbnail', self::FILE_A),
            $this->roleRow(20, 'small_image', self::FILE_A),
            $this->roleRow(20, 'thumbnail', self::FILE_A),
        ]);

        $processor->process($context);
    }

    /**
     * The same shadowing also stopped a second product's stale role from being
     * cleared, leaving the storefront pointed at a file the import had removed.
     */
    public function testStaleRoleIsClearedForASecondProductInTheBatch(): void
    {
        $context = $this->contextForMany([
            'P1' => ['entries' => [$this->entry(self::FILE_A)], 'link_id' => 10],
            'P2' => ['entries' => [$this->entry(self::FILE_A)], 'link_id' => 20],
        ]);

        $this->gallery->method('getGallery')->willReturn([
            10 => [$this->galleryRow(501, self::FILE_A, ['position' => 0])],
            // P2 loses FILE_B, and its "image" role points at exactly that file.
            20 => [
                $this->galleryRow(601, self::FILE_A, ['position' => 0]),
                $this->galleryRow(602, self::FILE_B, ['position' => 1]),
            ],
        ]);
        $this->gallery->method('insertGalleryRows')->willReturn([]);
        $this->eavValue->method('getValuesForStores')->willReturn([
            20 => [self::ROLE_IDS['image'] => [0 => self::FILE_B, 5 => self::FILE_B]],
        ]);

        $this->eavValue->expects(self::once())->method('delete')->with('varchar', [
            ['link_id' => 20, 'attribute_id' => self::ROLE_IDS['image'], 'store_id' => 0],
            ['link_id' => 20, 'attribute_id' => self::ROLE_IDS['image'], 'store_id' => 5],
        ]);

        $this->processor->process($context);
    }

    public function testProductWithoutAResolvedLinkIdIsSkipped(): void
    {
        $product = (new Product())->setSku('P1');
        $product->setMedia([$this->entry(self::FILE_A)]);
        $context = new BatchContext([$product]);

        $this->expectNoGalleryWork();

        $this->processor->process($context);
    }

    public function testFailedProductIsIgnored(): void
    {
        $context = $this->contextFor('P1', [$this->entry(self::FILE_A)], 10);
        $context->fail('P1', 'Earlier failure.');

        $this->expectNoGalleryWork();

        $this->processor->process($context);
    }

    public function testMissingMediaGalleryAttributeWarnsAndWritesNothing(): void
    {
        $attributeCache = $this->createMock(AttributeMetadataCache::class);
        $attributeCache->method('get')->willReturn(null);
        $processor = $this->processorWith(null, $attributeCache);

        $context = $this->contextFor('P1', [$this->entry(self::FILE_A)], 10);

        $this->gallery->expects(self::never())->method('getGallery');
        $this->gallery->expects(self::never())->method('insertGalleryRows');
        $this->eavValue->expects(self::never())->method('upsert');
        $this->logger->expects(self::once())->method('error');

        $processor->process($context);

        self::assertSame(
            ['The media_gallery attribute is missing; media was not imported.'],
            $context->getMessages('P1')
        );
    }

    public function testPrepareResolvesEachDistinctReferenceOnce(): void
    {
        $product1 = (new Product())->setSku('P1');
        $product1->setMedia([
            $this->entry(' https://cdn.example.com/a.jpg '),
            $this->entry(self::FILE_A),
        ]);
        $product2 = (new Product())->setSku('P2');
        $product2->setMedia([$this->entry('https://cdn.example.com/a.jpg')]);
        $context = new BatchContext([$product1, $product2]);

        $resolved = ['https://cdn.example.com/a.jpg' => ['file' => '/a/_/a.jpg', 'message' => null]];
        $this->fileResolver->expects(self::once())->method('resolve')
            ->with(['https://cdn.example.com/a.jpg', self::FILE_A])
            ->willReturn($resolved);

        $this->processor->prepare($context);

        self::assertSame($resolved, $context->get(MediaProcessor::CONTEXT_RESOLVED_FILES));
    }

    public function testPrepareWithoutMediaDoesNothing(): void
    {
        $context = new BatchContext([(new Product())->setSku('P1')]);

        $this->fileResolver->expects(self::never())->method('resolve');

        $this->processor->prepare($context);

        self::assertNull($context->get(MediaProcessor::CONTEXT_RESOLVED_FILES));
    }

    public function testPrepareIgnoresFailedProducts(): void
    {
        $product = (new Product())->setSku('P1');
        $product->setMedia([$this->entry(self::FILE_A)]);
        $context = new BatchContext([$product]);
        $context->fail('P1', 'Earlier failure.');

        $this->fileResolver->expects(self::never())->method('resolve');

        $this->processor->prepare($context);
    }

    public function testIsEnabledFollowsConfiguration(): void
    {
        foreach ([true, false] as $enabled) {
            $config = $this->createMock(Config::class);
            $config->method('isMediaEnabled')->willReturn($enabled);
            self::assertSame($enabled, $this->processorWith(null, null, $config)->isEnabled());
        }
    }

    public function testRunsAfterCategoryLinksAndBeforeProductLinks(): void
    {
        self::assertSame(710, $this->processor->getSortOrder());
    }

    public function testPublishesInsertedFilesAsCreated(): void
    {
        $context = $this->contextFor('P1', [
            $this->entry(self::FILE_A),
            $this->entry(self::FILE_B),
        ], 10);

        $this->gallery->method('getGallery')->willReturn([]);
        $this->eavValue->method('getValuesForStores')->willReturn([]);
        $this->gallery->method('insertGalleryRows')->willReturnCallback(
            static function (array $rows): array {
                $ids = [];
                $next = 900;
                foreach (array_keys($rows) as $key) {
                    $ids[$key] = $next++;
                }

                return $ids;
            }
        );

        $this->processor->process($context);

        self::assertSame([
            'P1' => [
                'entity_id' => 10,
                'created' => [self::FILE_A, self::FILE_B],
                'updated' => [],
                'removed' => [],
                'roles' => [],
                'partial' => false,
            ],
        ], $context->get(MediaProcessor::CONTEXT_CHANGES));
        self::assertSame(
            [self::FILE_A, self::FILE_B],
            $context->get(MediaProcessor::CONTEXT_RETAINED_FILES)
        );
    }

    public function testAnUnchangedGalleryPublishesNothing(): void
    {
        // A re-import must not tell a CDN to reprocess everything it hears about.
        $context = $this->contextFor('P1', [$this->entry(self::FILE_A, ['label' => 'Front'])], 10);

        $this->gallery->method('getGallery')->willReturn([
            10 => [$this->galleryRow(501, self::FILE_A, ['label' => 'Front', 'position' => 0])],
        ]);
        $this->eavValue->method('getValuesForStores')->willReturn([]);
        $this->gallery->method('insertGalleryRows')->willReturn([]);

        $this->processor->process($context);

        self::assertNull($context->get(MediaProcessor::CONTEXT_CHANGES));
    }

    public function testPublishesMetadataChangesAsUpdated(): void
    {
        $context = $this->contextFor('P1', [$this->entry(self::FILE_A, ['label' => 'New'])], 10);

        $this->gallery->method('getGallery')->willReturn([
            10 => [$this->galleryRow(501, self::FILE_A, ['label' => 'Old', 'position' => 0])],
        ]);
        $this->eavValue->method('getValuesForStores')->willReturn([]);
        $this->gallery->method('insertGalleryRows')->willReturn([]);

        $this->processor->process($context);

        $changes = $context->get(MediaProcessor::CONTEXT_CHANGES);
        self::assertSame([self::FILE_A], $changes['P1']['updated']);
        self::assertSame([], $changes['P1']['created']);
        self::assertSame([], $changes['P1']['removed']);
    }

    public function testPublishesAVideoOnlyChangeAsUpdated(): void
    {
        $context = $this->contextFor('P1', [
            $this->entry(self::FILE_A, ['position' => 0, 'video_url' => 'https://youtu.be/new']),
        ], 10);

        $this->gallery->method('getGallery')->willReturn([
            10 => [$this->galleryRow(501, self::FILE_A, [
                'media_type' => ProductMediaGallery::MEDIA_TYPE_EXTERNAL_VIDEO,
                'position' => 0,
                'video' => [
                    'provider' => 'youtube',
                    'url' => 'https://youtu.be/old',
                    'title' => null,
                    'description' => null,
                    'metadata' => null,
                ],
            ])],
        ]);
        $this->eavValue->method('getValuesForStores')->willReturn([]);
        $this->gallery->method('insertGalleryRows')->willReturn([]);

        $this->processor->process($context);

        self::assertSame([self::FILE_A], $context->get(MediaProcessor::CONTEXT_CHANGES)['P1']['updated']);
    }

    public function testPublishesDeletedFilesAsRemoved(): void
    {
        $context = $this->contextFor('P1', [$this->entry(self::FILE_A, ['position' => 0])], 10);

        $this->gallery->method('getGallery')->willReturn([
            10 => [
                $this->galleryRow(501, self::FILE_A, ['position' => 0]),
                $this->galleryRow(502, self::FILE_B, ['position' => 1]),
            ],
        ]);
        $this->eavValue->method('getValuesForStores')->willReturn([]);
        $this->gallery->method('insertGalleryRows')->willReturn([]);

        $this->processor->process($context);

        $changes = $context->get(MediaProcessor::CONTEXT_CHANGES);
        self::assertSame([self::FILE_B], $changes['P1']['removed']);
        self::assertSame([], $changes['P1']['updated']);
    }

    public function testWithheldRemovalsAreNotPublishedButThePartialFlagIs(): void
    {
        $context = $this->contextFor('P1', [
            $this->entry(self::FILE_A, ['position' => 0]),
            $this->entry(self::FILE_B),
            $this->entry('missing.jpg'),
        ], 10);

        $this->gallery->method('getGallery')->willReturn([
            10 => [
                $this->galleryRow(501, self::FILE_A, ['position' => 0]),
                $this->galleryRow(502, '/g/o/gone.jpg', ['position' => 1]),
            ],
        ]);
        $this->eavValue->method('getValuesForStores')->willReturn([]);
        $this->gallery->method('insertGalleryRows')->willReturn(["P1\0" . self::FILE_B => 900]);

        $this->processor->process($context);

        $changes = $context->get(MediaProcessor::CONTEXT_CHANGES);
        self::assertTrue($changes['P1']['partial']);
        self::assertSame([self::FILE_B], $changes['P1']['created']);
        self::assertSame([], $changes['P1']['removed']);
    }

    public function testProductsWithoutAMediaBlockAreNotPublished(): void
    {
        $withMedia = (new Product())->setSku('P1');
        $withMedia->setMedia([$this->entry(self::FILE_A)]);
        $context = new BatchContext([$withMedia, (new Product())->setSku('P2')]);
        $context->setEntityId('P1', 10);
        $context->setEntityId('P2', 20);
        $context->set(EntityProcessor::CONTEXT_LINK_IDS, ['P1' => 10, 'P2' => 20]);
        $context->set(MediaProcessor::CONTEXT_RESOLVED_FILES, [
            self::FILE_A => ['file' => self::FILE_A, 'message' => null],
        ]);

        $this->gallery->method('getGallery')->willReturn([]);
        $this->eavValue->method('getValuesForStores')->willReturn([]);
        $this->gallery->method('insertGalleryRows')->willReturn(["P1\0" . self::FILE_A => 900]);

        $this->processor->process($context);

        self::assertSame(['P1'], array_keys($context->get(MediaProcessor::CONTEXT_CHANGES)));
    }

    public function testNumericSkusSurviveArrayKeyCoercion(): void
    {
        $context = $this->contextFor('1234', [$this->entry(self::FILE_A)], 10);

        $this->gallery->method('getGallery')->willReturn([]);
        $this->eavValue->method('getValuesForStores')->willReturn([]);
        $this->gallery->method('insertGalleryRows')->willReturn(["1234\0" . self::FILE_A => 900]);

        $this->processor->process($context);

        $changes = $context->get(MediaProcessor::CONTEXT_CHANGES);
        self::assertCount(1, $changes);
        self::assertSame([self::FILE_A], array_values($changes)[0]['created']);
        self::assertSame(10, array_values($changes)[0]['entity_id']);
    }

    public function testARoleMovingBetweenTwoUnchangedFilesIsPublished(): void
    {
        // Nothing about the gallery rows changes — only which file the base
        // image points at. That is the single most storefront-visible media
        // fact there is, so it cannot be the change that reports nothing.
        $context = $this->contextFor('P1', [
            $this->entry(self::FILE_A, ['position' => 0]),
            $this->entry(self::FILE_B, ['position' => 1, 'roles' => ['image']]),
        ], 10);

        $this->gallery->method('getGallery')->willReturn([
            10 => [
                $this->galleryRow(501, self::FILE_A, ['position' => 0]),
                $this->galleryRow(502, self::FILE_B, ['position' => 1]),
            ],
        ]);
        $this->eavValue->method('getValuesForStores')->willReturn([
            10 => [self::ROLE_IDS['image'] => [0 => self::FILE_A]],
        ]);
        $this->gallery->method('insertGalleryRows')->willReturn([]);

        $this->processor->process($context);

        $changes = $context->get(MediaProcessor::CONTEXT_CHANGES);
        self::assertNotNull($changes, 'A base-image swap must not be filtered out as "no change".');
        self::assertSame(['image' => self::FILE_B], $changes['P1']['roles']);
        self::assertSame([], $changes['P1']['created']);
        self::assertSame([], $changes['P1']['updated']);
        self::assertSame([], $changes['P1']['removed']);
    }

    public function testAStaleRoleIsPublishedAsCleared(): void
    {
        $context = $this->contextFor('P1', [$this->entry(self::FILE_A, ['position' => 0])], 10);

        $this->gallery->method('getGallery')->willReturn([
            10 => [
                $this->galleryRow(501, self::FILE_A, ['position' => 0]),
                $this->galleryRow(502, self::FILE_B, ['position' => 1]),
            ],
        ]);
        // The role points at the file this import removes, so writeRoles() drops
        // its rows rather than leaving the storefront asking for a dead path.
        $this->eavValue->method('getValuesForStores')->willReturn([
            10 => [self::ROLE_IDS['image'] => [0 => self::FILE_B]],
        ]);
        $this->gallery->method('insertGalleryRows')->willReturn([]);

        $this->processor->process($context);

        $changes = $context->get(MediaProcessor::CONTEXT_CHANGES);
        self::assertSame(['image' => null], $changes['P1']['roles']);
        self::assertSame([self::FILE_B], $changes['P1']['removed']);
    }

    public function testUnchangedRolesOnAnUnchangedGalleryPublishNothing(): void
    {
        // The role is declared and already correct: no row is written, so this
        // must stay as silent as a re-import with no roles at all.
        $context = $this->contextFor('P1', [
            $this->entry(self::FILE_A, ['position' => 0, 'roles' => ['image']]),
        ], 10);

        $this->gallery->method('getGallery')->willReturn([
            10 => [$this->galleryRow(501, self::FILE_A, ['position' => 0])],
        ]);
        $this->eavValue->method('getValuesForStores')->willReturn([
            10 => [self::ROLE_IDS['image'] => [0 => self::FILE_A]],
        ]);
        $this->gallery->method('insertGalleryRows')->willReturn([]);

        $this->processor->process($context);

        self::assertNull($context->get(MediaProcessor::CONTEXT_CHANGES));
        self::assertNull($context->get(MediaProcessor::CONTEXT_RETAINED_FILES));
    }

    public function testRetainedFilesCoverAFileThatOnlyMovedBetweenProducts(): void
    {
        // P1 drops the shared file, P2 gains it. It is not gone from the store,
        // and the dispatcher needs to be able to tell that.
        $context = $this->contextForMany([
            'P1' => ['entries' => [$this->entry(self::FILE_A, ['position' => 0])], 'link_id' => 10],
            'P2' => ['entries' => [
                $this->entry(self::FILE_A, ['position' => 0]),
                $this->entry(self::FILE_B, ['position' => 1]),
            ], 'link_id' => 20],
        ]);

        $this->gallery->method('getGallery')->willReturn([
            10 => [
                $this->galleryRow(501, self::FILE_A, ['position' => 0]),
                $this->galleryRow(502, self::FILE_B, ['position' => 1]),
            ],
            20 => [$this->galleryRow(601, self::FILE_A, ['position' => 0])],
        ]);
        $this->eavValue->method('getValuesForStores')->willReturn([]);
        $this->gallery->method('insertGalleryRows')->willReturn(["P2\0" . self::FILE_B => 900]);

        $this->processor->process($context);

        $changes = $context->get(MediaProcessor::CONTEXT_CHANGES);
        self::assertSame([self::FILE_B], $changes['P1']['removed']);
        self::assertSame([self::FILE_B], $changes['P2']['created']);

        $retained = $context->get(MediaProcessor::CONTEXT_RETAINED_FILES);
        sort($retained);
        self::assertSame([self::FILE_A, self::FILE_B], $retained);

        // The rule that moved here from ImportEventDispatcher when the cleanup
        // hook needed the same answer: P1 detached FILE_B but P2 gained it, so
        // the batch did not let go of it. Deleting it on the strength of P1's
        // removal would take away the image P2 now shows.
        self::assertSame([], $context->get(MediaProcessor::CONTEXT_REMOVED_FILES));
    }

    /**
     * A file nothing in the batch retains does reach the union — the other half
     * of the rule above, and the input the post-commit cleanup acts on.
     */
    public function testTheRemovalUnionKeepsAFileNoProductInTheBatchStillHolds(): void
    {
        $context = $this->contextFor('P1', [], 10);
        $this->gallery->method('getGallery')->willReturn([
            10 => [$this->galleryRow(500, self::FILE_B)],
        ]);
        $this->eavValue->method('getValuesForStores')->willReturn([]);

        $this->processor->process($context);

        self::assertSame([self::FILE_B], $context->get(MediaProcessor::CONTEXT_CHANGES)['P1']['removed']);
        self::assertSame([self::FILE_B], $context->get(MediaProcessor::CONTEXT_REMOVED_FILES));
    }

    /**
     * The cleanup hooks are gated on the ownership flag, and with it off nothing
     * is even asked about — the service is never handed a list.
     */
    public function testTheCleanupHooksDoNothingWhenTheStoreDoesNotOwnProductMedia(): void
    {
        $this->mediaCleanup->method('isEnabled')->willReturn(false);
        $this->mediaCleanup->expects(self::never())->method('deleteUnreferenced');
        $this->mediaCleanup->expects(self::never())->method('deleteAbandonedDownloads');

        $context = $this->contextFor('P1', [], 10);
        $context->set(MediaProcessor::CONTEXT_REMOVED_FILES, [self::FILE_B]);

        $this->processor->cleanUpAfterCommit($context);
        $this->processor->cleanUpAfterRollback($context);
    }

    public function testCleanUpAfterCommitPassesTheRemovalUnionToTheService(): void
    {
        $this->mediaCleanup->method('isEnabled')->willReturn(true);
        $this->mediaCleanup->expects(self::once())->method('deleteUnreferenced')
            ->with([self::FILE_B])
            ->willReturn([self::FILE_B]);

        $context = $this->contextFor('P1', [], 10);
        $context->set(MediaProcessor::CONTEXT_REMOVED_FILES, [self::FILE_B]);

        $this->processor->cleanUpAfterCommit($context);
    }

    /**
     * A rolled-back batch has to discard what it FETCHED — not a local path, and
     * not a file that skip-if-present adopted, because those were already there
     * and are not ours to withdraw.
     *
     * Through deleteAbandonedDownloads(), not deleteUnreferenced(): a file this
     * batch just downloaded is inside the grace period by definition, so the
     * detach-path entry point would spare every one of them.
     */
    public function testCleanUpAfterRollbackDiscardsOnlyWhatThisBatchDownloaded(): void
    {
        $this->mediaCleanup->method('isEnabled')->willReturn(true);
        $this->mediaCleanup->expects(self::never())->method('deleteUnreferenced');
        $this->mediaCleanup->expects(self::once())->method('deleteAbandonedDownloads')
            ->with([self::FILE_A])
            ->willReturn([self::FILE_A]);

        $context = $this->contextFor('P1', [], 10);
        $context->set(MediaProcessor::CONTEXT_RESOLVED_FILES, [
            'https://cdn/new.jpg' => ['file' => self::FILE_A, 'message' => null, 'downloaded' => true],
            'https://cdn/kept.jpg' => ['file' => self::FILE_B, 'message' => null, 'downloaded' => false],
            'https://cdn/bad.jpg' => ['file' => null, 'message' => 'nope', 'downloaded' => false],
        ]);

        $this->processor->cleanUpAfterRollback($context);
    }

    public function testCleanUpAfterRollbackDoesNothingWhenNothingWasDownloaded(): void
    {
        $this->mediaCleanup->method('isEnabled')->willReturn(true);
        $this->mediaCleanup->expects(self::never())->method('deleteAbandonedDownloads');

        $context = $this->contextFor('P1', [], 10);
        $context->set(MediaProcessor::CONTEXT_RESOLVED_FILES, [
            'https://cdn/kept.jpg' => ['file' => self::FILE_B, 'message' => null, 'downloaded' => false],
        ]);

        $this->processor->cleanUpAfterRollback($context);
    }

    /**
     * Same as contextFor() but for a whole batch, which is the only shape in
     * which writeRoles() iterates its outer loop more than once.
     *
     * @param array<string, array{entries: MediaEntryInterface[], link_id: int}> $products
     */
    private function contextForMany(array $products): BatchContext
    {
        $list = [];
        $linkIds = [];
        foreach ($products as $sku => $spec) {
            $product = (new Product())->setSku((string)$sku);
            $product->setMedia($spec['entries']);
            $list[] = $product;
            $linkIds[(string)$sku] = $spec['link_id'];
        }

        $context = new BatchContext($list);
        foreach ($linkIds as $sku => $linkId) {
            $context->setEntityId($sku, $linkId);
        }
        $context->set(EntityProcessor::CONTEXT_LINK_IDS, $linkIds);
        $context->set(MediaProcessor::CONTEXT_RESOLVED_FILES, [
            self::FILE_A => ['file' => self::FILE_A, 'message' => null],
            self::FILE_B => ['file' => self::FILE_B, 'message' => null],
        ]);

        return $context;
    }

    private function expectNoGalleryWork(): void
    {
        $this->gallery->expects(self::never())->method('getGallery');
        $this->gallery->expects(self::never())->method('insertGalleryRows');
        $this->gallery->expects(self::never())->method('removeEntries');
        $this->gallery->expects(self::never())->method('saveValues');
        $this->gallery->expects(self::never())->method('saveVideos');
        $this->gallery->expects(self::never())->method('updateGalleryRows');
        $this->eavValue->expects(self::never())->method('upsert');
    }

    /**
     * A processor sharing this test's collaborators except the ones replaced.
     */
    private function processorWith(
        ?ProductMediaGallery $gallery = null,
        ?AttributeMetadataCache $attributeCache = null,
        ?Config $config = null,
        ?ProductEntity $productEntity = null
    ): MediaProcessor {
        return new MediaProcessor(
            $gallery ?? $this->gallery,
            $productEntity ?? $this->productEntity,
            $attributeCache ?? $this->attributeCache,
            $this->eavValue,
            $this->fileResolver,
            $config ?? $this->config,
            $this->mediaCleanup,
            $this->logger
        );
    }

    /**
     * @return array{attribute_id: int, attribute_code: string, backend_type: string,
     *         frontend_input: string, frontend_label: string, is_global: int, is_required: int}|null
     */
    private function metaFor(string $code): ?array
    {
        $id = $code === 'media_gallery' ? self::GALLERY_ATTRIBUTE_ID : (self::ROLE_IDS[$code] ?? null);
        if ($id === null) {
            return null;
        }

        return [
            'attribute_id' => $id,
            'attribute_code' => $code,
            'backend_type' => $code === 'media_gallery' ? 'static' : 'varchar',
            'frontend_input' => $code === 'media_gallery' ? 'gallery' : 'media_image',
            'frontend_label' => $code,
            'is_global' => 0,
            'is_required' => 0,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function entry(string $file, array $data = []): MediaEntryInterface
    {
        $entry = (new MediaEntry())->setFile($file);
        foreach ($data as $key => $value) {
            match ($key) {
                'label' => $entry->setLabel($value),
                'position' => $entry->setPosition($value),
                'disabled' => $entry->setDisabled($value),
                'roles' => $entry->setRoles($value),
                'media_type' => $entry->setMediaType($value),
                'video_provider' => $entry->setVideoProvider($value),
                'video_url' => $entry->setVideoUrl($value),
                'video_title' => $entry->setVideoTitle($value),
                'video_description' => $entry->setVideoDescription($value),
                'video_metadata' => $entry->setVideoMetadata($value),
            };
        }

        return $entry;
    }

    /**
     * A stored gallery row as ProductMediaGallery::getGallery() returns it.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function galleryRow(int $valueId, string $file, array $overrides = []): array
    {
        return array_merge([
            'value_id' => $valueId,
            'file' => $file,
            'media_type' => ProductMediaGallery::MEDIA_TYPE_IMAGE,
            'gallery_disabled' => 0,
            'label' => null,
            'position' => null,
            'value_disabled' => 0,
            'has_value_row' => true,
            'video' => null,
        ], $overrides);
    }

    /**
     * @return array{attribute_id: int, value: string, media_type: string, disabled: int}
     */
    private function insertRow(string $file, string $mediaType = ProductMediaGallery::MEDIA_TYPE_IMAGE): array
    {
        return [
            'attribute_id' => self::GALLERY_ATTRIBUTE_ID,
            'value' => $file,
            'media_type' => $mediaType,
            'disabled' => 0,
        ];
    }

    /**
     * @return array{value_id: int, link_id: int, label: string|null, position: int, disabled: int}
     */
    private function valueRow(
        int $valueId,
        int $linkId,
        ?string $label,
        int $position,
        int $disabled = 0
    ): array {
        return [
            'value_id' => $valueId,
            'link_id' => $linkId,
            'label' => $label,
            'position' => $position,
            'disabled' => $disabled,
        ];
    }

    /**
     * @return array<string, int|string>
     */
    private function roleRow(int $linkId, string $code, string $file, int $storeId = 0): array
    {
        return [
            'entity_id' => $linkId,
            'attribute_id' => self::ROLE_IDS[$code],
            'store_id' => $storeId,
            'value' => $file,
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private static function callbackVideo(string $provider): array
    {
        return [
            'value_id' => 501,
            'provider' => $provider,
            'url' => self::lastVideoUrl($provider),
            'title' => null,
            'description' => null,
            'metadata' => null,
        ];
    }

    private static function lastVideoUrl(string $provider): string
    {
        return $provider === 'vimeo'
            ? 'https://player.vimeo.com/video/12345'
            : 'https://media.example.com/clip.mp4';
    }

    private function additiveMessage(): string
    {
        return 'Media gallery applied additively: some entries could not be resolved,'
            . ' so no existing gallery entries were removed.';
    }

    /**
     * @param MediaEntryInterface[] $entries
     */
    private function contextFor(string $sku, array $entries, int $entityId, ?int $linkId = null): BatchContext
    {
        $product = (new Product())->setSku($sku);
        $product->setMedia($entries);

        $context = new BatchContext([$product]);
        $context->setEntityId($sku, $entityId);
        $context->set(EntityProcessor::CONTEXT_LINK_IDS, [$sku => $linkId ?? $entityId]);
        $context->set(MediaProcessor::CONTEXT_RESOLVED_FILES, [
            self::FILE_A => ['file' => self::FILE_A, 'message' => null],
            self::FILE_B => ['file' => self::FILE_B, 'message' => null],
            'missing.jpg' => [
                'file' => null,
                'message' => 'Media file "missing.jpg" was not found under pub/media/catalog/product; skipped.',
            ],
        ]);

        return $context;
    }
}
