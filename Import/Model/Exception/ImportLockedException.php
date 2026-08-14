<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Exception;

use Magento\Framework\Phrase;
use Magento\Framework\Webapi\Exception as WebapiException;
use ReadyData\Import\Model\ImportLocks;

/**
 * A request was refused because another one holds a lock it needs.
 *
 * Exists to give that refusal a **status code of its own**. Every other failure
 * from these endpoints is a LocalizedException, which Magento renders as `400
 * Bad Request` — the one status a caller must NOT retry, since a bad payload
 * stays bad. A lock conflict is the opposite: nothing is wrong with the request
 * and the only sane response is to send it again shortly. Callers had to
 * substring-match the message to tell the two apart, and one endpoint's wording
 * did not match, so its rejections were never retried at all.
 *
 * **429**, not 503 or 409. Extending WebapiException is what carries the code
 * through {@see \Magento\Framework\Webapi\ErrorProcessor::maskException()},
 * which returns such an exception unchanged; it accepts any code in 400-599, and
 * of the plausible ones:
 *
 * - `503` reads as "the backend is unhealthy" to everything between the caller
 *   and PHP — proxies, load balancers, health checks — and this store is
 *   perfectly healthy;
 * - `409` describes the state accurately but tells a caller to resolve a
 *   conflict, when there is nothing to resolve but the wait;
 * - `429` is the code whose defined meaning is "back off and retry", which is
 *   exactly the behaviour being asked for, and which every retry library already
 *   treats that way.
 *
 * It still extends LocalizedException through WebapiException, so callers
 * outside the web API — the CLI, another module — catch it exactly as before.
 *
 * `Retry-After` is deliberately NOT set. The header would have to come from the
 * HTTP response object, which a service has no business holding, so the hint
 * travels in the body's `parameters` instead, where a non-HTTP caller can read
 * it too.
 */
class ImportLockedException extends WebapiException
{
    /**
     * Machine-readable discriminator in the response body, so a caller never has
     * to match on a message again.
     */
    public const REASON = 'import_locked';

    /**
     * @param Phrase $phrase the human-readable message, unchanged from what
     *        these endpoints have always sent, so a caller still matching on it
     *        keeps working
     * @param string[] $locks the lock names that were unavailable, for the log
     *        line the caller will write and the report they will read later
     * @param int $retryAfterSec how long the caller should wait, which is the
     *        wait the next attempt will itself sit through
     */
    public function __construct(
        Phrase $phrase,
        array $locks = [],
        int $retryAfterSec = ImportLocks::TIMEOUT_SEC
    ) {
        parent::__construct(
            $phrase,
            0,
            self::HTTP_TOO_MANY_REQUESTS,
            [
                'reason' => self::REASON,
                'locks' => array_values($locks),
                'retry_after' => $retryAfterSec,
            ],
            self::REASON
        );
    }
}
