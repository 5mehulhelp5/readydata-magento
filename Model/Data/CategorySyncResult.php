<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Data;

use ReadyData\Import\Api\Data\CategorySyncResultInterface;

class CategorySyncResult implements CategorySyncResultInterface
{
    private string $path = '';
    private ?int $entityId = null;
    private string $status = self::STATUS_ERROR;
    private ?string $reason = null;
    private array $messages = [];

    public function getPath(): string
    {
        return $this->path;
    }

    public function setPath(string $path): CategorySyncResultInterface
    {
        $this->path = $path;
        return $this;
    }

    public function getEntityId(): ?int
    {
        return $this->entityId;
    }

    public function setEntityId(?int $entityId): CategorySyncResultInterface
    {
        $this->entityId = $entityId;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): CategorySyncResultInterface
    {
        $this->status = $status;
        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): CategorySyncResultInterface
    {
        $this->reason = $reason;
        return $this;
    }

    public function getMessages(): array
    {
        return $this->messages;
    }

    public function setMessages(array $messages): CategorySyncResultInterface
    {
        $this->messages = $messages;
        return $this;
    }
}
