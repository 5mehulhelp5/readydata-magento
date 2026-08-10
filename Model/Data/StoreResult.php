<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Data;

use ReadyData\Import\Api\Data\StoreResultInterface;

class StoreResult implements StoreResultInterface
{
    private int $storeId = 0;
    private string $status = self::STATUS_ERROR;
    private array $messages = [];

    public function getStoreId(): int
    {
        return $this->storeId;
    }

    public function setStoreId(int $storeId): StoreResultInterface
    {
        $this->storeId = $storeId;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): StoreResultInterface
    {
        $this->status = $status;
        return $this;
    }

    public function getMessages(): array
    {
        return $this->messages;
    }

    public function setMessages(array $messages): StoreResultInterface
    {
        $this->messages = $messages;
        return $this;
    }
}
