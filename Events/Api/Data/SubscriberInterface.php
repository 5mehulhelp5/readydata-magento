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
    /**
     * @return string|null
     */
    public function getCode(): ?string;

    /**
     * @param string|null $code
     * @return $this
     */
    public function setCode(?string $code): self;

    /**
     * @return string|null
     */
    public function getEndpointUrl(): ?string;

    /**
     * @param string|null $endpointUrl
     * @return $this
     */
    public function setEndpointUrl(?string $endpointUrl): self;

    /**
     * The HMAC signing secret.
     *
     * Returned in clear exactly once, by the call that registers the
     * subscriber. Every later read returns null: the value is stored encrypted
     * and a caller that lost it has to rotate rather than recover it.
     *
     * @return string|null
     */
    public function getSecret(): ?string;

    /**
     * @param string|null $secret
     * @return $this
     */
    public function setSecret(?string $secret): self;

    /**
     * @return bool|null
     */
    public function getEnabled(): ?bool;

    /**
     * @param bool|null $enabled
     * @return $this
     */
    public function setEnabled(?bool $enabled): self;

    /**
     * @return int|null
     */
    public function getMaxBatchSize(): ?int;

    /**
     * @param int|null $maxBatchSize
     * @return $this
     */
    public function setMaxBatchSize(?int $maxBatchSize): self;
}
