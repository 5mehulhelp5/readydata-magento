<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Media;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Pool;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use ReadyData\Import\Model\Config;
use ReadyData\Import\Model\Exception\MediaReferenceException;

/**
 * Concurrent image downloads through a bounded Guzzle pool.
 *
 * Guzzle rather than Magento\Framework\HTTP\AsyncClientInterface, which cannot
 * express any of the three things this needs: it has no concurrency cap (every
 * request goes onto the shared curl_multi handle at once), it materialises each
 * whole body as a PHP string held for the deferred's lifetime, and it passes no
 * timeout options at all — Guzzle's defaults leave connect and total timeouts
 * infinite, so adopting it would silently drop the limits the synchronous path
 * already enforces. Guzzle 7 is a hard requirement of magento/framework, so
 * depending on it directly is safe.
 *
 * Two properties matter for a bulk importer:
 *
 *  - The pool keeps a SLIDING window: EachPromise starts a new request as each
 *    one finishes, so a single slow image never stalls a whole chunk the way
 *    hand-rolled array_chunk batching would.
 *  - Bodies stream into php://temp, which keeps small images in memory and
 *    spills large ones to disk. Peak footprint is therefore bounded by
 *    concurrency x MEMORY_SPILL_BYTES rather than by the largest image, and an
 *    origin that lies about (or omits) Content-Length cannot blow memory up.
 *
 * Per-URL failures are reported through the callback, never thrown: one dead
 * host must not abandon the rest of the batch.
 */
class PooledDownloader implements DownloaderInterface
{
    /**
     * Bytes held in memory per in-flight response before php://temp rolls the
     * rest over to a temporary file.
     */
    private const MEMORY_SPILL_BYTES = 2097152;

    private const CONNECT_TIMEOUT_SEC = 5;
    private const MAX_REDIRECTS = 3;

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly Config $config
    ) {
    }

    public function fetchAll(array $urls, callable $onEach): void
    {
        if (!$urls) {
            return;
        }

        $maxBytes = $this->config->getMediaMaxFileSizeKb() * 1024;

        $pool = new Pool(
            $this->httpClient,
            $this->requests($urls, $maxBytes),
            [
                'concurrency' => $this->config->getMediaDownloadConcurrency(),
                'fulfilled' => function (ResponseInterface $response, string $key) use ($onEach): void {
                    $body = $response->getBody();
                    try {
                        if ($body->isSeekable()) {
                            // The sink is left at EOF after the transfer.
                            $body->rewind();
                        }
                        $onEach($key, $body, $response->getStatusCode(), null);
                    } finally {
                        // Release the php://temp handle (and its spill file)
                        // before the next request claims the window slot.
                        $body->close();
                    }
                },
                'rejected' => function (mixed $reason, string $key) use ($onEach): void {
                    $onEach($key, null, 0, $this->toThrowable($reason));
                },
            ]
        );

        $pool->promise()->wait();
    }

    /**
     * Normalise a rejection reason into the most useful throwable it carries.
     *
     * Guzzle swallows anything thrown from on_headers inside a RequestException
     * whose own message is the useless "An error was encountered during the
     * on_headers event", keeping the real cause as the previous. Since that is
     * how this class refuses an oversize body, the chain is unwrapped so the
     * caller reports the reason rather than the wrapper.
     */
    private function toThrowable(mixed $reason): \Throwable
    {
        if (!$reason instanceof \Throwable) {
            return new \RuntimeException((string)$reason);
        }

        for ($cause = $reason; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof MediaReferenceException) {
                return $cause;
            }
        }

        return $reason;
    }

    /**
     * Yields one request factory per URL. Lazily, and deliberately so: the
     * pool only invokes a factory when a window slot frees up, which is what
     * keeps the number of open php://temp handles equal to the concurrency
     * rather than to the size of the batch.
     *
     * @param array<string, string> $urls
     * @return \Generator<string, callable(array): PromiseInterface>
     */
    private function requests(array $urls, int $maxBytes): \Generator
    {
        foreach ($urls as $key => $url) {
            yield $key => fn (): PromiseInterface => $this->httpClient->requestAsync(
                'GET',
                $url,
                $this->options($maxBytes)
            );
        }
    }

    /**
     * @return array<string, mixed> Guzzle per-request options
     */
    private function options(int $maxBytes): array
    {
        return [
            RequestOptions::SINK => fopen('php://temp/maxmemory:' . self::MEMORY_SPILL_BYTES, 'r+'),
            RequestOptions::CONNECT_TIMEOUT => self::CONNECT_TIMEOUT_SEC,
            RequestOptions::TIMEOUT => $this->config->getMediaDownloadTimeout(),
            // Redirects are capped and pinned to http(s), so a redirect cannot
            // walk the fetch onto another protocol.
            RequestOptions::ALLOW_REDIRECTS => [
                'max' => self::MAX_REDIRECTS,
                'protocols' => ['http', 'https'],
                'strict' => true,
                'referer' => false,
            ],
            // A 404 is data, not an exception: the caller turns it into a
            // per-product warning like any other unusable reference.
            RequestOptions::HTTP_ERRORS => false,
            // Refuse an oversize body from its headers, before it transfers.
            // Throwing here is Guzzle's documented way to abort at that point;
            // the wrapper it produces is unwrapped again in toThrowable().
            'on_headers' => static function (ResponseInterface $response) use ($maxBytes): void {
                $declared = (int)($response->getHeaderLine('Content-Length') ?: 0);
                if ($declared > $maxBytes) {
                    throw new MediaReferenceException(sprintf(
                        'the origin announced %d bytes, above the %d byte limit',
                        $declared,
                        $maxBytes
                    ));
                }
            },
        ];
    }
}
