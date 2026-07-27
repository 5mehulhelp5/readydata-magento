<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Data;

use ReadyData\Import\Api\Data\AttributeSyncResultInterface;

class AttributeSyncResult implements AttributeSyncResultInterface
{
    private string $attributeCode = '';
    private string $status = self::STATUS_ERROR;
    private ?string $reason = null;
    private array $messages = [];

    public function getAttributeCode(): string
    {
        return $this->attributeCode;
    }

    public function setAttributeCode(string $attributeCode): AttributeSyncResultInterface
    {
        $this->attributeCode = $attributeCode;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): AttributeSyncResultInterface
    {
        $this->status = $status;
        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): AttributeSyncResultInterface
    {
        $this->reason = $reason;
        return $this;
    }

    public function getMessages(): array
    {
        return $this->messages;
    }

    public function setMessages(array $messages): AttributeSyncResultInterface
    {
        $this->messages = $messages;
        return $this;
    }
}
