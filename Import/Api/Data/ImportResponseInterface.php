<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Api\Data;

/**
 * Bulk import response: summary counters + per-product results.
 *
 * @api
 */
interface ImportResponseInterface
{
    public const RECEIVED = 'received';
    public const CREATED = 'created';
    public const UPDATED = 'updated';
    public const FAILED = 'failed';
    public const ELAPSED_MS = 'elapsed_ms';
    public const STORE_ID = 'store_id';
    public const RESULTS = 'results';

    /**
     * @return int
     */
    public function getReceived(): int;

    /**
     * @param int $received
     * @return $this
     */
    public function setReceived(int $received): self;

    /**
     * @return int
     */
    public function getCreated(): int;

    /**
     * @param int $created
     * @return $this
     */
    public function setCreated(int $created): self;

    /**
     * @return int
     */
    public function getUpdated(): int;

    /**
     * @param int $updated
     * @return $this
     */
    public function setUpdated(int $updated): self;

    /**
     * @return int
     */
    public function getFailed(): int;

    /**
     * @param int $failed
     * @return $this
     */
    public function setFailed(int $failed): self;

    /**
     * @return int
     */
    public function getElapsedMs(): int;

    /**
     * @param int $elapsedMs
     * @return $this
     */
    public function setElapsedMs(int $elapsedMs): self;

    /**
     * The scope the request was actually run in, as the server resolved it
     * from `settings` — 0 for the default scope. It is what every
     * `results[].status` and every unscoped message is about, and it is what a
     * caller attributing history rows needs, since `/rest/V1/...` resolves
     * against the default store view rather than the admin scope and only
     * `settings` overrides that.
     *
     * @return int
     */
    public function getStoreId(): int;

    /**
     * @param int $storeId
     * @return $this
     */
    public function setStoreId(int $storeId): self;

    /**
     * @return \ReadyData\Import\Api\Data\ImportResultInterface[]
     */
    public function getResults(): array;

    /**
     * @param \ReadyData\Import\Api\Data\ImportResultInterface[] $results
     * @return $this
     */
    public function setResults(array $results): self;
}
