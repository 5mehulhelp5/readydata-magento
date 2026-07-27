<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Api;

use ReadyData\Import\Api\Data\AttributeSyncResponseInterface;

/**
 * Attribute-definition sync entry point.
 *
 * Provisions product EAV attribute *definitions* on this instance to match the
 * definitions authored in the calling application (the system of record).
 * Standalone: it needs no product import before or after, and is normally
 * called as a pre-flight step so the attributes a feed references already exist.
 *
 * Behaviour is create-or-update-to-source: a missing attribute is created, an
 * existing one has its safe (non-structural) columns updated to match, and a
 * difference in a structural column (backend_type/frontend_input/is_global) is
 * reported for a deliberate migration rather than applied. Gated by the
 * readydata_import/attributes/auto_create switch.
 *
 * @api
 */
interface AttributeSyncInterface
{
    /**
     * Create or update the given product attribute definitions.
     *
     * @param \ReadyData\Import\Api\Data\AttributeDefinitionInterface[] $attributes
     * @return \ReadyData\Import\Api\Data\AttributeSyncResponseInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function sync(array $attributes): AttributeSyncResponseInterface;
}
