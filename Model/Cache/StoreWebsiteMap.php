<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Cache;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;

/**
 * Request-scoped cache of store/website code => ID maps, read straight
 * from the DB to avoid store model overhead.
 */
class StoreWebsiteMap
{
    private ?array $storeIdByCode = null;
    private ?array $websiteIdByCode = null;
    private ?array $storeIdsByWebsiteId = null;
    private ?array $websiteStoreIds = null;
    private ?array $rootCategoryIdByStoreId = null;
    private ?int $defaultWebsiteId = null;

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Resolve a store view code to its ID; null/"admin" means global scope (0).
     *
     * @throws LocalizedException on unknown code
     */
    public function resolveStoreId(?string $storeViewCode): int
    {
        if ($storeViewCode === null || $storeViewCode === '' || $storeViewCode === 'admin') {
            return 0;
        }

        $storeId = $this->getStoreMap()[$storeViewCode] ?? null;
        if ($storeId === null) {
            throw new LocalizedException(__('Unknown store view code "%1".', $storeViewCode));
        }

        return $storeId;
    }

    /**
     * Resolve a scope given either form, ID first — a caller that holds the ID
     * should not have to translate it back into a code for us to translate it
     * forward again. Neither given means the default scope.
     *
     * @throws LocalizedException on an unknown ID or code
     */
    public function resolveScopeStoreId(?int $storeId, ?string $storeViewCode): int
    {
        if ($storeId === null) {
            return $this->resolveStoreId($storeViewCode);
        }
        if (!$this->hasStoreId($storeId)) {
            throw new LocalizedException(__('Unknown store view ID %1.', $storeId));
        }

        return $storeId;
    }

    /**
     * Like {@see resolveScopeStoreId()} but answers null instead of throwing,
     * for scopes that arrive per payload item: one unresolvable store view
     * there is that item's problem to report, not the whole request's to fail
     * on.
     */
    public function findScopeStoreId(?int $storeId, ?string $storeViewCode): ?int
    {
        if ($storeId !== null) {
            return $this->hasStoreId($storeId) ? $storeId : null;
        }
        if ($storeViewCode === null || $storeViewCode === '') {
            return null;
        }
        if ($storeViewCode === 'admin') {
            return 0;
        }

        return $this->getStoreMap()[$storeViewCode] ?? null;
    }

    /**
     * Whether the ID addresses a real scope. 0 is the admin scope: not a store
     * view, but a scope values can be written in, which is what callers ask.
     */
    public function hasStoreId(int $storeId): bool
    {
        return $storeId === 0 || in_array($storeId, $this->getStoreMap(), true);
    }

    public function getWebsiteId(string $websiteCode): ?int
    {
        return $this->getWebsiteMap()[$websiteCode] ?? null;
    }

    /**
     * @return array<string, int> website code => ID (admin website excluded)
     */
    public function getWebsiteMap(): array
    {
        if ($this->websiteIdByCode === null) {
            $connection = $this->resourceConnection->getConnection();
            $select = $connection->select()
                ->from($this->resourceConnection->getTableName('store_website'), ['code', 'website_id'])
                ->where('website_id > 0');
            $this->websiteIdByCode = array_map('intval', $connection->fetchPairs($select));
        }

        return $this->websiteIdByCode;
    }

    public function getDefaultWebsiteId(): int
    {
        if ($this->defaultWebsiteId === null) {
            $connection = $this->resourceConnection->getConnection();
            $select = $connection->select()
                ->from($this->resourceConnection->getTableName('store_website'), 'website_id')
                ->where('is_default = 1');
            $this->defaultWebsiteId = (int)$connection->fetchOne($select);
        }

        return $this->defaultWebsiteId;
    }

    /**
     * Active store view IDs belonging to the given websites (for URL rewrites).
     *
     * @param int[] $websiteIds
     * @return int[]
     */
    public function getStoreIdsForWebsites(array $websiteIds): array
    {
        if ($this->storeIdsByWebsiteId === null) {
            $connection = $this->resourceConnection->getConnection();
            $select = $connection->select()
                ->from($this->resourceConnection->getTableName('store'), ['website_id', 'store_id'])
                ->where('store_id > 0')
                ->where('is_active = 1');
            $this->storeIdsByWebsiteId = [];
            foreach ($connection->fetchAll($select) as $row) {
                $this->storeIdsByWebsiteId[(int)$row['website_id']][] = (int)$row['store_id'];
            }
        }

        $storeIds = [];
        foreach ($websiteIds as $websiteId) {
            $storeIds[] = $this->storeIdsByWebsiteId[$websiteId] ?? [];
        }

        return array_values(array_unique(array_merge(...$storeIds ?: [[]])));
    }

    /**
     * All store view IDs of the website containing the given store view,
     * including the view itself and inactive views (website-scoped values
     * must not go stale on views activated later).
     *
     * @return int[]
     */
    public function getWebsiteStoreIds(int $storeId): array
    {
        if ($this->websiteStoreIds === null) {
            $connection = $this->resourceConnection->getConnection();
            $select = $connection->select()
                ->from($this->resourceConnection->getTableName('store'), ['website_id', 'store_id'])
                ->where('store_id > 0');
            $storeIdsByWebsiteId = [];
            $websiteIdByStoreId = [];
            foreach ($connection->fetchAll($select) as $row) {
                $storeIdsByWebsiteId[(int)$row['website_id']][] = (int)$row['store_id'];
                $websiteIdByStoreId[(int)$row['store_id']] = (int)$row['website_id'];
            }
            $this->websiteStoreIds = [];
            foreach ($websiteIdByStoreId as $id => $websiteId) {
                $this->websiteStoreIds[$id] = $storeIdsByWebsiteId[$websiteId];
            }
        }

        return $this->websiteStoreIds[$storeId] ?? [$storeId];
    }

    /**
     * Root category of the store view's store group — the top of the category
     * tree that store actually shows.
     *
     * @throws LocalizedException when the store view has no root category, which
     *         a caller cannot fix per category: every store-scoped write would
     *         be unverifiable, so the request fails instead.
     */
    public function getRootCategoryId(int $storeId): int
    {
        if ($this->rootCategoryIdByStoreId === null) {
            $connection = $this->resourceConnection->getConnection();
            $select = $connection->select()
                ->from(['s' => $this->resourceConnection->getTableName('store')], ['store_id'])
                ->join(
                    ['g' => $this->resourceConnection->getTableName('store_group')],
                    'g.group_id = s.group_id',
                    ['root_category_id']
                )
                ->where('s.store_id > 0');
            $this->rootCategoryIdByStoreId = array_map('intval', $connection->fetchPairs($select));
        }

        // root_category_id is NOT NULL DEFAULT 0, so 0 is the "not configured"
        // sentinel and indistinguishable from an unknown store here.
        $rootCategoryId = $this->rootCategoryIdByStoreId[$storeId] ?? 0;
        if ($rootCategoryId <= 0) {
            throw new LocalizedException(
                __('Store view ID %1 has no root category configured.', $storeId)
            );
        }

        return $rootCategoryId;
    }

    /**
     * @return array<string, int> store view code => ID
     */
    private function getStoreMap(): array
    {
        if ($this->storeIdByCode === null) {
            $connection = $this->resourceConnection->getConnection();
            $select = $connection->select()
                ->from($this->resourceConnection->getTableName('store'), ['code', 'store_id'])
                ->where('store_id > 0');
            $this->storeIdByCode = array_map('intval', $connection->fetchPairs($select));
        }

        return $this->storeIdByCode;
    }
}
