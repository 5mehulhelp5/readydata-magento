<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Api;

use ReadyData\Import\Api\Data\CategorySyncResponseInterface;
use ReadyData\Import\Api\Data\ImportSettingsInterface;

/**
 * Category sync entry point.
 *
 * Makes the category tree on this instance match the categories authored in the
 * calling application (the system of record). Standalone: it needs no product
 * import before or after, and is normally called as a pre-flight step so the
 * categories a product feed references already exist with the right properties.
 *
 * The product import can already create categories implicitly, but only as bare
 * nodes with a name; this endpoint owns their properties and reports per-category
 * what happened.
 *
 * Behaviour is create-or-update-to-source, and purely additive: a category the
 * caller stops sending is never deactivated or deleted. Structural changes are
 * deliberately narrow — a missing category below an existing root is created,
 * but roots are never created, and reparenting an existing category is reported
 * rather than applied. Gated by the readydata_import/categories/enabled switch.
 *
 * @api
 */
interface CategorySyncInterface
{
    /**
     * Create or update the given categories.
     *
     * @param \ReadyData\Import\Api\Data\CategoryDefinitionInterface[] $categories
     * @param \ReadyData\Import\Api\Data\ImportSettingsInterface|null $settings
     * @return \ReadyData\Import\Api\Data\CategorySyncResponseInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function sync(array $categories, ?ImportSettingsInterface $settings = null): CategorySyncResponseInterface;
}
