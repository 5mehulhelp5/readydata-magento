<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Data;

use ReadyData\Import\Api\Data\CategoryStoreResultInterface;

class CategoryStoreResult implements CategoryStoreResultInterface
{
    private ?int $storeId = null;
    private string $status = self::STATUS_SKIPPED;
    private ?string $reason = null;
    private array $messages = [];

    public function getStoreId(): ?int
    {
        return $this->storeId;
    }

    public function setStoreId(?int $storeId): CategoryStoreResultInterface
    {
        $this->storeId = $storeId;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): CategoryStoreResultInterface
    {
        $this->status = $status;
        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): CategoryStoreResultInterface
    {
        $this->reason = $reason;
        return $this;
    }

    public function getMessages(): array
    {
        return $this->messages;
    }

    public function setMessages(array $messages): CategoryStoreResultInterface
    {
        $this->messages = $messages;
        return $this;
    }
}
