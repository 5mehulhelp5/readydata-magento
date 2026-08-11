<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Media;

use Psr\Http\Message\StreamInterface;

/**
 * Fetches many URLs, handing each result to a callback as it completes.
 *
 * Deliberately callback-driven rather than returning a map: the caller consumes
 * (validates, writes, discards) each body inside the callback, so no more than
 * the configured number of bodies is ever live. Returning them all would defeat
 * the point of streaming them in the first place.
 *
 * Implementations must never throw for a single failed URL — a bad host or a
 * timeout is reported through the callback's $error argument so the rest of the
 * batch still completes.
 */
interface DownloaderInterface
{
    /**
     * @param array<string, string> $urls caller's key => URL
     * @param callable $onEach fn(string $key, ?StreamInterface $body, int $status, ?\Throwable $error): void
     *        invoked exactly once per entry of $urls. $body and $status are set
     *        on a completed response (any status, including 4xx/5xx); $error is
     *        set instead when the request never produced one.
     */
    public function fetchAll(array $urls, callable $onEach): void;
}
