<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Plugin\CatalogInventory;

use Magento\CatalogInventory\Observer\SaveInventoryDataObserver;
use Magento\Framework\Event\Observer as EventObserver;
use ReadyData\Import\Model\ImportState;

/**
 * Stands the native inventory-save observer down while a ReadyData import is
 * running. The importer writes stock itself (StockProcessor), so letting this
 * observer also run on the importer's `catalog_product_save_after` would
 * duplicate/conflict. Outside an import it runs normally.
 */
class SuppressInventorySaveDuringImport
{
    public function __construct(
        private readonly ImportState $importState
    ) {
    }

    public function aroundExecute(
        SaveInventoryDataObserver $subject,
        callable $proceed,
        EventObserver $observer
    ): void {
        if ($this->importState->isImporting()) {
            return;
        }

        $proceed($observer);
    }
}
