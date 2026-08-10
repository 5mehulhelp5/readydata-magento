<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Data;

use ReadyData\Import\Api\Data\ImportSettingsInterface;

class ImportSettings implements ImportSettingsInterface
{
    private ?string $storeViewCode = null;
    private ?int $storeId = null;
    private ?int $rootCategoryId = null;
    private ?bool $continueOnError = null;
    private ?int $batchSize = null;

    public function getStoreViewCode(): ?string
    {
        return $this->storeViewCode;
    }

    public function setStoreViewCode(string $storeViewCode): ImportSettingsInterface
    {
        $this->storeViewCode = $storeViewCode;
        return $this;
    }

    public function getStoreId(): ?int
    {
        return $this->storeId;
    }

    public function setStoreId(?int $storeId): ImportSettingsInterface
    {
        $this->storeId = $storeId;
        return $this;
    }

    public function getRootCategoryId(): ?int
    {
        return $this->rootCategoryId;
    }

    public function setRootCategoryId(?int $rootCategoryId): ImportSettingsInterface
    {
        $this->rootCategoryId = $rootCategoryId;
        return $this;
    }

    public function getContinueOnError(): ?bool
    {
        return $this->continueOnError;
    }

    public function setContinueOnError(bool $continueOnError): ImportSettingsInterface
    {
        $this->continueOnError = $continueOnError;
        return $this;
    }

    public function getBatchSize(): ?int
    {
        return $this->batchSize;
    }

    public function setBatchSize(int $batchSize): ImportSettingsInterface
    {
        $this->batchSize = $batchSize;
        return $this;
    }
}
