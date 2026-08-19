<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Test\Unit\Observer;

use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReadyData\Import\Logger\Logger;
use ReadyData\Import\Model\Cache\AttributeMetadataCache;
use ReadyData\Import\Model\Media\Cleanup\DeletedProductMedia;
use ReadyData\Import\Model\Media\Cleanup\MediaCleanupService;
use ReadyData\Import\Model\ResourceModel\ProductEntity;
use ReadyData\Import\Model\ResourceModel\ProductMediaGallery;
use ReadyData\Import\Observer\CaptureProductMediaOnDelete;
use ReadyData\Import\Observer\CleanUpProductMediaAfterDelete;

/**
 * The two halves of the product-delete cleanup are tested together because
 * neither means anything alone: the paths only survive the delete because one
 * reads them before the transaction and the other acts after it commits.
 */
class ProductDeleteMediaCleanupTest extends TestCase
{
    private const GALLERY_ATTRIBUTE_ID = 90;

    private MediaCleanupService&MockObject $cleanup;
    private DeletedProductMedia $registry;
    private ProductMediaGallery&MockObject $gallery;

    protected function setUp(): void
    {
        $this->cleanup = $this->createMock(MediaCleanupService::class);
        $this->registry = new DeletedProductMedia();
        $this->gallery = $this->createMock(ProductMediaGallery::class);
    }

    public function testPathsReadBeforeTheDeleteAreCleanedUpAfterItCommits(): void
    {
        $this->cleanup->method('isEnabled')->willReturn(true);
        $this->gallery->method('getGallery')->willReturn([
            55 => [
                ['value_id' => 1, 'file' => '/a/b/one.jpg'],
                ['value_id' => 2, 'file' => '/a/b/two.jpg'],
            ],
        ]);
        $this->cleanup->expects(self::once())->method('deleteUnreferenced')
            ->with(['/a/b/one.jpg', '/a/b/two.jpg'])
            ->willReturn(['/a/b/one.jpg']);

        $observer = $this->observerFor(7, 55);
        $this->capture()->execute($observer);
        $this->cleanUp()->execute($observer);
    }

    /**
     * By the time the "after" event fires the gallery rows are gone, so nothing
     * captured means nothing to do — never a second read that would find an
     * empty gallery and conclude the product had no images.
     */
    public function testNothingIsDeletedWhenNothingWasCaptured(): void
    {
        $this->cleanup->method('isEnabled')->willReturn(true);
        $this->cleanup->expects(self::never())->method('deleteUnreferenced');

        $this->cleanUp()->execute($this->observerFor(7, 55));
    }

    /**
     * A delete that never reaches its "after" event must not leave paths behind
     * for some later product to pick up.
     */
    public function testCapturedPathsAreConsumedOnce(): void
    {
        $this->registry->remember(7, ['/a/b/one.jpg']);

        self::assertSame(['/a/b/one.jpg'], $this->registry->take(7));
        self::assertSame([], $this->registry->take(7));
    }

    public function testNeitherObserverActsWhenTheStoreDoesNotOwnProductMedia(): void
    {
        $this->cleanup->method('isEnabled')->willReturn(false);
        $this->gallery->expects(self::never())->method('getGallery');
        $this->cleanup->expects(self::never())->method('deleteUnreferenced');

        $observer = $this->observerFor(7, 55);
        $this->capture()->execute($observer);
        $this->registry->remember(7, ['/a/b/one.jpg']);
        $this->cleanUp()->execute($observer);
    }

    /**
     * Tidying up is not worth failing a delete over: the capture swallows,
     * remembers nothing, and the files are left for the §9.1 report to surface.
     */
    public function testAFailureReadingTheGalleryDoesNotBreakTheDelete(): void
    {
        $this->cleanup->method('isEnabled')->willReturn(true);
        $this->gallery->method('getGallery')->willThrowException(new \RuntimeException('db gone'));

        $logger = $this->createMock(Logger::class);
        $logger->expects(self::once())->method('warning');

        $this->capture($logger)->execute($this->observerFor(7, 55));

        self::assertSame([], $this->registry->take(7));
    }

    /**
     * The product is already deleted and committed by now; an exception here
     * would report a successful delete as broken.
     */
    public function testAFailureDeletingIsLoggedNotThrown(): void
    {
        $this->cleanup->method('isEnabled')->willReturn(true);
        $this->cleanup->method('deleteUnreferenced')->willThrowException(new \RuntimeException('disk gone'));
        $this->registry->remember(7, ['/a/b/one.jpg']);

        $logger = $this->createMock(Logger::class);
        $logger->expects(self::once())->method('error');

        $this->cleanUp($logger)->execute($this->observerFor(7, 55));
    }

    public function testAnEventWithoutAProductIsIgnored(): void
    {
        $this->cleanup->method('isEnabled')->willReturn(true);
        $this->gallery->expects(self::never())->method('getGallery');
        $this->cleanup->expects(self::never())->method('deleteUnreferenced');

        $observer = new Observer(['event' => new Event()]);
        $this->capture()->execute($observer);
        $this->cleanUp()->execute($observer);
    }

    private function capture(?Logger $logger = null): CaptureProductMediaOnDelete
    {
        $productEntity = $this->createMock(ProductEntity::class);
        $productEntity->method('getLinkField')->willReturn('entity_id');

        $attributes = $this->createMock(AttributeMetadataCache::class);
        $attributes->method('get')->willReturn([
            'attribute_id' => self::GALLERY_ATTRIBUTE_ID,
            'attribute_code' => 'media_gallery',
            'backend_type' => 'static',
            'frontend_input' => 'gallery',
            'frontend_label' => 'Media Gallery',
            'is_global' => 1,
            'is_required' => 0,
            'apply_to' => '',
        ]);

        return new CaptureProductMediaOnDelete(
            $this->cleanup,
            $this->registry,
            $this->gallery,
            $productEntity,
            $attributes,
            $logger ?? $this->createMock(Logger::class)
        );
    }

    private function cleanUp(?Logger $logger = null): CleanUpProductMediaAfterDelete
    {
        return new CleanUpProductMediaAfterDelete(
            $this->cleanup,
            $this->registry,
            $logger ?? $this->createMock(Logger::class)
        );
    }

    private function observerFor(int $productId, int $linkId): Observer
    {
        $product = new DataObject(['id' => $productId, 'entity_id' => $linkId]);

        return new Observer(['event' => new Event(['product' => $product])]);
    }
}
