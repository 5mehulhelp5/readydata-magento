<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Indexer;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use ReadyData\Import\Logger\Logger;

/**
 * Post-sync cache and index maintenance for attribute-definition changes.
 *
 * This is a different shape from the product InvalidationHandler: an attribute
 * change has no product IDs to reindex, so affected indexers are marked
 * invalid (cron/scheduled rebuild) rather than partially reindexed. EavSetup
 * already clears the EAV cache on write; we additionally clean config/FPC/block
 * cache and invalidate the indexers whose output depends on attribute metadata
 * (notably the flat catalog, whose columns only appear after a rebuild).
 */
class AttributeInvalidationHandler
{
    /**
     * @var string[]
     */
    private readonly array $cacheTypes;

    /**
     * @var string[]
     */
    private readonly array $indexerIds;

    /**
     * @param string[] $cacheTypes
     * @param string[] $indexerIds
     */
    public function __construct(
        private readonly TypeListInterface $cacheTypeList,
        private readonly IndexerRegistry $indexerRegistry,
        private readonly Logger $logger,
        array $cacheTypes = ['eav', 'config', 'full_page', 'block_html'],
        array $indexerIds = [
            'catalog_product_attribute',
            'catalogsearch_fulltext',
            'catalog_product_flat',
        ]
    ) {
        $this->cacheTypes = $cacheTypes;
        $this->indexerIds = $indexerIds;
    }

    /**
     * Invalidate caches/indexers after one or more attribute definitions
     * changed. No-op when nothing changed.
     */
    public function execute(bool $changed): void
    {
        if (!$changed) {
            return;
        }

        foreach ($this->cacheTypes as $cacheType) {
            try {
                $this->cacheTypeList->invalidate($cacheType);
            } catch (\Throwable $e) {
                $this->logger->warning(
                    sprintf('Attribute sync: cache type "%s" invalidation skipped: %s', $cacheType, $e->getMessage())
                );
            }
        }

        foreach ($this->indexerIds as $indexerId) {
            try {
                $this->indexerRegistry->get($indexerId)->invalidate();
            } catch (\Throwable $e) {
                // Missing indexer (e.g. flat disabled) is not a sync failure.
                $this->logger->warning(
                    sprintf('Attribute sync: invalidation of "%s" skipped: %s', $indexerId, $e->getMessage())
                );
            }
        }
    }
}
