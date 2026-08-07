<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Api\Data;

/**
 * Category sync response: summary counters + per-category results.
 *
 * @api
 */
interface CategorySyncResponseInterface
{
    public const RECEIVED = 'received';
    public const CREATED = 'created';
    public const UPDATED = 'updated';
    public const UNCHANGED = 'unchanged';
    public const DELETED = 'deleted';
    public const SKIPPED = 'skipped';
    public const FAILED = 'failed';
    public const ELAPSED_MS = 'elapsed_ms';
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
    public function getUnchanged(): int;

    /**
     * @param int $unchanged
     * @return $this
     */
    public function setUnchanged(int $unchanged): self;

    /**
     * @return int
     */
    public function getDeleted(): int;

    /**
     * @param int $deleted
     * @return $this
     */
    public function setDeleted(int $deleted): self;

    /**
     * @return int
     */
    public function getSkipped(): int;

    /**
     * @param int $skipped
     * @return $this
     */
    public function setSkipped(int $skipped): self;

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
     * @return \ReadyData\Import\Api\Data\CategorySyncResultInterface[]
     */
    public function getResults(): array;

    /**
     * @param \ReadyData\Import\Api\Data\CategorySyncResultInterface[] $results
     * @return $this
     */
    public function setResults(array $results): self;
}
