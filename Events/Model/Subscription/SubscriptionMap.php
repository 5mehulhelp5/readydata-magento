<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Model\Subscription;

use Magento\Framework\App\Cache\Type\Config as ConfigCache;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * The `event_code => subscription[]` lookup every hook performs first.
 *
 * This is the check that decides what an unsubscribed store pays. It has to be
 * O(1) and allocation-free in the negative case, because the catalogue registers
 * ~100 hooks and most stores will subscribe to a handful. One lazy load per
 * request from the config cache, then an isset() per dispatch.
 *
 * Cached rather than queried because the alternative is a SELECT on every
 * dispatched catalogue event; invalidated on subscription writes, so a REST
 * change takes effect on the next request with no deploy.
 */
class SubscriptionMap
{
    public const CACHE_KEY = 'readydata_events_subscription_map';

    /** @var array<string, Subscription[]>|null */
    private ?array $map = null;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ConfigCache $cache,
        private readonly SerializerInterface $serializer
    ) {
    }

    /**
     * The hot path. Nothing here allocates when the code is not subscribed.
     */
    public function isSubscribed(string $eventCode): bool
    {
        if ($this->map === null) {
            $this->load();
        }

        return isset($this->map[$eventCode]);
    }

    /** @return Subscription[] */
    public function forCode(string $eventCode): array
    {
        if ($this->map === null) {
            $this->load();
        }

        return $this->map[$eventCode] ?? [];
    }

    /** @return array<string, Subscription[]> */
    public function all(): array
    {
        if ($this->map === null) {
            $this->load();
        }

        return $this->map;
    }

    /**
     * Drop the cached map and the in-process copy.
     *
     * Called after every subscription or subscriber write. Forgetting this is
     * how a REST change appears to do nothing, so writes go through the
     * repositories rather than raw SQL.
     */
    public function invalidate(): void
    {
        $this->map = null;
        $this->cache->remove(self::CACHE_KEY);
    }

    private function load(): void
    {
        $cached = $this->cache->load(self::CACHE_KEY);
        if ($cached !== false) {
            $this->map = $this->hydrate((array)$this->serializer->unserialize($cached));
            return;
        }

        $rows = $this->fetchRows();
        $this->cache->save(
            $this->serializer->serialize($rows),
            self::CACHE_KEY,
            [ConfigCache::CACHE_TAG]
        );
        $this->map = $this->hydrate($rows);
    }

    /**
     * Only enabled subscriptions belonging to an enabled subscriber. A disabled
     * destination makes its subscriptions inert without deleting them, so
     * turning delivery off and on again does not lose the configuration.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchRows(): array
    {
        $connection = $this->resource->getConnection();
        $subscriptionTable = $this->resource->getTableName('readydata_event_subscription');
        $subscriberTable = $this->resource->getTableName('readydata_event_subscriber');

        // The tables are created by db_schema.xml on setup:upgrade. Capture runs
        // on requests that may precede that on a partially upgraded deployment,
        // and a hard failure there would take down page renders over a feature
        // nobody has configured yet.
        if (!$connection->isTableExists($subscriptionTable)) {
            return [];
        }

        $select = $connection->select()
            ->from(['s' => $subscriptionTable])
            ->join(['sub' => $subscriberTable], 's.subscriber_id = sub.subscriber_id', [])
            ->where('s.enabled = ?', 1)
            ->where('sub.enabled = ?', 1);

        return $connection->fetchAll($select);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, Subscription[]>
     */
    private function hydrate(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $subscription = Subscription::fromRow($row);
            $map[$subscription->eventCode][] = $subscription;
        }

        return $map;
    }
}
