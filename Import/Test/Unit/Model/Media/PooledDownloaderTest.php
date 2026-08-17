<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Test\Unit\Model\Media;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response as Psr7Response;
use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use ReadyData\Import\Model\Config;
use ReadyData\Import\Model\Media\HostAllowList;
use ReadyData\Import\Model\Media\PooledDownloader;

class PooledDownloaderTest extends TestCase
{
    private Config&MockObject $config;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('getMediaDownloadTimeout')->willReturn(15);
        $this->config->method('getMediaMaxFileSizeKb')->willReturn(10240);
        $this->config->method('getMediaDownloadConcurrency')->willReturn(4);
    }

    public function testEveryUrlProducesExactlyOneCallbackKeyedAsGiven(): void
    {
        $downloader = $this->downloaderFor([
            new Psr7Response(200, [], 'a-bytes'),
            new Psr7Response(200, [], 'b-bytes'),
            new Psr7Response(200, [], 'c-bytes'),
        ]);

        $seen = [];
        $downloader->fetchAll(
            ['a' => 'https://cdn.example.com/a.jpg', 'b' => 'https://cdn.example.com/b.jpg', 'c' => 'https://cdn.example.com/c.jpg'],
            function (string $key, ?StreamInterface $body, int $status) use (&$seen): void {
                $seen[$key] = [$status, $body?->getContents()];
            }
        );

        self::assertSame(
            ['a' => [200, 'a-bytes'], 'b' => [200, 'b-bytes'], 'c' => [200, 'c-bytes']],
            $seen
        );
    }

    public function testEmptyInputIsANoOp(): void
    {
        $handler = new MockHandler([]);
        $downloader = new PooledDownloader($this->clientFor($handler), $this->config, new HostAllowList($this->config));

        $downloader->fetchAll([], static function (): void {
            self::fail('The callback must not run for an empty batch.');
        });

        self::assertSame(0, $handler->count());
    }

    public function testBodyIsRewoundBeforeTheCallbackSeesIt(): void
    {
        // The sink is left at EOF by the transfer; a caller that has to rewind
        // by hand will silently read an empty file.
        $downloader = $this->downloaderFor([new Psr7Response(200, [], 'image-bytes')]);

        $read = null;
        $downloader->fetchAll(
            ['a' => 'https://cdn.example.com/a.jpg'],
            function (string $key, ?StreamInterface $body) use (&$read): void {
                $read = $body->read(5);
            }
        );

        self::assertSame('image', $read);
    }

    public function testNotFoundArrivesAsAStatusNotAnException(): void
    {
        $downloader = $this->downloaderFor([new Psr7Response(404, [], 'nope')]);

        $status = null;
        $error = 'unset';
        $downloader->fetchAll(
            ['a' => 'https://cdn.example.com/gone.jpg'],
            function (string $key, ?StreamInterface $body, int $code, ?\Throwable $e) use (&$status, &$error): void {
                $status = $code;
                $error = $e;
            }
        );

        self::assertSame(404, $status);
        self::assertNull($error);
    }

    public function testConnectionFailureIsReportedWithoutAbandoningSiblings(): void
    {
        // Not MockHandler: it hands the queued exception straight to on_headers,
        // which real handlers only ever call with a genuine response.
        $queue = [
            new Psr7Response(200, [], 'ok-bytes'),
            new ConnectException('Connection refused', new Psr7Request('GET', 'https://dead.example.com/b.jpg')),
            new Psr7Response(200, [], 'also-ok'),
        ];
        $handler = static function () use (&$queue): PromiseInterface {
            $next = array_shift($queue);

            return $next instanceof \Throwable ? Create::rejectionFor($next) : Create::promiseFor($next);
        };
        $downloader = new PooledDownloader(
            new Client(['handler' => HandlerStack::create($handler)]),
            $this->config,
            new HostAllowList($this->config)
        );

        $outcomes = [];
        $downloader->fetchAll(
            ['a' => 'https://cdn.example.com/a.jpg', 'b' => 'https://dead.example.com/b.jpg', 'c' => 'https://cdn.example.com/c.jpg'],
            function (string $key, ?StreamInterface $body, int $status, ?\Throwable $error) use (&$outcomes): void {
                $outcomes[$key] = $error?->getMessage() ?? 'ok';
            }
        );

        self::assertSame('ok', $outcomes['a']);
        self::assertStringContainsString('Connection refused', $outcomes['b']);
        self::assertSame('ok', $outcomes['c']);
    }

    public function testAnnouncedOversizeIsRefusedFromTheHeadersAlone(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getMediaDownloadTimeout')->willReturn(15);
        $config->method('getMediaDownloadConcurrency')->willReturn(4);
        $config->method('getMediaMaxFileSizeKb')->willReturn(1);

        $downloader = $this->downloaderFor(
            [new Psr7Response(200, ['Content-Length' => (string)(5 * 1024 * 1024)], 'x')],
            $config
        );

        $error = null;
        $downloader->fetchAll(
            ['a' => 'https://cdn.example.com/huge.jpg'],
            function (string $key, ?StreamInterface $body, int $status, ?\Throwable $e) use (&$error): void {
                $error = $e;
            }
        );

        self::assertNotNull($error);
        self::assertStringContainsString('above the', $error->getMessage());
    }

    public function testTimeoutsSinkAndRedirectCapAreSetOnEveryRequest(): void
    {
        $captured = [];
        $handler = static function (RequestInterface $request, array $options) use (&$captured): PromiseInterface {
            $captured[] = $options;
            $promise = new Promise(static function () use (&$promise): void {
                $promise->resolve(new Psr7Response(200, [], 'bytes'));
            });

            return $promise;
        };

        $downloader = new PooledDownloader(
            new Client(['handler' => HandlerStack::create($handler)]),
            $this->config,
            new HostAllowList($this->config)
        );
        $downloader->fetchAll(['a' => 'https://cdn.example.com/a.jpg'], static function (): void {
        });

        self::assertCount(1, $captured);
        self::assertSame(15, $captured[0][RequestOptions::TIMEOUT]);
        self::assertSame(5, $captured[0][RequestOptions::CONNECT_TIMEOUT]);
        self::assertFalse($captured[0][RequestOptions::HTTP_ERRORS]);
        self::assertSame(3, $captured[0][RequestOptions::ALLOW_REDIRECTS]['max']);
        self::assertSame(['http', 'https'], $captured[0][RequestOptions::ALLOW_REDIRECTS]['protocols']);
        // A stream OBJECT, not the raw handle: every redirect hop reuses this one
        // sink, and the options array is what keeps it alive for the whole
        // transfer. Handing Guzzle a bare resource let it close the handle
        // between hops, and the resulting "Invalid resource" came out of
        // curl_multi_exec() where nothing in this module could catch it.
        self::assertInstanceOf(StreamInterface::class, $captured[0][RequestOptions::SINK]);
    }

    public function testConcurrencyCapBoundsRequestsInFlight(): void
    {
        $concurrency = 3;
        $config = $this->createMock(Config::class);
        $config->method('getMediaDownloadTimeout')->willReturn(15);
        $config->method('getMediaMaxFileSizeKb')->willReturn(10240);
        $config->method('getMediaDownloadConcurrency')->willReturn($concurrency);

        // Promises are held unresolved until the pool stops opening new ones,
        // so the high-water mark is the real in-flight count.
        $pending = [];
        $inFlight = 0;
        $peak = 0;
        $handler = static function () use (&$pending, &$inFlight, &$peak): PromiseInterface {
            $inFlight++;
            $peak = max($peak, $inFlight);
            $promise = new Promise(static function () use (&$pending): void {
                // Resolving the oldest outstanding promise frees one slot.
                $next = array_shift($pending);
                $next?->resolve(new Psr7Response(200, [], 'bytes'));
            });
            $pending[] = $promise;

            return $promise;
        };

        $urls = [];
        for ($i = 0; $i < 12; $i++) {
            $urls['k' . $i] = 'https://cdn.example.com/' . $i . '.jpg';
        }

        $completed = 0;
        (new PooledDownloader(
            new Client(['handler' => HandlerStack::create($handler)]),
            $config,
            new HostAllowList($config)
        ))
            ->fetchAll($urls, static function () use (&$completed, &$inFlight): void {
                $completed++;
                $inFlight--;
            });

        self::assertSame(12, $completed);
        self::assertLessThanOrEqual($concurrency, $peak);
    }

    /**
     * @param array<int, Psr7Response|\Throwable> $queue
     */
    private function downloaderFor(array $queue, ?Config $config = null): PooledDownloader
    {
        $config ??= $this->config;

        return new PooledDownloader($this->clientFor(new MockHandler($queue)), $config, new HostAllowList($config));
    }

    private function clientFor(MockHandler $handler): Client
    {
        return new Client(['handler' => HandlerStack::create($handler)]);
    }
}
