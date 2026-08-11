<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Model\Data;

use ReadyData\Events\Api\Data\QueueStatusInterface;

class QueueStatus implements QueueStatusInterface
{
    private ?bool $enabled = null;
    private ?bool $hooked = null;
    private ?string $instanceId = null;
    private ?int $catalogueSize = null;
    private ?string $subscriberCode = null;
    private ?int $subscriptionCount = null;
    private ?int $waiting = 0;
    private ?int $inProgress = 0;
    private ?int $sent = 0;
    private ?int $failed = 0;
    private ?int $deadLettered = 0;
    private ?string $oldestWaitingAt = null;

    public function getEnabled(): ?bool
    {
        return $this->enabled;
    }

    public function setEnabled(?bool $enabled): QueueStatusInterface
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getHooked(): ?bool
    {
        return $this->hooked;
    }

    public function setHooked(?bool $hooked): QueueStatusInterface
    {
        $this->hooked = $hooked;

        return $this;
    }

    public function getInstanceId(): ?string
    {
        return $this->instanceId;
    }

    public function setInstanceId(?string $instanceId): QueueStatusInterface
    {
        $this->instanceId = $instanceId;

        return $this;
    }

    public function getCatalogueSize(): ?int
    {
        return $this->catalogueSize;
    }

    public function setCatalogueSize(?int $size): QueueStatusInterface
    {
        $this->catalogueSize = $size;

        return $this;
    }

    public function getSubscriberCode(): ?string
    {
        return $this->subscriberCode;
    }

    public function setSubscriberCode(?string $code): QueueStatusInterface
    {
        $this->subscriberCode = $code;

        return $this;
    }

    public function getSubscriptionCount(): ?int
    {
        return $this->subscriptionCount;
    }

    public function setSubscriptionCount(?int $count): QueueStatusInterface
    {
        $this->subscriptionCount = $count;

        return $this;
    }

    public function getWaiting(): ?int
    {
        return $this->waiting;
    }

    public function setWaiting(?int $waiting): QueueStatusInterface
    {
        $this->waiting = $waiting;

        return $this;
    }

    public function getInProgress(): ?int
    {
        return $this->inProgress;
    }

    public function setInProgress(?int $inProgress): QueueStatusInterface
    {
        $this->inProgress = $inProgress;

        return $this;
    }

    public function getSent(): ?int
    {
        return $this->sent;
    }

    public function setSent(?int $sent): QueueStatusInterface
    {
        $this->sent = $sent;

        return $this;
    }

    public function getFailed(): ?int
    {
        return $this->failed;
    }

    public function setFailed(?int $failed): QueueStatusInterface
    {
        $this->failed = $failed;

        return $this;
    }

    public function getDeadLettered(): ?int
    {
        return $this->deadLettered;
    }

    public function setDeadLettered(?int $deadLettered): QueueStatusInterface
    {
        $this->deadLettered = $deadLettered;

        return $this;
    }

    public function getOldestWaitingAt(): ?string
    {
        return $this->oldestWaitingAt;
    }

    public function setOldestWaitingAt(?string $timestamp): QueueStatusInterface
    {
        $this->oldestWaitingAt = $timestamp;

        return $this;
    }
}
