<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Processor;

use ReadyData\Import\Model\BatchContext;
use ReadyData\Import\Model\Cache\StoreWebsiteMap;
use ReadyData\Import\Model\ResourceModel\Website;

/**
 * Assigns products to websites (additive). New products with no websites
 * in the payload go to the default website; existing products are left
 * unchanged when websites are omitted.
 *
 * Publishes to the context data bag:
 *  - "website_ids": array<string sku, int[]> website IDs the product is
 *    assigned to after this batch; consumed by UrlRewriteProcessor.
 */
class WebsiteProcessor implements ProcessorInterface
{
    public const CONTEXT_WEBSITE_IDS = 'website_ids';

    public function __construct(
        private readonly Website $websiteResource,
        private readonly StoreWebsiteMap $storeWebsiteMap
    ) {
    }

    public function process(BatchContext $context): void
    {
        $rows = [];
        $websiteIdsBySku = [];
        $skusNeedingLookup = [];

        foreach ($context->getValidProducts() as $sku => $product) {
            $entityId = $context->getEntityId($sku);
            if ($entityId === null) {
                continue;
            }

            $websiteCodes = $product->getWebsites();
            if ($websiteCodes === null) {
                if ($context->isExisting($sku)) {
                    $skusNeedingLookup[$sku] = $entityId;
                    continue;
                }
                $websiteIds = [$this->storeWebsiteMap->getDefaultWebsiteId()];
            } else {
                $websiteIds = [];
                foreach ($websiteCodes as $code) {
                    $websiteId = $this->storeWebsiteMap->getWebsiteId($code);
                    if ($websiteId === null) {
                        $context->addMessage($sku, sprintf('Unknown website code "%s" skipped.', $code));
                        continue;
                    }
                    $websiteIds[] = $websiteId;
                }
            }

            foreach ($websiteIds as $websiteId) {
                $rows[] = ['product_id' => $entityId, 'website_id' => $websiteId];
            }
            $websiteIdsBySku[$sku] = $websiteIds;
        }

        // Existing products with no websites in the payload keep their
        // current assignments; look them up for downstream processors.
        if ($skusNeedingLookup) {
            $assignments = $this->websiteResource->getAssignments(array_values($skusNeedingLookup));
            foreach ($skusNeedingLookup as $sku => $entityId) {
                $websiteIdsBySku[$sku] = $assignments[$entityId] ?? [];
            }
        }

        $this->websiteResource->assign($rows);
        $context->set(self::CONTEXT_WEBSITE_IDS, $websiteIdsBySku);
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function getSortOrder(): int
    {
        return 400;
    }
}
