<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Model\Subscriber;

/**
 * A delivery destination, with its secret already decrypted.
 *
 * Instances are short-lived and never persisted or logged as a whole, because
 * the secret is the only thing standing between a public endpoint and forged
 * events.
 */
class Subscriber
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly string $endpointUrl,
        public readonly string $secret,
        public readonly bool $enabled = true,
        public readonly int $maxBatchSize = 100,
        public readonly array $headers = []
    ) {
    }

    /**
     * Keeps the secret out of var_dump(), stack traces and any log line that
     * happens to interpolate the object.
     */
    public function __debugInfo(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'endpointUrl' => $this->endpointUrl,
            'secret' => '***redacted***',
            'enabled' => $this->enabled,
            'maxBatchSize' => $this->maxBatchSize,
        ];
    }
}
