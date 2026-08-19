<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use ReadyData\Import\Logger\Logger;
use ReadyData\Import\Model\Cache\AttributeMetadataCache;
use ReadyData\Import\Model\Media\Cleanup\DeletedProductMedia;
use ReadyData\Import\Model\Media\Cleanup\MediaCleanupService;
use ReadyData\Import\Model\Processor\MediaProcessor;
use ReadyData\Import\Model\ResourceModel\ProductEntity;
use ReadyData\Import\Model\ResourceModel\ProductMediaGallery;

/**
 * Reads a product's gallery paths while they still exist, for
 * {@see CleanUpProductMediaAfterDelete} to act on once the delete has committed.
 *
 * The pair exists because core cleans up neither half of a product delete. The
 * rows are handled by registering core's own unwired Gallery\DeleteHandler (see
 * etc/di.xml); the FILES are what these observers are for.
 *
 * Reads nothing and remembers nothing when the store has not claimed ownership
 * of product media — see Config::ownsProductMedia().
 */
class CaptureProductMediaOnDelete implements ObserverInterface
{
    public function __construct(
        private readonly MediaCleanupService $mediaCleanup,
        private readonly DeletedProductMedia $deletedMedia,
        private readonly ProductMediaGallery $productMediaGallery,
        private readonly ProductEntity $productEntity,
        private readonly AttributeMetadataCache $attributeMetadataCache,
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
            $productId = (int)$product->getId();
            $linkId = (int)$product->getData($this->productEntity->getLinkField());
            if ($productId === 0 || $linkId === 0) {
                return;
            }

            $attribute = $this->attributeMetadataCache->get(MediaProcessor::MEDIA_GALLERY_CODE);
            if ($attribute === null) {
                return;
            }

            $paths = [];
            foreach ($this->productMediaGallery->getGallery([$linkId], $attribute['attribute_id']) as $rows) {
                foreach ($rows as $row) {
                    if ($row['file'] !== '') {
                        $paths[$row['file']] = true;
                    }
                }
            }

            $this->deletedMedia->remember($productId, array_keys($paths));
        } catch (\Throwable $e) {
            // A product delete must not fail because we wanted to tidy up after
            // it. Nothing is remembered, so the "after" observer simply finds
            // nothing and the files are left for the §9.1 report to surface.
            $this->logger->warning(
                sprintf('Could not read gallery paths before a product delete: %s', $e->getMessage())
            );
        }
    }
}
