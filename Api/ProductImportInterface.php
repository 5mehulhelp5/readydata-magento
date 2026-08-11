<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Api;

use ReadyData\Import\Api\Data\ImportResponseInterface;
use ReadyData\Import\Api\Data\ImportSettingsInterface;
use ReadyData\Import\Api\Data\ProductInterface;

/**
 * Bulk product import entry point.
 *
 * Accepts an arbitrary number of products; the service splits them into
 * configurable batches internally (default 500 per batch/transaction).
 *
 * @api
 */
interface ProductImportInterface
{
    /**
     * Import (create or update) products in bulk via direct database writes.
     *
     * A refusal because another request holds a lock this one needs is an
     * ImportLockedException — a LocalizedException that renders as **429**
     * rather than 400, because it is the one failure here worth retrying
     * unchanged. Everything else stays a 400.
     *
     * @param \ReadyData\Import\Api\Data\ProductInterface[] $products
     * @param \ReadyData\Import\Api\Data\ImportSettingsInterface|null $settings
     * @return \ReadyData\Import\Api\Data\ImportResponseInterface
     * @throws \ReadyData\Import\Model\Exception\ImportLockedException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function import(array $products, ?ImportSettingsInterface $settings = null): ImportResponseInterface;
}
