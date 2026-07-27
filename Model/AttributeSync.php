<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model;

use ReadyData\Import\Api\AttributeSyncInterface;
use ReadyData\Import\Api\Data\AttributeSyncResponseInterface;

/**
 * Thin Web API facade; all logic lives in AttributeSyncService.
 */
class AttributeSync implements AttributeSyncInterface
{
    public function __construct(
        private readonly AttributeSyncService $attributeSyncService
    ) {
    }

    /**
     * @inheritDoc
     */
    public function sync(array $attributes): AttributeSyncResponseInterface
    {
        return $this->attributeSyncService->sync($attributes);
    }
}
