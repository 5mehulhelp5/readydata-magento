<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Model\Data;

use ReadyData\Events\Api\Data\SubscriberInterface;

class Subscriber implements SubscriberInterface
{
    private ?string $code = null;
    private ?string $endpointUrl = null;
    private ?string $secret = null;
    private ?bool $enabled = true;
    private ?int $maxBatchSize = 100;

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): SubscriberInterface
    {
        $this->code = $code;

        return $this;
    }

    public function getEndpointUrl(): ?string
    {
        return $this->endpointUrl;
    }

    public function setEndpointUrl(?string $endpointUrl): SubscriberInterface
    {
        $this->endpointUrl = $endpointUrl;

        return $this;
    }

    public function getSecret(): ?string
    {
        return $this->secret;
    }

    public function setSecret(?string $secret): SubscriberInterface
    {
        $this->secret = $secret;

        return $this;
    }

    public function getEnabled(): ?bool
    {
        return $this->enabled;
    }

    public function setEnabled(?bool $enabled): SubscriberInterface
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getMaxBatchSize(): ?int
    {
        return $this->maxBatchSize;
    }

    public function setMaxBatchSize(?int $maxBatchSize): SubscriberInterface
    {
        $this->maxBatchSize = $maxBatchSize;

        return $this;
    }
}
