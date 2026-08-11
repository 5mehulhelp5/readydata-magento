<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model;

use ReadyData\Import\Api\Data\ImportResponseInterface;
use ReadyData\Import\Api\Data\ImportSettingsInterface;
use ReadyData\Import\Api\ProductImportInterface;

/**
 * Thin Web API facade; all logic lives in ImportService.
 */
class ProductImport implements ProductImportInterface
{
    public function __construct(
        private readonly ImportService $importService
    ) {
    }

    /**
     * @inheritDoc
     */
    public function import(array $products, ?ImportSettingsInterface $settings = null): ImportResponseInterface
    {
        return $this->importService->import($products, $settings);
    }
}
