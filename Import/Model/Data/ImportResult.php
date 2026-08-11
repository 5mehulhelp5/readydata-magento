<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Data;

use ReadyData\Import\Api\Data\ImportResultInterface;

class ImportResult implements ImportResultInterface
{
    private string $sku = '';
    private ?int $entityId = null;
    private string $status = self::STATUS_ERROR;
    private array $messages = [];
    private ?array $storeResults = null;

    public function getSku(): string
    {
        return $this->sku;
    }

    public function setSku(string $sku): ImportResultInterface
    {
        $this->sku = $sku;
        return $this;
    }

    public function getEntityId(): ?int
    {
        return $this->entityId;
    }

    public function setEntityId(int $entityId): ImportResultInterface
    {
        $this->entityId = $entityId;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): ImportResultInterface
    {
        $this->status = $status;
        return $this;
    }

    public function getMessages(): array
    {
        return $this->messages;
    }

    public function setMessages(array $messages): ImportResultInterface
    {
        $this->messages = $messages;
        return $this;
    }

    public function getStoreResults(): ?array
    {
        return $this->storeResults;
    }

    public function setStoreResults(array $storeResults): ImportResultInterface
    {
        $this->storeResults = $storeResults;
        return $this;
    }
}
