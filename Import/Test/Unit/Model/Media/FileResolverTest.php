<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Test\Unit\Model\Media;

use GuzzleHttp\Psr7\Utils;
use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Filesystem\DriverInterface;
use Magento\Framework\Filesystem\File\WriteInterface as FileWriteInterface;
use Magento\MediaStorage\Helper\File\Storage\Database as StorageDatabase;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReadyData\Import\Logger\Logger;
use ReadyData\Import\Model\Config;
use ReadyData\Import\Model\Media\DownloaderInterface;
use ReadyData\Import\Model\Media\FileResolver;
use ReadyData\Import\Model\Media\HostAllowList;

class FileResolverTest extends TestCase
{
    private const BASE = 'catalog/product';

    /** Smallest byte sequences that pass each signature check. */
    private const JPEG = "\xFF\xD8\xFFbody";
    private const PNG = "\x89PNG\r\n\x1A\nbody";

    /** sha1('https://cdn.example.com/img/hero.jpg') truncated as the resolver does. */
    private const HERO_URL = 'https://cdn.example.com/img/hero.jpg';

    private Filesystem&MockObject $filesystem;
    private WriteInterface&MockObject $directory;
    private DriverInterface&MockObject $driver;
    private MediaConfig&MockObject $mediaConfig;
    private DownloaderInterface&MockObject $downloader;
    private StorageDatabase&MockObject $storageDatabase;
    private Config&MockObject $config;
    private Logger&MockObject $logger;
    private FileResolver $resolver;

    /** @var array<string, string> path => bytes written through openFile() */
    private array $written = [];
    /** @var array<int, array{0: string, 1: string}> renameFile(from, to) calls */
    private array $renamed = [];
    /** @var string[] delete() calls */
    private array $deleted = [];
    /** @var array<string, string> path => contents, for isExist/readFile */
    private array $existing = [];

    protected function setUp(): void
    {
        $this->written = [];
        $this->renamed = [];
        $this->deleted = [];
        $this->existing = [];

        $this->driver = $this->createMock(DriverInterface::class);
        // By default every path resolves inside the product media directory.
        $this->driver->method('getRealPath')->willReturnArgument(0);

        $this->directory = $this->createMock(WriteInterface::class);
        $this->configureDirectory($this->directory);

        $this->filesystem = $this->createMock(Filesystem::class);
        $this->filesystem->method('getDirectoryWrite')->willReturn($this->directory);

        $this->mediaConfig = $this->createMock(MediaConfig::class);
        $this->mediaConfig->method('getBaseMediaPath')->willReturn(self::BASE);

        $this->downloader = $this->createMock(DownloaderInterface::class);

        $this->storageDatabase = $this->createMock(StorageDatabase::class);
        $this->storageDatabase->method('checkDbUsage')->willReturn(false);

        $this->config = $this->createMock(Config::class);
        $this->config->method('getMediaAllowedExtensions')->willReturn(['jpg', 'jpeg', 'png', 'gif', 'webp']);
        $this->config->method('getMediaAllowedHosts')->willReturn([]);
        $this->config->method('getMediaMaxFileSizeKb')->willReturn(10240);
        $this->config->method('isMediaRedownloadExisting')->willReturn(false);

        $this->logger = $this->createMock(Logger::class);

        $this->resolver = $this->resolverWith();
    }

    // ---------------------------------------------------------------- local

    public function testExistingLocalPathIsAccepted(): void
    {
        $this->existing[self::BASE . '/s/h/shirt.jpg'] = 'bytes';

        self::assertSame(
            // `downloaded` false is load-bearing, not incidental: a path that was
            // already on disk is not ours to withdraw, so a rolled-back batch
            // must leave it alone. See MediaProcessor::cleanUpAfterRollback().
            ['/s/h/shirt.jpg' => ['file' => '/s/h/shirt.jpg', 'message' => null, 'downloaded' => false]],
            $this->resolver->resolve(['/s/h/shirt.jpg'])
        );
    }

    /**
     * The other half of that distinction. A rolled-back batch discards only what
     * it actually fetched, so the flag has to be set on the download path and
     * nowhere else — if it were never true, rollback cleanup would silently do
     * nothing; if it were always true, it would delete files it did not create.
     */
    public function testOnlyAFetchedFileIsMarkedAsDownloaded(): void
    {
        $adopted = '/h/e/hero_' . substr(sha1(self::HERO_URL), 0, 8) . '.jpg';
        $this->existing[self::BASE . $adopted] = self::JPEG;
        $this->existing[self::BASE . '/s/h/shirt.jpg'] = 'bytes';
        $fetched = 'https://cdn.example.com/img/fresh.jpg';
        $this->stubDownload([$fetched => self::JPEG]);

        $results = $this->resolver->resolve([$fetched, self::HERO_URL, '/s/h/shirt.jpg']);

        self::assertTrue($results[$fetched]['downloaded'], 'a URL this call fetched');
        self::assertFalse($results[self::HERO_URL]['downloaded'], 'a URL skip-if-present adopted');
        self::assertFalse($results['/s/h/shirt.jpg']['downloaded'], 'a local path');
    }

    /**
     * A download that fails leaves nothing on disk, so there is nothing for a
     * rollback to clean up and the flag must not claim otherwise.
     */
    public function testAFailedDownloadIsNotMarkedAsDownloaded(): void
    {
        $this->stubDownload([self::HERO_URL => null], status: 404);

        $result = $this->resolver->resolve([self::HERO_URL])[self::HERO_URL];

        self::assertNull($result['file']);
        self::assertFalse($result['downloaded']);
    }

    /**
     * @dataProvider equivalentLocalReferenceProvider
     */
    public function testEquivalentLocalReferencesNormaliseToOnePath(string $reference): void
    {
        $this->existing[self::BASE . '/s/h/shirt.jpg'] = 'bytes';

        self::assertSame('/s/h/shirt.jpg', $this->resolver->resolve([$reference])[$reference]['file']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function equivalentLocalReferenceProvider(): array
    {
        return [
            'leading slash' => ['/s/h/shirt.jpg'],
            'no leading slash' => ['s/h/shirt.jpg'],
            'media-relative prefix' => ['catalog/product/s/h/shirt.jpg'],
            'absolute media prefix' => ['/catalog/product/s/h/shirt.jpg'],
            'backslashes' => ['\\s\\h\\shirt.jpg'],
        ];
    }

    public function testMissingLocalFileIsReportedNotThrown(): void
    {
        $result = $this->resolver->resolve(['/s/h/shirt.jpg']);

        self::assertNull($result['/s/h/shirt.jpg']['file']);
        self::assertStringContainsString('was not found', $result['/s/h/shirt.jpg']['message']);
    }

    public function testMissingLocalFileIsReportedAsMissingNotAsAContainmentViolation(): void
    {
        // realpath() cannot resolve a path that is not there, so the containment
        // check must not run first: a plain typo would be reported as a security
        // problem and send whoever reads the message on a hunt.
        $this->driver = $this->createMock(DriverInterface::class);
        $this->driver->method('getRealPath')->willReturn(false);
        $this->directory = $this->createMock(WriteInterface::class);
        $this->configureDirectory($this->directory);

        $result = $this->resolverWithDirectory($this->directory)->resolve(['/g/h/ghost.jpg']);

        self::assertStringContainsString('was not found', $result['/g/h/ghost.jpg']['message']);
    }

    /**
     * @dataProvider traversalProvider
     */
    public function testTraversalAndAbsoluteEscapesAreRejected(string $reference): void
    {
        $this->existing = ['any' => 'bytes'];

        $result = $this->resolver->resolve([$reference]);

        self::assertNull($result[$reference]['file']);
        self::assertNotNull($result[$reference]['message']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function traversalProvider(): array
    {
        return [
            'parent segments' => ['../../app/etc/env.php'],
            'embedded parent' => ['/a/../../b.jpg'],
            'current dir' => ['/a/./b.jpg'],
            'double slash' => ['//etc/passwd'],
            'nul byte' => ["/a/\0b.jpg"],
            'empty' => ['   '],
            'trailing slash' => ['/a/b/'],
        ];
    }

    /**
     * @dataProvider disallowedExtensionProvider
     */
    public function testDisallowedLocalExtensionIsRejected(string $reference): void
    {
        $result = $this->resolver->resolve([$reference]);

        self::assertNull($result[$reference]['file']);
        self::assertStringContainsString('extension that is not allowed', $result[$reference]['message']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function disallowedExtensionProvider(): array
    {
        return [
            'php' => ['/a/b/shell.php'],
            'phtml' => ['/a/b/shell.phtml'],
            'svg can carry script' => ['/a/b/logo.svg'],
            'no extension' => ['/a/b/image'],
        ];
    }

    public function testLocalPathResolvingOutsideTheMediaDirectoryIsRejected(): void
    {
        // A symlink out of the tree: the syntactic whitelist passes, the
        // containment check does not.
        $this->driver = $this->createMock(DriverInterface::class);
        $this->driver->method('getRealPath')->willReturnCallback(
            static fn (string $p): string => str_contains($p, 'escape') ? '/etc/passwd' : $p
        );
        $this->directory = $this->createMock(WriteInterface::class);
        $this->configureDirectory($this->directory);
        $this->existing[self::BASE . '/e/s/escape.jpg'] = 'bytes';

        $result = $this->resolverWithDirectory($this->directory)->resolve(['/e/s/escape.jpg']);

        self::assertNull($result['/e/s/escape.jpg']['file']);
        self::assertStringContainsString('resolves outside', $result['/e/s/escape.jpg']['message']);
    }

    // ----------------------------------------------------------- downloads

    public function testUrlIsDownloadedToItsHashedDispersedPath(): void
    {
        $url = 'https://cdn.example.com/img/Hero%20Shot.JPG';
        $this->stubDownload([$url => self::JPEG]);

        $expected = '/h/e/hero_20shot_' . substr(sha1($url), 0, 8) . '.jpg';

        self::assertSame($expected, $this->resolver->resolve([$url])[$url]['file']);
        // Written under a temporary name, then renamed: a killed process must
        // never leave a truncated file where skip-if-present would trust it.
        self::assertSame([self::BASE . $expected . '.rd-part' => self::JPEG], $this->written);
        self::assertSame([[self::BASE . $expected . '.rd-part', self::BASE . $expected]], $this->renamed);
    }

    public function testHashedNameIsStableAcrossRuns(): void
    {
        $this->stubDownload([self::HERO_URL => self::JPEG]);
        $first = $this->resolver->resolve([self::HERO_URL])[self::HERO_URL]['file'];

        $this->written = [];
        $this->renamed = [];
        $this->stubDownload([self::HERO_URL => self::JPEG]);
        $second = $this->resolver->resolve([self::HERO_URL])[self::HERO_URL]['file'];

        self::assertSame($first, $second);
    }

    public function testTwoUrlsSharingABasenameGetDistinctStablePaths(): void
    {
        // The bug this naming scheme exists to prevent: with a shared
        // "/h/e/hero.jpg" the second product silently adopts the first
        // product's image on the next run, via skip-if-present.
        $a = 'https://a.example.com/img/hero.jpg';
        $b = 'https://b.example.com/img/hero.jpg';
        $bytesB = self::JPEG . 'a different picture';

        $fetched = 0;
        $this->downloader->method('fetchAll')->willReturnCallback(
            function (array $urls, callable $onEach) use ($a, $bytesB, &$fetched): void {
                foreach ($urls as $key => $url) {
                    $fetched++;
                    $onEach($key, Utils::streamFor($url === $a ? self::JPEG : $bytesB), 200, null);
                }
            }
        );

        $first = $this->resolver->resolve([$a, $b]);

        self::assertSame(2, $fetched);
        self::assertNotSame($first[$a]['file'], $first[$b]['file']);

        // Second run: both files are published, so neither is fetched and each
        // reference still resolves to its own path.
        $this->existing[self::BASE . $first[$a]['file']] = self::JPEG;
        $this->existing[self::BASE . $first[$b]['file']] = $bytesB;

        $second = $this->resolver->resolve([$a, $b]);

        self::assertSame(2, $fetched, 'a published image must never be fetched again');

        self::assertSame($first[$a]['file'], $second[$a]['file']);
        self::assertSame($first[$b]['file'], $second[$b]['file']);
    }

    public function testOnlyUrlsThatNeedFetchingReachTheDownloader(): void
    {
        $published = 'https://cdn.example.com/img/published.jpg';
        $fresh = 'https://cdn.example.com/img/fresh.jpg';
        $this->existing[self::BASE . '/p/u/published_' . substr(sha1($published), 0, 8) . '.jpg'] = self::JPEG;

        $requested = null;
        $this->downloader->expects(self::once())->method('fetchAll')
            ->willReturnCallback(function (array $urls, callable $onEach) use (&$requested): void {
                $requested = $urls;
                foreach ($urls as $key => $url) {
                    $onEach($key, Utils::streamFor(self::JPEG), 200, null);
                }
            });

        $this->resolver->resolve([$published, '/s/h/local.jpg', $fresh]);

        self::assertSame([$fresh => $fresh], $requested);
    }

    public function testExistingTargetIsNotDownloadedAgain(): void
    {
        $this->existing[self::BASE . '/h/e/hero_' . substr(sha1(self::HERO_URL), 0, 8) . '.jpg'] = self::JPEG;

        $this->downloader->expects(self::never())->method('fetchAll');

        self::assertSame(
            '/h/e/hero_' . substr(sha1(self::HERO_URL), 0, 8) . '.jpg',
            $this->resolver->resolve([self::HERO_URL])[self::HERO_URL]['file']
        );
    }

    public function testRedownloadOfIdenticalBytesLeavesThePublishedFileAlone(): void
    {
        $resolver = $this->resolverWithConfig($this->configWithRedownload());
        $target = '/h/e/hero_' . substr(sha1(self::HERO_URL), 0, 8) . '.jpg';
        $this->existing[self::BASE . $target] = self::JPEG;
        $this->stubDownload([self::HERO_URL => self::JPEG]);

        self::assertSame($target, $resolver->resolve([self::HERO_URL])[self::HERO_URL]['file']);
        // The part file is written, found identical, and discarded.
        self::assertSame([], $this->renamed);
        self::assertSame([self::BASE . $target . '.rd-part'], $this->deleted);
    }

    public function testRedownloadOfChangedBytesReplacesTheFileAndWarns(): void
    {
        $resolver = $this->resolverWithConfig($this->configWithRedownload());
        $target = '/h/e/hero_' . substr(sha1(self::HERO_URL), 0, 8) . '.jpg';
        $this->existing[self::BASE . $target] = 'stale bytes';
        $this->stubDownload([self::HERO_URL => self::JPEG]);

        $this->logger->expects(self::once())->method('warning')
            ->with(self::stringContains('renditions'));

        self::assertSame($target, $resolver->resolve([self::HERO_URL])[self::HERO_URL]['file']);
        self::assertSame([[self::BASE . $target . '.rd-part', self::BASE . $target]], $this->renamed);
    }

    public function testNonOkStatusIsReported(): void
    {
        $this->stubDownload([self::HERO_URL => self::JPEG], 404);

        $result = $this->resolver->resolve([self::HERO_URL]);

        self::assertNull($result[self::HERO_URL]['file']);
        self::assertStringContainsString('HTTP 404', $result[self::HERO_URL]['message']);
        self::assertSame([], $this->written);
    }

    public function testTransportErrorIsReported(): void
    {
        $this->downloader->method('fetchAll')->willReturnCallback(
            static function (array $urls, callable $onEach): void {
                foreach (array_keys($urls) as $key) {
                    $onEach($key, null, 0, new \RuntimeException('Connection refused'));
                }
            }
        );

        $result = $this->resolver->resolve([self::HERO_URL]);

        self::assertNull($result[self::HERO_URL]['file']);
        self::assertStringContainsString('Connection refused', $result[self::HERO_URL]['message']);
    }

    public function testContentNotMatchingTheClaimedTypeIsNeverWritten(): void
    {
        // A PHP payload wearing a .jpg name.
        $this->stubDownload([self::HERO_URL => '<?php echo "pwned";']);

        $result = $this->resolver->resolve([self::HERO_URL]);

        self::assertNull($result[self::HERO_URL]['file']);
        self::assertStringContainsString('is not a valid jpg image', $result[self::HERO_URL]['message']);
        self::assertSame([], $this->written);
        self::assertSame([], $this->renamed);
    }

    public function testExtensionAndContentMustAgree(): void
    {
        $url = 'https://cdn.example.com/img/hero.png';
        $this->stubDownload([$url => self::PNG]);

        self::assertSame(
            '/h/e/hero_' . substr(sha1($url), 0, 8) . '.png',
            $this->resolver->resolve([$url])[$url]['file']
        );
    }

    public function testWebpRequiresTheRiffFormMarker(): void
    {
        $url = 'https://cdn.example.com/img/hero.webp';
        $this->stubDownload([$url => 'RIFF' . '1234' . 'AVI body']);

        self::assertNull($this->resolver->resolve([$url])[$url]['file']);
    }

    public function testEmptyBodyIsRejected(): void
    {
        $this->stubDownload([self::HERO_URL => '']);

        self::assertStringContainsString(
            'no content',
            $this->resolver->resolve([self::HERO_URL])[self::HERO_URL]['message']
        );
    }

    public function testOversizeBodyIsStoppedMidStreamAndThePartFileRemoved(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getMediaAllowedExtensions')->willReturn(['jpg']);
        $config->method('getMediaAllowedHosts')->willReturn([]);
        $config->method('getMediaMaxFileSizeKb')->willReturn(1);
        $config->method('isMediaRedownloadExisting')->willReturn(false);
        $resolver = $this->resolverWithConfig($config);

        // A lying origin: no Content-Length, oversize body.
        $this->stubDownload([self::HERO_URL => self::JPEG . str_repeat('x', 4096)]);

        $result = $resolver->resolve([self::HERO_URL]);

        self::assertNull($result[self::HERO_URL]['file']);
        self::assertStringContainsString('exceeds the', $result[self::HERO_URL]['message']);
        self::assertSame([], $this->renamed);
        self::assertCount(1, $this->deleted, 'the partial file must be removed');
    }

    public function testLongFileNamesStayWithinTheFilesystemLimit(): void
    {
        $url = 'https://cdn.example.com/img/' . str_repeat('a', 400) . '.jpg';
        $this->stubDownload([$url => self::JPEG]);

        $file = $this->resolver->resolve([$url])[$url]['file'];

        self::assertNotNull($file);
        self::assertLessThanOrEqual(255, strlen(basename($file)));
        self::assertStringEndsWith('_' . substr(sha1($url), 0, 8) . '.jpg', $file);
    }

    public function testHostAllowListIsEnforcedWhenConfigured(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getMediaAllowedExtensions')->willReturn(['jpg']);
        $config->method('getMediaAllowedHosts')->willReturn(['cdn.example.com']);
        $config->method('getMediaMaxFileSizeKb')->willReturn(10240);
        $config->method('isMediaRedownloadExisting')->willReturn(false);
        $resolver = $this->resolverWithConfig($config);

        $this->downloader->expects(self::never())->method('fetchAll');

        $url = 'http://169.254.169.254/latest/meta-data/hero.jpg';
        $result = $resolver->resolve([$url]);

        self::assertNull($result[$url]['file']);
        self::assertStringContainsString('not in the allowed download hosts', $result[$url]['message']);
    }

    /**
     * @dataProvider unusableUrlProvider
     */
    public function testUnusableUrlsAreReported(string $url, string $expected): void
    {
        $this->downloader->expects(self::never())->method('fetchAll');

        $result = $this->resolver->resolve([$url]);

        self::assertNull($result[$url]['file']);
        self::assertStringContainsString($expected, $result[$url]['message']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unusableUrlProvider(): array
    {
        return [
            // Non-http schemes never reach the URL branch: they are treated as
            // paths, and no path may contain a colon.
            'ftp' => ['ftp://example.com/hero.jpg', 'not a valid path'],
            'file' => ['file:///etc/passwd', 'not a valid path'],
            'javascript' => ['javascript:alert(1)', 'not a valid path'],
            'host only' => ['https://example.com', 'could not be parsed'],
            'directory url' => ['https://example.com/img/', 'no usable file name'],
            'extension without a signature' => ['https://example.com/img/hero.gif2', 'not allowed'],
        ];
    }

    public function testExtensionAllowedButUnsniffableIsRefusedForDownloads(): void
    {
        $config = $this->createMock(Config::class);
        // A merchant widened the allow-list to a format we cannot verify.
        $config->method('getMediaAllowedExtensions')->willReturn(['jpg', 'svg']);
        $config->method('getMediaAllowedHosts')->willReturn([]);
        $config->method('getMediaMaxFileSizeKb')->willReturn(10240);
        $config->method('isMediaRedownloadExisting')->willReturn(false);
        $resolver = $this->resolverWithConfig($config);

        $this->downloader->expects(self::never())->method('fetchAll');

        $url = 'https://cdn.example.com/img/logo.svg';
        $result = $resolver->resolve([$url]);

        self::assertNull($result[$url]['file']);
        self::assertStringContainsString('no known image signature', $result[$url]['message']);
    }

    public function testUnexpectedFailureIsCaughtAndLogged(): void
    {
        $this->downloader->method('fetchAll')->willReturnCallback(
            static function (array $urls, callable $onEach): void {
                foreach (array_keys($urls) as $key) {
                    $onEach($key, Utils::streamFor(self::JPEG), 200, null);
                }
            }
        );
        $this->directory->method('openFile')->willThrowException(new \RuntimeException('disk is full'));

        $this->logger->expects(self::once())->method('warning');

        $result = $this->resolver->resolve([self::HERO_URL]);

        self::assertNull($result[self::HERO_URL]['file']);
        self::assertStringContainsString('disk is full', $result[self::HERO_URL]['message']);
    }

    public function testDatabaseMediaStorageRefusesEverything(): void
    {
        $storageDatabase = $this->createMock(StorageDatabase::class);
        $storageDatabase->method('checkDbUsage')->willReturn(true);
        $resolver = new FileResolver(
            $this->filesystem,
            $this->mediaConfig,
            $this->downloader,
            $storageDatabase,
            $this->config,
            new HostAllowList($this->config),
            $this->logger
        );

        $this->downloader->expects(self::never())->method('fetchAll');
        $this->logger->expects(self::once())->method('error');

        $result = $resolver->resolve(['/s/h/shirt.jpg', self::HERO_URL]);

        self::assertNull($result['/s/h/shirt.jpg']['file']);
        self::assertStringContainsString('database media storage', $result['/s/h/shirt.jpg']['message']);
    }

    // ------------------------------------------------------------- helpers

    /**
     * Make the downloader hand each URL the given bytes.
     *
     * @param array<string, string> $bodies url => bytes
     */
    private function stubDownload(array $bodies, int $status = 200): void
    {
        $this->downloader->method('fetchAll')->willReturnCallback(
            static function (array $urls, callable $onEach) use ($bodies, $status): void {
                foreach ($urls as $key => $url) {
                    $onEach($key, Utils::streamFor($bodies[$url] ?? ''), $status, null);
                }
            }
        );
    }

    private function configureDirectory(WriteInterface&MockObject $directory): void
    {
        $directory->method('getDriver')->willReturn($this->driver);
        $directory->method('getAbsolutePath')
            ->willReturnCallback(static fn (string $p): string => '/var/www/pub/media/' . ltrim($p, '/'));
        $directory->method('isExist')
            ->willReturnCallback(fn (string $p): bool => isset($this->existing[$p]));
        $directory->method('isFile')
            ->willReturnCallback(fn (string $p): bool => isset($this->existing[$p]));
        $directory->method('readFile')
            ->willReturnCallback(fn (string $p): string => $this->existing[$p] ?? '');
        $directory->method('renameFile')
            ->willReturnCallback(function (string $from, string $to): bool {
                $this->renamed[] = [$from, $to];
                return true;
            });
        $directory->method('delete')
            ->willReturnCallback(function (string $p): bool {
                $this->deleted[] = $p;
                unset($this->existing[$p]);
                return true;
            });
        $directory->method('openFile')
            ->willReturnCallback(function (string $path): FileWriteInterface {
                $this->written[$path] = '';
                $file = $this->createMock(FileWriteInterface::class);
                $file->method('write')->willReturnCallback(function (string $data) use ($path): int {
                    $this->written[$path] .= $data;
                    // A part file exists as soon as it is opened, so the cleanup
                    // path can find and remove it.
                    $this->existing[$path] = $this->written[$path];
                    return strlen($data);
                });

                return $file;
            });
    }

    private function configWithRedownload(): Config&MockObject
    {
        $config = $this->createMock(Config::class);
        $config->method('getMediaAllowedExtensions')->willReturn(['jpg', 'jpeg', 'png']);
        $config->method('getMediaAllowedHosts')->willReturn([]);
        $config->method('getMediaMaxFileSizeKb')->willReturn(10240);
        $config->method('isMediaRedownloadExisting')->willReturn(true);

        return $config;
    }

    private function resolverWith(): FileResolver
    {
        return new FileResolver(
            $this->filesystem,
            $this->mediaConfig,
            $this->downloader,
            $this->storageDatabase,
            $this->config,
            new HostAllowList($this->config),
            $this->logger
        );
    }

    private function resolverWithConfig(Config $config): FileResolver
    {
        return new FileResolver(
            $this->filesystem,
            $this->mediaConfig,
            $this->downloader,
            $this->storageDatabase,
            $config,
            new HostAllowList($config),
            $this->logger
        );
    }

    private function resolverWithDirectory(WriteInterface $directory): FileResolver
    {
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->willReturn($directory);

        return new FileResolver(
            $filesystem,
            $this->mediaConfig,
            $this->downloader,
            $this->storageDatabase,
            $this->config,
            new HostAllowList($this->config),
            $this->logger
        );
    }
}
