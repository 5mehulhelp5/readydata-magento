<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Data;

use ReadyData\Import\Api\Data\ImportResponseInterface;

class ImportResponse implements ImportResponseInterface
{
    private int $received = 0;
    private int $created = 0;
    private int $updated = 0;
    private int $failed = 0;
    private int $elapsedMs = 0;
    private int $storeId = 0;
    private array $results = [];

    public function getStoreId(): int
    {
        return $this->storeId;
    }

    public function setStoreId(int $storeId): ImportResponseInterface
    {
        $this->storeId = $storeId;
        return $this;
    }

    public function getReceived(): int
    {
        return $this->received;
    }

    public function setReceived(int $received): ImportResponseInterface
    {
        $this->received = $received;
        return $this;
    }

    public function getCreated(): int
    {
        return $this->created;
    }

    public function setCreated(int $created): ImportResponseInterface
    {
        $this->created = $created;
        return $this;
    }

    public function getUpdated(): int
    {
        return $this->updated;
    }

    public function setUpdated(int $updated): ImportResponseInterface
    {
        $this->updated = $updated;
        return $this;
    }

    public function getFailed(): int
    {
        return $this->failed;
    }

    public function setFailed(int $failed): ImportResponseInterface
    {
        $this->failed = $failed;
        return $this;
    }

    public function getElapsedMs(): int
    {
        return $this->elapsedMs;
    }

    public function setElapsedMs(int $elapsedMs): ImportResponseInterface
    {
        $this->elapsedMs = $elapsedMs;
        return $this;
    }

    public function getResults(): array
    {
        return $this->results;
    }

    public function setResults(array $results): ImportResponseInterface
    {
        $this->results = $results;
        return $this;
    }
}
