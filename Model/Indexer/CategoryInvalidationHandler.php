<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Indexer;

use Magento\Catalog\Model\Category;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Indexer\CacheContext;
use Magento\Framework\Indexer\IndexerRegistry;
use ReadyData\Import\Logger\Logger;
use ReadyData\Import\Model\Config;
use ReadyData\Import\Model\ResourceModel\Category as CategoryResource;

/**
 * Post-sync index and cache maintenance for changed categories.
 *
 * Deliberately much thinner than the product InvalidationHandler, because most
 * of the work is already done: category writes go through the category model,
 * whose afterSave() registers a reindex as a commit callback, so
 * catalog_category_flat and catalog_category_product are handled on our own
 * commit. Re-running them here would be a second full reindex for nothing.
 *
 * What core does NOT do on a category save is touch the search index — the
 * search document carries category_ids and position_category_N — and it only
 * cleans the FPC tag of the saved category itself, not of the descendants whose
 * url_path just changed underneath them.
 */
class CategoryInvalidationHandler
{
    /**
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
        private readonly CategoryResource $categoryResource,
        private readonly Logger $logger,
        array $indexerIds = [
            'catalogsearch_fulltext',
        ]
    ) {
        $this->indexerIds = $indexerIds;
    }

    /**
     * @param int[] $categoryIds categories created or updated by this sync
     */
    public function execute(array $categoryIds): void
    {
        $categoryIds = array_values(array_unique(array_filter($categoryIds)));
        if (!$categoryIds) {
            return;
        }

        if ($this->config->getIndexingMode() !== Config::INDEXING_MODE_NONE) {
            foreach ($this->indexerIds as $indexerId) {
                try {
                    $indexer = $this->indexerRegistry->get($indexerId);
                    // Scheduled indexers already have the IDs from the mview
                    // triggers the category save fired.
                    if (!$indexer->isScheduled()) {
                        $indexer->invalidate();
                    }
                } catch (\Throwable $e) {
                    // A missing indexer is not a sync failure.
                    $this->logger->warning(
                        sprintf('Category sync: invalidation of "%s" skipped: %s', $indexerId, $e->getMessage())
                    );
                }
            }
        }

        if (!$this->config->isCleanCache()) {
            return;
        }

        // A name or url_key change rewrites url_path all the way down, so every
        // descendant's cached page is stale too.
        $descendantIds = $this->categoryResource->getDescendantIds($categoryIds);
        $this->cacheContext->registerEntities(
            Category::CACHE_TAG,
            array_values(array_unique(array_merge($categoryIds, $descendantIds)))
        );
        $this->eventManager->dispatch('clean_cache_by_tags', ['object' => $this->cacheContext]);
    }
}
