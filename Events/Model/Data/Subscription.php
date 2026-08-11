<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Model\Data;

use ReadyData\Events\Api\Data\SubscriptionInterface;

class Subscription implements SubscriptionInterface
{
    private ?int $id = null;
    private ?string $eventCode = null;
    private ?bool $enabled = true;
    private ?array $fields = null;
    private ?array $rules = null;
    private ?string $gateClass = null;
    private ?array $storeIds = null;
    private ?bool $ignoreReadydataOrigin = true;
    private ?string $coalesceBy = null;
    private ?array $processors = null;
    private ?array $converters = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): SubscriptionInterface
    {
        $this->id = $id;

        return $this;
    }

    public function getEventCode(): ?string
    {
        return $this->eventCode;
    }

    public function setEventCode(?string $eventCode): SubscriptionInterface
    {
        $this->eventCode = $eventCode;

        return $this;
    }

    public function getEnabled(): ?bool
    {
        return $this->enabled;
    }

    public function setEnabled(?bool $enabled): SubscriptionInterface
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getFields(): ?array
    {
        return $this->fields;
    }

    public function setFields(?array $fields): SubscriptionInterface
    {
        $this->fields = $fields;

        return $this;
    }

    public function getRules(): ?array
    {
        return $this->rules;
    }

    public function setRules(?array $rules): SubscriptionInterface
    {
        $this->rules = $rules;

        return $this;
    }

    public function getGateClass(): ?string
    {
        return $this->gateClass;
    }

    public function setGateClass(?string $gateClass): SubscriptionInterface
    {
        $this->gateClass = $gateClass;

        return $this;
    }

    public function getStoreIds(): ?array
    {
        return $this->storeIds;
    }

    public function setStoreIds(?array $storeIds): SubscriptionInterface
    {
        $this->storeIds = $storeIds;

        return $this;
    }

    public function getIgnoreReadydataOrigin(): ?bool
    {
        return $this->ignoreReadydataOrigin;
    }

    public function setIgnoreReadydataOrigin(?bool $ignore): SubscriptionInterface
    {
        $this->ignoreReadydataOrigin = $ignore;

        return $this;
    }

    public function getProcessors(): ?array
    {
        return $this->processors;
    }

    public function setProcessors(?array $processors): SubscriptionInterface
    {
        $this->processors = $processors;

        return $this;
    }

    public function getConverters(): ?array
    {
        return $this->converters;
    }

    public function setConverters(?array $converters): SubscriptionInterface
    {
        $this->converters = $converters;

        return $this;
    }

    public function getCoalesceBy(): ?string
    {
        return $this->coalesceBy;
    }

    public function setCoalesceBy(?string $coalesceBy): SubscriptionInterface
    {
        $this->coalesceBy = $coalesceBy;

        return $this;
    }
}
