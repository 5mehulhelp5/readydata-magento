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

    /**
     * Old enough that the grace period never applies. Every test that is not
     * about the grace period wants this.
     */
    private const LONG_AGO = 30 * 86400;

    /** @var string[] files that exist on the fake filesystem */
    private array $existing = [];

    /** @var string[] paths that exist but are directories */
    private array $directories = [];

    /** @var array<string, int> path => mtime, defaulting to LONG_AGO ago */
    private array $mtimes = [];

    /** @var string[] paths delete() was called with */
    private array $deleted = [];

    /** @var string[] paths whose delete() throws */
    private array $undeletable = [];

    /** @var string[] paths whose stat() throws */
    private array $unstattable = [];

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('ownsProductMedia')->willReturn(true);
        $this->checker = $this->createMock(MediaReferenceCheckerInterface::class);
        $this->cachePurge = $this->createMock(RemoveDeletedImagesFromCache::class);

        $this->directory = $this->createMock(WriteInterface::class);
        $this->directory->method('isExist')->willReturnCallback(
            fn (string $p): bool => in_array($p, $this->existing, true)
                || in_array($p, $this->directories, true)
        );
        $this->directory->method('isFile')
            ->willReturnCallback(fn (string $p): bool => in_array($p, $this->existing, true));
        $this->directory->method('stat')->willReturnCallback(function (string $p): array {
            if (in_array($p, $this->unstattable, true)) {
                throw new FileSystemException(__('cannot stat'));
            }

            return ['size' => 1024, 'mtime' => $this->mtimes[$p] ?? time() - self::LONG_AGO];
        });
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
        self::assertSame([], $service->deleteAbandonedDownloads(['/a/b/x.jpg']));
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
     * WriteInterface::delete() removes a directory RECURSIVELY and guards only
     * the media root, so a stored path that names one would take the whole
     * subtree. The reference check cannot catch it either: no gallery row ever
     * equals a directory path, so every directory looks unreferenced.
     */
    public function testADirectoryIsRefusedRatherThanDeletedRecursively(): void
    {
        $this->directories = ['catalog/product/a'];
        $this->checker->method('getUnreferenced')->willReturn(['/a']);

        $logger = $this->createMock(Logger::class);
        $logger->expects(self::once())->method('warning');

        self::assertSame([], $this->service(null, $logger)->deleteUnreferenced(['/a']));
        self::assertSame([], $this->deleted);
    }

    public function testADirectoryIsRefusedOnTheAbandonedDownloadPathToo(): void
    {
        $this->directories = ['catalog/product/a'];
        $this->checker->method('getUnreferenced')->willReturn(['/a']);

        self::assertSame([], $this->service()->deleteAbandonedDownloads(['/a']));
        self::assertSame([], $this->deleted);
    }

    /**
     * The reference check answers for the instant it runs. A concurrent batch
     * that adopted this file during its unlocked prepare phase has not committed
     * its gallery row yet, so deleting now would leave that row pointing at
     * nothing — with no error anywhere.
     */
    public function testAFileWrittenInsideTheGracePeriodIsSpared(): void
    {
        $this->existing = ['catalog/product/a/b/fresh.jpg'];
        $this->mtimes = ['catalog/product/a/b/fresh.jpg' => time() - 60];
        $this->checker->method('getUnreferenced')->willReturn(['/a/b/fresh.jpg']);

        self::assertSame([], $this->service()->deleteUnreferenced(['/a/b/fresh.jpg']));
        self::assertSame([], $this->deleted);
    }

    public function testAFileOlderThanTheGracePeriodIsDeleted(): void
    {
        $this->existing = ['catalog/product/a/b/old.jpg'];
        $this->mtimes = [
            'catalog/product/a/b/old.jpg' => time() - MediaCleanupService::GRACE_PERIOD_SECONDS - 60,
        ];
        $this->checker->method('getUnreferenced')->willReturn(['/a/b/old.jpg']);

        self::assertSame(['/a/b/old.jpg'], $this->service()->deleteUnreferenced(['/a/b/old.jpg']));
    }

    /**
     * A rolled-back batch's downloads are seconds old by definition, so honouring
     * the grace period there would spare every one of them and clean up nothing.
     */
    public function testAbandonedDownloadsAreDeletedHoweverRecentlyTheyWereWritten(): void
    {
        $this->existing = ['catalog/product/a/b/fresh.jpg'];
        $this->mtimes = ['catalog/product/a/b/fresh.jpg' => time()];
        $this->checker->method('getUnreferenced')->willReturn(['/a/b/fresh.jpg']);

        self::assertSame(['/a/b/fresh.jpg'], $this->service()->deleteAbandonedDownloads(['/a/b/fresh.jpg']));
        self::assertSame(['catalog/product/a/b/fresh.jpg'], $this->deleted);
    }

    /**
     * An unreadable timestamp is not evidence of age. Same reading OrphanScanner
     * gives an mtime of 0 when it buckets it as `unknown` rather than as the
     * oldest files an operator would act on.
     */
    public function testAFileWhoseAgeCannotBeReadIsSpared(): void
    {
        $this->existing = ['catalog/product/a/b/x.jpg', 'catalog/product/a/b/y.jpg'];
        $this->unstattable = ['catalog/product/a/b/x.jpg'];
        $this->mtimes = ['catalog/product/a/b/y.jpg' => 0];
        $this->checker->method('getUnreferenced')->willReturn(['/a/b/x.jpg', '/a/b/y.jpg']);

        self::assertSame([], $this->service()->deleteUnreferenced(['/a/b/x.jpg', '/a/b/y.jpg']));
        self::assertSame([], $this->deleted);
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
