<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model;

use ReadyData\Import\Api\CategorySyncInterface;
use ReadyData\Import\Api\Data\CategorySyncResponseInterface;
use ReadyData\Import\Api\Data\ImportSettingsInterface;

/**
 * Webapi entry point; all logic lives in CategorySyncService.
 */
class CategorySync implements CategorySyncInterface
{
    public function __construct(
        private readonly CategorySyncService $categorySyncService
    ) {
    }

    /**
     * @inheritDoc
     */
    public function sync(array $categories, ?ImportSettingsInterface $settings = null): CategorySyncResponseInterface
    {
        return $this->categorySyncService->sync($categories, $settings);
    }
}
