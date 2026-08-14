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
 * Behaviour is create-or-update-to-source, and nothing is ever inferred from
 * absence: a category the caller stops sending is never deactivated or deleted.
 * Every structural change is available but each has to be asked for explicitly —
 * a missing category (including a level-1 root) is created, a category is
 * reparented only when the payload names a destination through
 * parent_path/parent_category_id, and one is deleted only on a `delete` flag,
 * with a second flag before its descendants go with it. Gated by the
 * readydata_import/categories/enabled switch, with allow_move and allow_delete
 * gating the two destructive operations separately.
 *
 * @api
 */
interface CategorySyncInterface
{
    /**
     * Create or update the given categories.
     *
     * A refusal because another request holds a lock this one needs is an
     * ImportLockedException — a LocalizedException that renders as **429**
     * rather than 400, because it is the one failure here worth retrying
     * unchanged. Everything else stays a 400.
     *
     * @param \ReadyData\Import\Api\Data\CategoryDefinitionInterface[] $categories
     * @param \ReadyData\Import\Api\Data\ImportSettingsInterface|null $settings
     * @return \ReadyData\Import\Api\Data\CategorySyncResponseInterface
     * @throws \ReadyData\Import\Model\Exception\ImportLockedException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function sync(array $categories, ?ImportSettingsInterface $settings = null): CategorySyncResponseInterface;
}
