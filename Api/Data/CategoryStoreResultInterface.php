<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Api\Data;

/**
 * What happened to one category in one of the store scopes its payload named
 * beyond the request's own — one entry per `store_values` block.
 *
 * The request's own scope is NOT repeated here: the category's top-level result
 * is that scope's outcome. A caller recording one history row per (category,
 * scope) reads the category result plus this list, with nothing described
 * twice.
 *
 * Carries the endpoint's own vocabulary rather than the product endpoint's
 * ({@see StoreResultInterface}), because `unchanged` is load-bearing here: a
 * replayed payload writing nothing in a scope is the property that makes the
 * whole endpoint free to re-run, and collapsing it into "skipped" would hide
 * that.
 *
 * @api
 */
interface CategoryStoreResultInterface
{
    public const STORE_ID = 'store_id';
    public const STATUS = 'status';
    public const REASON = 'reason';
    public const MESSAGES = 'messages';

    /** Values in this scope differed and were written. */
    public const STATUS_UPDATED = 'updated';
    /** Nothing differed — no save at all, so a replay in this scope is free. */
    public const STATUS_UNCHANGED = 'unchanged';
    /** Nothing was attempted; `reason` says why. */
    public const STATUS_SKIPPED = 'skipped';
    /**
     * The category's own write failed, so nothing survives in any of its
     * scopes — each category is one transaction. Never about the scope alone.
     */
    public const STATUS_ERROR = 'error';

    /**
     * The store view this result is about; never 0, which the category itself
     * writes.
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
     * One of: updated, unchanged, skipped, error.
     *
     * @return string
     */
    public function getStatus(): string;

    /**
     * @param string $status
     * @return $this
     */
    public function setStatus(string $status): self;

    /**
     * Machine-readable reason code accompanying a skip — the same vocabulary
     * {@see CategorySyncResultInterface} uses (`wrong_store_root`,
     * `unknown_store`, `store_scope_structural_change`, …). Null otherwise.
     *
     * @return string|null
     */
    public function getReason(): ?string;

    /**
     * @param string|null $reason
     * @return $this
     */
    public function setReason(?string $reason): self;

    /**
     * Warnings and errors raised writing this scope, untagged — the scope is
     * already named by store_id.
     *
     * @return string[]
     */
    public function getMessages(): array;

    /**
     * @param string[] $messages
     * @return $this
     */
    public function setMessages(array $messages): self;
}
