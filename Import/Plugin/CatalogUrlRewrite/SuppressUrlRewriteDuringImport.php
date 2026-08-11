<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Plugin\CatalogUrlRewrite;

use Magento\CatalogUrlRewrite\Observer\ProductProcessUrlRewriteSavingObserver;
use Magento\Framework\Event\Observer as EventObserver;
use ReadyData\Import\Model\ImportState;

/**
 * Stands the native product URL-rewrite observer down while a ReadyData import
 * is running. The importer regenerates rewrites itself (UrlRewriteProcessor),
 * so letting this observer also run on the importer's `catalog_product_save_after`
 * would duplicate/conflict. Outside an import it runs normally.
 */
class SuppressUrlRewriteDuringImport
{
    public function __construct(
        private readonly ImportState $importState
    ) {
    }

    public function aroundExecute(
        ProductProcessUrlRewriteSavingObserver $subject,
        callable $proceed,
        EventObserver $observer
    ): void {
        if ($this->importState->isImporting()) {
            return;
        }

        $proceed($observer);
    }
}
