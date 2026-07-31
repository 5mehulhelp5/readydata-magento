<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Test\Unit\Model\Media;

use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Filesystem\DriverInterface;
use Magento\Framework\HTTP\ClientFactory;
use Magento\Framework\HTTP\ClientInterface;
use Magento\MediaStorage\Helper\File\Storage\Database as StorageDatabase;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReadyData\Import\Logger\Logger;
use ReadyData\Import\Model\Config;
use ReadyData\Import\Model\Media\FileResolver;

class FileResolverTest extends TestCase
{
    private const BASE = 'catalog/product';

    /** Smallest byte sequences that pass each signature check. */
    private const JPEG = "\xFF\xD8\xFFbody";
    private const PNG = "\x89PNG\r\n\x1A\nbody";

    private Filesystem&MockObject $filesystem;
    private WriteInterface&MockObject $directory;
    private DriverInterface&MockObject $driver;
    private MediaConfig&MockObject $mediaConfig;
    private ClientFactory&MockObject $clientFactory;
    private ClientInterface&MockObject $client;
    private StorageDatabase&MockObject $storageDatabase;
    private Config&MockObject $config;
    private Logger&MockObject $logger;
    private FileResolver $resolver;

    protected function setUp(): void
    {
        $this->driver = $this->createMock(DriverInterface::class);
        $this->directory = $this->createMock(WriteInterface::class);
        $this->directory->method('getDriver')->willReturn($this->driver);
        $this->directory->method('getAbsolutePath')
            ->willReturnCallback(static fn (string $p): string => '/var/www/pub/media/' . ltrim($p, '/'));
        // By default every path resolves inside the product media directory.
        $this->driver->method('getRealPath')->willReturnArgument(0);

        $this->filesystem = $this->createMock(Filesystem::class);
        $this->filesystem->method('getDirectoryWrite')->willReturn($this->directory);

        $this->mediaConfig = $this->createMock(MediaConfig::class);
        $this->mediaConfig->method('getBaseMediaPath')->willReturn(self::BASE);

        $this->client = $this->createMock(ClientInterface::class);
        $this->clientFactory = $this->createMock(ClientFactory::class);
        $this->clientFactory->method('create')->willReturn($this->client);

        $this->storageDatabase = $this->createMock(StorageDatabase::class);
        $this->storageDatabase->method('checkDbUsage')->willReturn(false);

        $this->config = $this->createMock(Config::class);
        $this->config->method('getMediaAllowedExtensions')->willReturn(['jpg', 'jpeg', 'png', 'gif', 'webp']);
        $this->config->method('getMediaAllowedHosts')->willReturn([]);
        $this->config->method('getMediaDownloadTimeout')->willReturn(15);
        $this->config->method('getMediaMaxFileSizeKb')->willReturn(10240);
        $this->config->method('isMediaRedownloadExisting')->willReturn(false);

        $this->logger = $this->createMock(Logger::class);

        $this->resolver = new FileResolver(
            $this->filesystem,
            $this->mediaConfig,
            $this->clientFactory,
            $this->storageDatabase,
            $this->config,
            $this->logger
        );
    }

    public function testExistingLocalPathIsAccepted(): void
    {
        $this->directory->method('isExist')->with(self::BASE . '/s/h/shirt.jpg')->willReturn(true);
        $this->directory->method('isFile')->willReturn(true);

        self::assertSame(
            ['/s/h/shirt.jpg' => ['file' => '/s/h/shirt.jpg', 'message' => null]],
            $this->resolver->resolve(['/s/h/shirt.jpg'])
        );
    }

    /**
     * @dataProvider equivalentLocalReferenceProvider
     */
    public function testEquivalentLocalReferencesNormaliseToOnePath(string $reference): void
    {
        $this->directory->method('isExist')->willReturn(true);
        $this->directory->method('isFile')->willReturn(true);

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
        $this->directory->method('isExist')->willReturn(false);

        $result = $this->resolver->resolve(['/s/h/shirt.jpg']);

        self::assertNull($result['/s/h/shirt.jpg']['file']);
        self::assertStringContainsString('was not found', $result['/s/h/shirt.jpg']['message']);
    }

    public function testMissingLocalFileIsReportedAsMissingNotAsAContainmentViolation(): void
    {
        // realpath() cannot resolve a path that is not there, so the containment
        // check must not run first: a plain typo would be reported as a security
        // problem and send whoever reads the message on a hunt.
        $driver = $this->createMock(DriverInterface::class);
        $driver->method('getRealPath')->willReturnCallback(
            static fn (string $p): string|false => str_contains($p, 'ghost') ? false : $p
        );
        $directory = $this->createMock(WriteInterface::class);
        $directory->method('getDriver')->willReturn($driver);
        $directory->method('getAbsolutePath')
            ->willReturnCallback(static fn (string $p): string => '/var/www/pub/media/' . ltrim($p, '/'));
        $directory->method('isExist')->willReturn(false);

        $result = $this->resolverWithDirectory($directory)->resolve(['/g/h/ghost.jpg']);

        self::assertStringContainsString('was not found', $result['/g/h/ghost.jpg']['message']);
    }

    /**
     * @dataProvider traversalProvider
     */
    public function testTraversalAndAbsoluteEscapesAreRejected(string $reference): void
    {
        $this->directory->method('isExist')->willReturn(true);
        $this->directory->method('isFile')->willReturn(true);

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
        $this->directory->method('isExist')->willReturn(true);
        $this->directory->method('isFile')->willReturn(true);

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
        $driver = $this->createMock(DriverInterface::class);
        // A symlink out of the tree: the syntactic whitelist passes, the
        // containment check does not.
        $driver->method('getRealPath')->willReturnCallback(
            static fn (string $p): string => str_contains($p, 'escape') ? '/etc/passwd' : $p
        );
        $this->directory = $this->createMock(WriteInterface::class);
        $this->directory->method('getDriver')->willReturn($driver);
        $this->directory->method('getAbsolutePath')
            ->willReturnCallback(static fn (string $p): string => '/var/www/pub/media/' . ltrim($p, '/'));
        $this->directory->method('isExist')->willReturn(true);
        $this->directory->method('isFile')->willReturn(true);
        $resolver = $this->resolverWithDirectory($this->directory);

        $result = $resolver->resolve(['/e/s/escape.jpg']);

        self::assertNull($result['/e/s/escape.jpg']['file']);
        self::assertStringContainsString('resolves outside', $result['/e/s/escape.jpg']['message']);
    }

    public function testUrlIsDownloadedToItsDispersedPath(): void
    {
        $url = 'https://cdn.example.com/img/Hero%20Shot.JPG';
        $this->directory->method('isExist')->willReturn(false);
        $this->client->method('getStatus')->willReturn(200);
        $this->client->method('getHeaders')->willReturn([]);
        $this->client->method('getBody')->willReturn(self::JPEG);

        $this->client->expects(self::once())->method('get')->with($url);
        $this->directory->expects(self::once())->method('create')
            ->with(self::BASE . '/h/e');
        $this->directory->expects(self::once())->method('writeFile')
            ->with(self::BASE . '/h/e/hero_20shot.jpg', self::JPEG);

        self::assertSame('/h/e/hero_20shot.jpg', $this->resolver->resolve([$url])[$url]['file']);
    }

    public function testExistingTargetIsNotDownloadedAgain(): void
    {
        $url = 'https://cdn.example.com/img/hero.jpg';
        $this->directory->method('isExist')->willReturn(true);

        // The whole point of skip-if-present: a re-import makes no request at all.
        $this->clientFactory->expects(self::never())->method('create');
        $this->directory->expects(self::never())->method('writeFile');

        self::assertSame('/h/e/hero.jpg', $this->resolver->resolve([$url])[$url]['file']);
    }

    public function testRedownloadOfIdenticalBytesLeavesTheFileAlone(): void
    {
        $config = $this->configWithRedownload();
        $resolver = $this->resolverWithConfig($config);

        $url = 'https://cdn.example.com/img/hero.jpg';
        $this->directory->method('isExist')->willReturn(true);
        $this->directory->method('readFile')->willReturn(self::JPEG);
        $this->client->method('getStatus')->willReturn(200);
        $this->client->method('getHeaders')->willReturn([]);
        $this->client->method('getBody')->willReturn(self::JPEG);

        $this->directory->expects(self::never())->method('writeFile');

        self::assertSame('/h/e/hero.jpg', $resolver->resolve([$url])[$url]['file']);
    }

    public function testDifferentBytesUnderTheSameNameGetAStableUrlDerivedSuffix(): void
    {
        $config = $this->configWithRedownload();
        $resolver = $this->resolverWithConfig($config);

        $url = 'https://cdn.example.com/img/hero.jpg';
        // Dispersion follows the new name, which still starts "he".
        $expected = '/h/e/hero_' . substr(sha1($url), 0, 8) . '.jpg';

        $this->directory->method('isExist')
            ->willReturnCallback(static fn (string $p): bool => !str_contains($p, '_'));
        $this->directory->method('readFile')->willReturn('other bytes');
        $this->client->method('getStatus')->willReturn(200);
        $this->client->method('getHeaders')->willReturn([]);
        $this->client->method('getBody')->willReturn(self::JPEG);

        $this->directory->expects(self::once())->method('writeFile')
            ->with(self::BASE . $expected, self::JPEG);

        self::assertSame($expected, $resolver->resolve([$url])[$url]['file']);
    }

    public function testNonOkStatusIsReported(): void
    {
        $url = 'https://cdn.example.com/img/hero.jpg';
        $this->directory->method('isExist')->willReturn(false);
        $this->client->method('getStatus')->willReturn(404);

        $result = $this->resolver->resolve([$url]);

        self::assertNull($result[$url]['file']);
        self::assertStringContainsString('HTTP 404', $result[$url]['message']);
    }

    public function testAnnouncedOversizeIsRejectedWithoutWriting(): void
    {
        $url = 'https://cdn.example.com/img/hero.jpg';
        $this->directory->method('isExist')->willReturn(false);
        $this->client->method('getStatus')->willReturn(200);
        $this->client->method('getHeaders')->willReturn(['content-length' => (string)(20 * 1024 * 1024)]);

        $this->directory->expects(self::never())->method('writeFile');

        $result = $this->resolver->resolve([$url]);

        self::assertNull($result[$url]['file']);
        self::assertStringContainsString('above the', $result[$url]['message']);
    }

    public function testOversizeBodyIsRejected(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getMediaAllowedExtensions')->willReturn(['jpg']);
        $config->method('getMediaAllowedHosts')->willReturn([]);
        $config->method('getMediaDownloadTimeout')->willReturn(15);
        $config->method('getMediaMaxFileSizeKb')->willReturn(1);
        $config->method('isMediaRedownloadExisting')->willReturn(false);
        $resolver = $this->resolverWithConfig($config);

        $url = 'https://cdn.example.com/img/hero.jpg';
        $this->directory->method('isExist')->willReturn(false);
        $this->client->method('getStatus')->willReturn(200);
        // A lying origin: no Content-Length, oversize body.
        $this->client->method('getHeaders')->willReturn([]);
        $this->client->method('getBody')->willReturn(self::JPEG . str_repeat('x', 2048));

        $this->directory->expects(self::never())->method('writeFile');

        self::assertNull($resolver->resolve([$url])[$url]['file']);
    }

    public function testEmptyBodyIsRejected(): void
    {
        $url = 'https://cdn.example.com/img/hero.jpg';
        $this->directory->method('isExist')->willReturn(false);
        $this->client->method('getStatus')->willReturn(200);
        $this->client->method('getHeaders')->willReturn([]);
        $this->client->method('getBody')->willReturn('');

        self::assertStringContainsString('no content', $this->resolver->resolve([$url])[$url]['message']);
    }

    public function testContentNotMatchingTheClaimedTypeIsNeverWritten(): void
    {
        $url = 'https://cdn.example.com/img/hero.jpg';
        $this->directory->method('isExist')->willReturn(false);
        $this->client->method('getStatus')->willReturn(200);
        $this->client->method('getHeaders')->willReturn([]);
        // A PHP payload wearing a .jpg name.
        $this->client->method('getBody')->willReturn('<?php echo "pwned";');

        $this->directory->expects(self::never())->method('writeFile');

        $result = $this->resolver->resolve([$url]);

        self::assertNull($result[$url]['file']);
        self::assertStringContainsString('is not a valid jpg image', $result[$url]['message']);
    }

    public function testExtensionAndContentMustAgree(): void
    {
        $url = 'https://cdn.example.com/img/hero.png';
        $this->directory->method('isExist')->willReturn(false);
        $this->client->method('getStatus')->willReturn(200);
        $this->client->method('getHeaders')->willReturn([]);
        $this->client->method('getBody')->willReturn(self::PNG);

        self::assertSame('/h/e/hero.png', $this->resolver->resolve([$url])[$url]['file']);
    }

    public function testWebpRequiresTheRiffFormMarker(): void
    {
        $url = 'https://cdn.example.com/img/hero.webp';
        $this->directory->method('isExist')->willReturn(false);
        $this->client->method('getStatus')->willReturn(200);
        $this->client->method('getHeaders')->willReturn([]);
        $this->client->method('getBody')->willReturn('RIFF' . '1234' . 'AVI body');

        self::assertNull($this->resolver->resolve([$url])[$url]['file']);
    }

    public function testTimeoutAndRedirectCapsComeFromConfiguration(): void
    {
        $url = 'https://cdn.example.com/img/hero.jpg';
        $this->directory->method('isExist')->willReturn(false);
        $this->client->method('getStatus')->willReturn(200);
        $this->client->method('getHeaders')->willReturn([]);
        $this->client->method('getBody')->willReturn(self::JPEG);

        $this->client->expects(self::once())->method('setTimeout')->with(15);
        $options = [];
        $this->client->method('setOption')->willReturnCallback(
            function (int $key, mixed $value) use (&$options): void {
                $options[$key] = $value;
            }
        );

        $this->resolver->resolve([$url]);

        self::assertSame(5, $options[CURLOPT_CONNECTTIMEOUT]);
        self::assertTrue($options[CURLOPT_FOLLOWLOCATION]);
        self::assertSame(3, $options[CURLOPT_MAXREDIRS]);
    }

    public function testHostAllowListIsEnforcedWhenConfigured(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getMediaAllowedExtensions')->willReturn(['jpg']);
        $config->method('getMediaAllowedHosts')->willReturn(['cdn.example.com']);
        $config->method('getMediaDownloadTimeout')->willReturn(15);
        $config->method('getMediaMaxFileSizeKb')->willReturn(10240);
        $config->method('isMediaRedownloadExisting')->willReturn(false);
        $resolver = $this->resolverWithConfig($config);

        $this->directory->method('isExist')->willReturn(false);
        $this->clientFactory->expects(self::never())->method('create');

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
        $this->directory->method('isExist')->willReturn(false);
        $this->directory->method('isFile')->willReturn(false);

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
            'extension without a signature' => [
                'https://example.com/img/hero.gif2',
                'not allowed',
            ],
        ];
    }

    public function testExtensionAllowedButUnsniffableIsRefusedForDownloads(): void
    {
        $config = $this->createMock(Config::class);
        // A merchant widened the allow-list to a format we cannot verify.
        $config->method('getMediaAllowedExtensions')->willReturn(['jpg', 'svg']);
        $config->method('getMediaAllowedHosts')->willReturn([]);
        $config->method('getMediaDownloadTimeout')->willReturn(15);
        $config->method('getMediaMaxFileSizeKb')->willReturn(10240);
        $config->method('isMediaRedownloadExisting')->willReturn(false);
        $resolver = $this->resolverWithConfig($config);

        $this->directory->method('isExist')->willReturn(false);
        $this->clientFactory->expects(self::never())->method('create');

        $url = 'https://cdn.example.com/img/logo.svg';
        $result = $resolver->resolve([$url]);

        self::assertNull($result[$url]['file']);
        self::assertStringContainsString('no known image signature', $result[$url]['message']);
    }

    public function testThrowingHttpClientIsCaughtAndLogged(): void
    {
        $url = 'https://cdn.example.com/img/hero.jpg';
        $this->directory->method('isExist')->willReturn(false);
        $this->client->method('get')->willThrowException(new \Exception('Error 28: Operation timed out'));

        $this->logger->expects(self::once())->method('warning');

        $result = $this->resolver->resolve([$url]);

        self::assertNull($result[$url]['file']);
        self::assertStringContainsString('Operation timed out', $result[$url]['message']);
    }

    public function testEachDistinctReferenceIsFetchedOnce(): void
    {
        $this->directory->method('isExist')->willReturn(false);
        $this->client->method('getStatus')->willReturn(200);
        $this->client->method('getHeaders')->willReturn([]);
        $this->client->method('getBody')->willReturn(self::JPEG);

        $this->clientFactory->expects(self::exactly(2))->method('create')->willReturn($this->client);

        $result = $this->resolver->resolve([
            'https://cdn.example.com/img/a.jpg',
            'https://cdn.example.com/img/b.jpg',
        ]);

        self::assertCount(2, $result);
    }

    public function testDatabaseMediaStorageRefusesEverything(): void
    {
        $storageDatabase = $this->createMock(StorageDatabase::class);
        $storageDatabase->method('checkDbUsage')->willReturn(true);
        $resolver = new FileResolver(
            $this->filesystem,
            $this->mediaConfig,
            $this->clientFactory,
            $storageDatabase,
            $this->config,
            $this->logger
        );

        $this->clientFactory->expects(self::never())->method('create');
        $this->logger->expects(self::once())->method('error');

        $result = $resolver->resolve(['/s/h/shirt.jpg', 'https://cdn.example.com/img/hero.jpg']);

        self::assertNull($result['/s/h/shirt.jpg']['file']);
        self::assertStringContainsString('database media storage', $result['/s/h/shirt.jpg']['message']);
    }

    private function configWithRedownload(): Config&MockObject
    {
        $config = $this->createMock(Config::class);
        $config->method('getMediaAllowedExtensions')->willReturn(['jpg', 'jpeg', 'png']);
        $config->method('getMediaAllowedHosts')->willReturn([]);
        $config->method('getMediaDownloadTimeout')->willReturn(15);
        $config->method('getMediaMaxFileSizeKb')->willReturn(10240);
        $config->method('isMediaRedownloadExisting')->willReturn(true);

        return $config;
    }

    private function resolverWithConfig(Config $config): FileResolver
    {
        return new FileResolver(
            $this->filesystem,
            $this->mediaConfig,
            $this->clientFactory,
            $this->storageDatabase,
            $config,
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
            $this->clientFactory,
            $this->storageDatabase,
            $this->config,
            $this->logger
        );
    }
}
