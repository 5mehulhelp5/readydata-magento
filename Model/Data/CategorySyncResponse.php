<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Data;

use ReadyData\Import\Api\Data\CategorySyncResponseInterface;

class CategorySyncResponse implements CategorySyncResponseInterface
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

    public function setReceived(int $received): CategorySyncResponseInterface
    {
        $this->received = $received;
        return $this;
    }

    public function getCreated(): int
    {
        return $this->created;
    }

    public function setCreated(int $created): CategorySyncResponseInterface
    {
        $this->created = $created;
        return $this;
    }

    public function getUpdated(): int
    {
        return $this->updated;
    }

    public function setUpdated(int $updated): CategorySyncResponseInterface
    {
        $this->updated = $updated;
        return $this;
    }

    public function getUnchanged(): int
    {
        return $this->unchanged;
    }

    public function setUnchanged(int $unchanged): CategorySyncResponseInterface
    {
        $this->unchanged = $unchanged;
        return $this;
    }

    public function getSkipped(): int
    {
        return $this->skipped;
    }

    public function setSkipped(int $skipped): CategorySyncResponseInterface
    {
        $this->skipped = $skipped;
        return $this;
    }

    public function getFailed(): int
    {
        return $this->failed;
    }

    public function setFailed(int $failed): CategorySyncResponseInterface
    {
        $this->failed = $failed;
        return $this;
    }

    public function getElapsedMs(): int
    {
        return $this->elapsedMs;
    }

    public function setElapsedMs(int $elapsedMs): CategorySyncResponseInterface
    {
        $this->elapsedMs = $elapsedMs;
        return $this;
    }

    public function getResults(): array
    {
        return $this->results;
    }

    public function setResults(array $results): CategorySyncResponseInterface
    {
        $this->results = $results;
        return $this;
    }
}
