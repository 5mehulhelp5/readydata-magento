<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Test\Unit\Model\Media\Cleanup;

use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Filesystem\DriverInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReadyData\Import\Logger\Logger;
use ReadyData\Import\Model\Media\Cleanup\FileWalker;
use ReadyData\Import\Model\Media\Cleanup\MediaPathNormalizer;

/**
 * Drives the walker over an in-memory tree, in the style of FileResolverTest:
 * the directory mock is backed by closures over an array rather than a
 * sequence of individual expectations.
 */
class FileWalkerTest extends TestCase
{
    private const BASE = 'catalog/product';

    /** @var array<string, string|array{size: int, mtime: int}> path => 'dir' or file metadata */
    private array $tree = [];

    /** @var array<string, string> absolute path => resolved real path, for symlinks */
    private array $realPaths = [];

    /** @var string[] paths that throw when probed, i.e. deleted mid-walk */
    private array $vanished = [];

    /** @var string[] every path stat() was called on */
    private array $statted = [];

    public function testDispersedFilesAreEmittedInCanonicalForm(): void
    {
        $this->tree = $this->directories(['a', 'a/b']) + [
            self::BASE . '/a/b/one.jpg' => ['size' => 100, 'mtime' => 1700000000],
            self::BASE . '/a/b/two.jpg' => ['size' => 200, 'mtime' => 1700000000],
        ];

        [$totals, $emitted] = $this->walk();

        self::assertSame(['/a/b/one.jpg', '/a/b/two.jpg'], $emitted);
        self::assertSame(['files' => 2, 'bytes' => 300], $totals['included']);
        self::assertSame(2, $totals['dispersed']);
    }

    public function testExcludedDirectoriesAreSizedButProduceNoCandidates(): void
    {
        $this->tree = $this->directories(['a', 'a/b', 'cache', 'cache/x', 'watermark', 'placeholder']) + [
            self::BASE . '/a/b/one.jpg' => ['size' => 10, 'mtime' => 1],
            self::BASE . '/cache/x/r.jpg' => ['size' => 1000, 'mtime' => 1],
            self::BASE . '/watermark/w.png' => ['size' => 30, 'mtime' => 1],
            self::BASE . '/placeholder/p.jpg' => ['size' => 40, 'mtime' => 1],
        ];

        [$totals, $emitted] = $this->walk();

        self::assertSame(['/a/b/one.jpg'], $emitted);
        self::assertSame(['files' => 1, 'bytes' => 1000], $totals['excluded']['cache']);
        self::assertSame(['files' => 1, 'bytes' => 30], $totals['excluded']['watermark']);
        self::assertSame(['files' => 1, 'bytes' => 40], $totals['excluded']['placeholder']);
    }

    public function testExcludedDirectoriesAreNotVisitedAtAllWhenSizingIsSkipped(): void
    {
        $this->tree = $this->directories(['a', 'a/b', 'cache', 'cache/x']) + [
            self::BASE . '/a/b/one.jpg' => ['size' => 10, 'mtime' => 1],
            self::BASE . '/cache/x/r.jpg' => ['size' => 1000, 'mtime' => 1],
        ];

        [$totals, $emitted] = $this->walk(false);

        self::assertSame(['/a/b/one.jpg'], $emitted);
        self::assertSame(['files' => 0, 'bytes' => 0], $totals['excluded']['cache']);
        self::assertNotContains(self::BASE . '/cache/x/r.jpg', $this->statted);
    }

    /**
     * The exclusion is a top-level segment, not a name match anywhere. A nested
     * directory called "cache" is somebody's images, and a file called
     * "cache.jpg" is certainly not a rendition.
     */
    public function testExclusionMatchesOnlyTheTopLevelSegment(): void
    {
        $this->tree = $this->directories(['a', 'a/cache', 'c', 'c/a']) + [
            self::BASE . '/a/cache/deep.jpg' => ['size' => 11, 'mtime' => 1],
            self::BASE . '/c/a/cache.jpg' => ['size' => 12, 'mtime' => 1],
        ];

        [$totals, $emitted] = $this->walk();

        self::assertSame(['/a/cache/deep.jpg', '/c/a/cache.jpg'], $emitted);
        self::assertSame(['files' => 0, 'bytes' => 0], $totals['excluded']['cache']);
    }

    /**
     * A gallery row's stored value may be any relative path, so a walker that
     * only looked at depth 2 would report referenced files as missing — the
     * failure the trust guard is there to catch, caused by us.
     */
    public function testFilesAtUnexpectedDepthsAreStillCandidates(): void
    {
        $this->tree = $this->directories(['a', 'a/b', 'a/b/c']) + [
            self::BASE . '/loose.jpg' => ['size' => 5, 'mtime' => 1],
            self::BASE . '/a/mid.jpg' => ['size' => 6, 'mtime' => 1],
            self::BASE . '/a/b/c/deep.jpg' => ['size' => 7, 'mtime' => 1],
        ];

        [$totals, $emitted] = $this->walk();

        self::assertSame(['/a/b/c/deep.jpg', '/a/mid.jpg', '/loose.jpg'], $emitted);
        self::assertSame(3, $totals['included']['files']);
        self::assertSame(0, $totals['dispersed']);
    }

    /**
     * SQL_MODE is empty on Magento's connection, so an over-long path would be
     * truncated into the candidate primary key rather than rejected — colliding
     * with its neighbours and matching no reference. Counted instead.
     */
    public function testAnOversizedPathIsSegregatedRatherThanEmitted(): void
    {
        $long = str_repeat('z', 260) . '.jpg';
        $this->tree = $this->directories(['a', 'a/b']) + [
            self::BASE . '/a/b/ok.jpg' => ['size' => 1, 'mtime' => 1],
            self::BASE . '/a/b/' . $long => ['size' => 2, 'mtime' => 1],
        ];

        [$totals, $emitted] = $this->walk();

        self::assertSame(['/a/b/ok.jpg'], $emitted);
        self::assertSame(1, $totals['skipped']['too_long']);
        self::assertSame(1, $totals['included']['bytes']);
    }

    public function testAFileThatVanishesMidWalkIsCountedNotFatal(): void
    {
        $this->tree = $this->directories(['a', 'a/b']) + [
            self::BASE . '/a/b/here.jpg' => ['size' => 1, 'mtime' => 1],
            self::BASE . '/a/b/gone.jpg' => ['size' => 2, 'mtime' => 1],
        ];
        $this->vanished = [self::BASE . '/a/b/gone.jpg'];

        [$totals, $emitted] = $this->walk();

        self::assertSame(['/a/b/here.jpg'], $emitted);
        self::assertSame(1, $totals['skipped']['vanished']);
    }

    public function testADirectorySymlinkOutOfTheMediaTreeIsSkipped(): void
    {
        $this->tree = $this->directories(['a', 'a/b', 'evil']) + [
            self::BASE . '/a/b/in.jpg' => ['size' => 1, 'mtime' => 1],
            self::BASE . '/evil/x.jpg' => ['size' => 9, 'mtime' => 1],
        ];
        $this->realPaths = ['/var/www/pub/media/' . self::BASE . '/evil' => '/etc'];

        [$totals, $emitted] = $this->walk();

        self::assertSame(['/a/b/in.jpg'], $emitted);
        self::assertSame(1, $totals['skipped']['outside_tree']);
    }

    /**
     * Without the visited set this recurses until the depth cap, counting the
     * same bytes over and over.
     */
    public function testASymlinkLoopIsVisitedOnce(): void
    {
        $this->tree = $this->directories(['a', 'a/b', 'link']) + [
            self::BASE . '/a/b/one.jpg' => ['size' => 1, 'mtime' => 1],
            self::BASE . '/link/one.jpg' => ['size' => 1, 'mtime' => 1],
        ];
        $this->realPaths = [
            '/var/www/pub/media/' . self::BASE . '/link' => '/var/www/pub/media/' . self::BASE . '/a',
        ];

        [$totals, $emitted] = $this->walk();

        self::assertSame(['/a/b/one.jpg'], $emitted);
        self::assertSame(1, $totals['included']['files']);
    }

    public function testAMissingProductDirectoryYieldsEmptyTotalsNotAnException(): void
    {
        $this->tree = [];

        [$totals, $emitted] = $this->walk();

        self::assertSame([], $emitted);
        self::assertSame(['files' => 0, 'bytes' => 0], $totals['included']);
    }

    public function testBatchesAreFlushedAtTheLimitAndAgainAtTheEnd(): void
    {
        $this->tree = $this->directories(['a', 'a/b']);
        for ($i = 0; $i < 7; $i++) {
            $this->tree[self::BASE . '/a/b/f' . $i . '.jpg'] = ['size' => 1, 'mtime' => 1];
        }

        [, $emitted, $batchSizes] = $this->walk(true, 3);

        self::assertSame([3, 3, 1], $batchSizes);
        self::assertCount(7, $emitted);
    }

    /**
     * @return array{0: array<string, mixed>, 1: string[], 2: int[]} totals, canonical paths (sorted), batch sizes
     */
    private function walk(bool $sizeExcluded = true, int $batchSize = 1000): array
    {
        $mediaConfig = $this->createMock(MediaConfig::class);
        $mediaConfig->method('getBaseMediaPath')->willReturn(self::BASE);

        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->willReturn($this->directoryMock());

        $walker = new FileWalker(
            $filesystem,
            $mediaConfig,
            new MediaPathNormalizer($mediaConfig),
            $this->createMock(Logger::class)
        );

        $emitted = [];
        $batchSizes = [];
        $totals = $walker->walk(
            function (array $batch) use (&$emitted, &$batchSizes): void {
                $batchSizes[] = count($batch);
                foreach ($batch as $row) {
                    $emitted[] = $row['path'];
                }
            },
            $sizeExcluded,
            $batchSize
        );
        sort($emitted);

        return [$totals, $emitted, $batchSizes];
    }

    private function directoryMock(): WriteInterface&MockObject
    {
        $driver = $this->createMock(DriverInterface::class);
        $driver->method('getRealPath')
            ->willReturnCallback(fn (string $path): string => $this->realPaths[$path] ?? $path);

        $directory = $this->createMock(WriteInterface::class);
        $directory->method('getDriver')->willReturn($driver);
        $directory->method('getAbsolutePath')
            ->willReturnCallback(static fn (string $p): string => '/var/www/pub/media/' . ltrim($p, '/'));
        $directory->method('isExist')->willReturnCallback(fn (string $p): bool => isset($this->tree[$p]));
        $directory->method('read')->willReturnCallback(function (string $path): array {
            $prefix = rtrim($path, '/') . '/';
            $entries = [];
            foreach (array_keys($this->tree) as $candidate) {
                if (str_starts_with($candidate, $prefix)
                    && !str_contains(substr($candidate, strlen($prefix)), '/')
                ) {
                    $entries[] = $candidate;
                }
            }
            sort($entries);

            return $entries;
        });
        $directory->method('isDirectory')->willReturnCallback(function (string $path): bool {
            if (in_array($path, $this->vanished, true)) {
                throw new FileSystemException(__('gone'));
            }

            return ($this->tree[$path] ?? null) === 'dir';
        });
        $directory->method('stat')->willReturnCallback(function (string $path): array {
            $this->statted[] = $path;
            if (in_array($path, $this->vanished, true)) {
                throw new FileSystemException(__('gone'));
            }

            return $this->tree[$path];
        });

        return $directory;
    }

    /**
     * @param string[] $relative directories under the base path, parents first
     * @return array<string, string>
     */
    private function directories(array $relative): array
    {
        $tree = [self::BASE => 'dir'];
        foreach ($relative as $path) {
            $tree[self::BASE . '/' . $path] = 'dir';
        }

        return $tree;
    }
}
