<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Data;

use ReadyData\Import\Api\Data\AttributeSyncResponseInterface;

class AttributeSyncResponse implements AttributeSyncResponseInterface
{
    private int $received = 0;
    private int $created = 0;
    private int $updated = 0;
    private int $unchanged = 0;
    private int $skipped = 0;
    private int $failed = 0;
    private int $elapsedMs = 0;
    private array $results = [];

    public function getReceived(): int
    {
        return $this->received;
    }

    public function setReceived(int $received): AttributeSyncResponseInterface
    {
        $this->received = $received;
        return $this;
    }

    public function getCreated(): int
    {
        return $this->created;
    }

    public function setCreated(int $created): AttributeSyncResponseInterface
    {
        $this->created = $created;
        return $this;
    }

    public function getUpdated(): int
    {
        return $this->updated;
    }

    public function setUpdated(int $updated): AttributeSyncResponseInterface
    {
        $this->updated = $updated;
        return $this;
    }

    public function getUnchanged(): int
    {
        return $this->unchanged;
    }

    public function setUnchanged(int $unchanged): AttributeSyncResponseInterface
    {
        $this->unchanged = $unchanged;
        return $this;
    }

    public function getSkipped(): int
    {
        return $this->skipped;
    }

    public function setSkipped(int $skipped): AttributeSyncResponseInterface
    {
        $this->skipped = $skipped;
        return $this;
    }

    public function getFailed(): int
    {
        return $this->failed;
    }

    public function setFailed(int $failed): AttributeSyncResponseInterface
    {
        $this->failed = $failed;
        return $this;
    }

    public function getElapsedMs(): int
    {
        return $this->elapsedMs;
    }

    public function setElapsedMs(int $elapsedMs): AttributeSyncResponseInterface
    {
        $this->elapsedMs = $elapsedMs;
        return $this;
    }

    public function getResults(): array
    {
        return $this->results;
    }

    public function setResults(array $results): AttributeSyncResponseInterface
    {
        $this->results = $results;
        return $this;
    }
}
