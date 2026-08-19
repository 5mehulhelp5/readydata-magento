<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Test\Unit\Model\Media\Cleanup;

use Magento\Catalog\Model\Product\Image\RemoveDeletedImagesFromCache;
use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReadyData\Import\Api\MediaReferenceCheckerInterface;
use ReadyData\Import\Logger\Logger;
use ReadyData\Import\Model\Config;
use ReadyData\Import\Model\Media\Cleanup\MediaCleanupService;

class MediaCleanupServiceTest extends TestCase
{
    private Config&MockObject $config;
    private MediaReferenceCheckerInterface&MockObject $checker;
    private WriteInterface&MockObject $directory;
    private RemoveDeletedImagesFromCache&MockObject $cachePurge;

    /** @var string[] paths that exist on the fake filesystem */
    private array $existing = [];

    /** @var string[] paths delete() was called with */
    private array $deleted = [];

    /** @var string[] paths whose delete() throws */
    private array $undeletable = [];

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('ownsProductMedia')->willReturn(true);
        $this->checker = $this->createMock(MediaReferenceCheckerInterface::class);
        $this->cachePurge = $this->createMock(RemoveDeletedImagesFromCache::class);

        $this->directory = $this->createMock(WriteInterface::class);
        $this->directory->method('isExist')
            ->willReturnCallback(fn (string $p): bool => in_array($p, $this->existing, true));
        $this->directory->method('delete')->willReturnCallback(function (string $p): bool {
            if (in_array($p, $this->undeletable, true)) {
                throw new FileSystemException(__('permission denied'));
            }
            $this->deleted[] = $p;

            return true;
        });
    }

    /**
     * The flag is an assertion the operator makes about their store, and with it
     * off nothing is even asked — no query, no filesystem call.
     */
    public function testNothingHappensWhenTheStoreDoesNotOwnProductMedia(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('ownsProductMedia')->willReturn(false);
        $this->checker->expects(self::never())->method('getUnreferenced');

        $service = $this->service($config);

        self::assertFalse($service->isEnabled());
        self::assertSame([], $service->deleteUnreferenced(['/a/b/x.jpg']));
        self::assertSame([], $this->deleted);
    }

    /**
     * The check is not a formality. Deterministic target paths mean two SKUs fed
     * the same image URL share one file, so a file the caller detached from one
     * product may still be another's.
     */
    public function testAStillReferencedFileIsNotDeleted(): void
    {
        $this->existing = ['catalog/product/a/b/keep.jpg', 'catalog/product/a/b/go.jpg'];
        $this->checker->method('getUnreferenced')
            ->with(['/a/b/keep.jpg', '/a/b/go.jpg'])
            ->willReturn(['/a/b/go.jpg']);

        $deleted = $this->service()->deleteUnreferenced(['/a/b/keep.jpg', '/a/b/go.jpg']);

        self::assertSame(['/a/b/go.jpg'], $deleted);
        self::assertSame(['catalog/product/a/b/go.jpg'], $this->deleted);
    }

    public function testTheCheckIsMadeOnceForTheWholeListNotPerFile(): void
    {
        $this->existing = ['catalog/product/a/b/one.jpg', 'catalog/product/a/b/two.jpg'];
        $this->checker->expects(self::once())->method('getUnreferenced')
            ->willReturn(['/a/b/one.jpg', '/a/b/two.jpg']);

        $this->service()->deleteUnreferenced(['/a/b/one.jpg', '/a/b/two.jpg']);

        self::assertCount(2, $this->deleted);
    }

    public function testEmptyAndDuplicatePathsAreNeverQueried(): void
    {
        $this->checker->expects(self::once())->method('getUnreferenced')
            ->with(['/a/b/x.jpg'])
            ->willReturn([]);

        $this->service()->deleteUnreferenced(['/a/b/x.jpg', '', '  ', '/a/b/x.jpg']);
    }

    public function testNothingIsQueriedForAnEmptyList(): void
    {
        $this->checker->expects(self::never())->method('getUnreferenced');

        self::assertSame([], $this->service()->deleteUnreferenced([]));
    }

    /**
     * A file already gone is the normal outcome of a concurrent run, not a
     * problem to report.
     */
    public function testAFileThatIsAlreadyGoneIsSkippedQuietly(): void
    {
        $this->existing = [];
        $this->checker->method('getUnreferenced')->willReturn(['/a/b/x.jpg']);

        self::assertSame([], $this->service()->deleteUnreferenced(['/a/b/x.jpg']));
        self::assertSame([], $this->deleted);
    }

    /**
     * Every caller has already committed its real work, so a file that cannot be
     * removed is a logged annoyance — never an exception thrown back into an
     * import or a product delete.
     */
    public function testAFailedDeleteIsLoggedAndTheRestStillProceed(): void
    {
        $this->existing = ['catalog/product/a/b/bad.jpg', 'catalog/product/a/b/ok.jpg'];
        $this->undeletable = ['catalog/product/a/b/bad.jpg'];
        $this->checker->method('getUnreferenced')->willReturn(['/a/b/bad.jpg', '/a/b/ok.jpg']);

        $logger = $this->createMock(Logger::class);
        $logger->expects(self::once())->method('warning');

        $deleted = $this->service(null, $logger)->deleteUnreferenced(['/a/b/bad.jpg', '/a/b/ok.jpg']);

        self::assertSame(['/a/b/ok.jpg'], $deleted);
    }

    /**
     * Deleting the source without its renditions just moves the bytes into
     * catalog/product/cache. Core's helper expects paths without a leading slash.
     */
    public function testRenditionsArePurgedForDeletedFilesWithoutALeadingSlash(): void
    {
        $this->existing = ['catalog/product/a/b/go.jpg'];
        $this->checker->method('getUnreferenced')->willReturn(['/a/b/go.jpg']);
        $this->cachePurge->expects(self::once())->method('removeDeletedImagesFromCache')
            ->with(['a/b/go.jpg']);

        $this->service()->deleteUnreferenced(['/a/b/go.jpg']);
    }

    public function testRenditionsAreNotPurgedWhenNothingWasDeleted(): void
    {
        $this->checker->method('getUnreferenced')->willReturn([]);
        $this->cachePurge->expects(self::never())->method('removeDeletedImagesFromCache');

        $this->service()->deleteUnreferenced(['/a/b/x.jpg']);
    }

    /**
     * The files are already gone by then; a cache-purge failure must not look
     * like the deletion failed.
     */
    public function testAFailingCachePurgeIsLoggedNotThrown(): void
    {
        $this->existing = ['catalog/product/a/b/go.jpg'];
        $this->checker->method('getUnreferenced')->willReturn(['/a/b/go.jpg']);
        $this->cachePurge->method('removeDeletedImagesFromCache')
            ->willThrowException(new \RuntimeException('view config exploded'));

        $logger = $this->createMock(Logger::class);
        $logger->expects(self::once())->method('warning');

        self::assertSame(['/a/b/go.jpg'], $this->service(null, $logger)->deleteUnreferenced(['/a/b/go.jpg']));
    }

    private function service(?Config $config = null, ?Logger $logger = null): MediaCleanupService
    {
        $mediaConfig = $this->createMock(MediaConfig::class);
        $mediaConfig->method('getBaseMediaPath')->willReturn('catalog/product');

        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->willReturn($this->directory);

        return new MediaCleanupService(
            $config ?? $this->config,
            $this->checker,
            $filesystem,
            $mediaConfig,
            $this->cachePurge,
            $logger ?? $this->createMock(Logger::class)
        );
    }
}
