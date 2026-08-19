<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use ReadyData\Import\Logger\Logger;
use ReadyData\Import\Model\Media\Cleanup\DeletedProductMedia;
use ReadyData\Import\Model\Media\Cleanup\MediaCleanupService;

/**
 * Deletes the files a just-removed product held, if nothing else references
 * them.
 *
 * Bound to `catalog_product_delete_after_done`, which
 * ResourceModel\Product::delete() dispatches AFTER EntityManager::delete()
 * returns — that is, after its transaction has committed. That timing is the
 * whole point: `catalog_product_delete_after` fires inside the transaction, and
 * a file removed there could not be restored if the delete then rolled back.
 *
 * The reference check still runs. Two products fed the same image URL resolve to
 * the same path on disk, so deleting one must not take the other's image with
 * it — which is exactly the case a per-product cleanup gets wrong if it trusts
 * "this product had it" to mean "nobody has it".
 */
class CleanUpProductMediaAfterDelete implements ObserverInterface
{
    public function __construct(
        private readonly MediaCleanupService $mediaCleanup,
        private readonly DeletedProductMedia $deletedMedia,
        private readonly Logger $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->mediaCleanup->isEnabled()) {
            return;
        }

        try {
            $product = $observer->getEvent()->getData('product');
            if ($product === null) {
                return;
            }
            $paths = $this->deletedMedia->take((int)$product->getId());
            if ($paths) {
                $this->mediaCleanup->deleteUnreferenced($paths);
            }
        } catch (\Throwable $e) {
            // The product is already deleted and committed; failing here would
            // report a successful delete as broken.
            $this->logger->error(
                sprintf('Media cleanup after a product delete failed: %s', $e->getMessage()),
                ['exception' => $e]
            );
        }
    }
}
