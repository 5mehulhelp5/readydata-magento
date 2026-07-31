<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Indexer;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Indexer\CacheContext;
use Magento\Framework\Indexer\IndexerRegistry;
use ReadyData\Import\Logger\Logger;
use ReadyData\Import\Model\Config;

/**
 * Post-import index and cache maintenance for the affected products.
 *
 * Indexers in "Update by Schedule" mode are skipped: direct SQL writes
 * fire the mview DB triggers, so the changelog already has the IDs.
 */
class InvalidationHandler
{
    /**
     * Indexers touched by product data/stock changes. Extendable via di.xml.
     *
     * @var string[]
     */
    private readonly array $indexerIds;

    /**
     * @param string[] $indexerIds
     */
    public function __construct(
        private readonly Config $config,
        private readonly IndexerRegistry $indexerRegistry,
        private readonly CacheContext $cacheContext,
        private readonly EventManager $eventManager,
        private readonly Logger $logger,
        array $indexerIds = [
            'catalog_product_attribute',
            'catalog_product_category',
            'catalog_product_price',
            'cataloginventory_stock',
            'inventory',
            'catalogsearch_fulltext',
            // The image role attributes are used_in_product_listing, so a new
            // base image only reaches category listings once flat rebuilds.
            'catalog_product_flat',
        ]
    ) {
        $this->indexerIds = $indexerIds;
    }

    /**
     * @param int[] $entityIds affected product entity IDs
     * @param int[] $categoryIds categories whose product set changed
     */
    public function execute(array $entityIds, array $categoryIds = []): void
    {
        $entityIds = array_values(array_unique($entityIds));
        if (!$entityIds) {
            return;
        }

        match ($this->config->getIndexingMode()) {
            Config::INDEXING_MODE_PARTIAL => $this->reindexPartial($entityIds),
            Config::INDEXING_MODE_INVALIDATE => $this->invalidate(),
            default => null,
        };

        if ($this->config->isCleanCache()) {
            $this->cacheContext->registerEntities(Product::CACHE_TAG, $entityIds);
            if ($categoryIds) {
                $this->cacheContext->registerEntities(
                    Category::CACHE_TAG,
                    array_values(array_unique($categoryIds))
                );
            }
            $this->eventManager->dispatch('clean_cache_by_tags', ['object' => $this->cacheContext]);
        }
    }

    /**
     * @param int[] $entityIds
     */
    private function reindexPartial(array $entityIds): void
    {
        foreach ($this->indexerIds as $indexerId) {
            try {
                $indexer = $this->indexerRegistry->get($indexerId);
                if (!$indexer->isScheduled()) {
                    $indexer->reindexList($entityIds);
                }
            } catch (\Throwable $e) {
                // Missing indexer (e.g. MSI not installed) is not an import failure.
                $this->logger->warning(
                    sprintf('Partial reindex of "%s" skipped: %s', $indexerId, $e->getMessage())
                );
            }
        }
    }

    private function invalidate(): void
    {
        foreach ($this->indexerIds as $indexerId) {
            try {
                $this->indexerRegistry->get($indexerId)->invalidate();
            } catch (\Throwable $e) {
                $this->logger->warning(
                    sprintf('Invalidation of "%s" skipped: %s', $indexerId, $e->getMessage())
                );
            }
        }
    }
}
