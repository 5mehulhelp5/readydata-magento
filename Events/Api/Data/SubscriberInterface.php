<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Api\Data;

/**
 * A registered delivery destination.
 *
 * @api
 */
interface SubscriberInterface
{
    public function getCode(): ?string;

    public function setCode(?string $code): self;

    public function getEndpointUrl(): ?string;

    public function setEndpointUrl(?string $endpointUrl): self;

    /**
     * The HMAC signing secret.
     *
     * Returned in clear exactly once, by the call that registers the
     * subscriber. Every later read returns null: the value is stored encrypted
     * and a caller that lost it has to rotate rather than recover it.
     */
    public function getSecret(): ?string;

    public function setSecret(?string $secret): self;

    public function getEnabled(): ?bool;

    public function setEnabled(?bool $enabled): self;

    public function getMaxBatchSize(): ?int;

    public function setMaxBatchSize(?int $maxBatchSize): self;
}
