<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Api\Data;

/**
 * What happened to one product in one of the store scopes its payload named
 * beyond the request's own — one entry per resolved `store_values` block.
 *
 * The request's own scope is NOT repeated here: the product's top-level result
 * is that scope's outcome (its status, its entity ID, its unscoped messages),
 * and the response's `store_id` says which scope that was. A caller recording
 * one history row per (product, scope) therefore reads the product result plus
 * this list, with no entry described twice.
 *
 * @api
 */
interface StoreResultInterface
{
    public const STORE_ID = 'store_id';
    public const STATUS = 'status';
    public const MESSAGES = 'messages';

    /** Values or clears were applied in this scope. */
    public const STATUS_WRITTEN = 'written';
    /**
     * The scope resolved but nothing was applied in it — every value it carried
     * was refused, or the block named a scope and carried nothing. The messages
     * say which; an empty message list means the block was empty.
     */
    public const STATUS_SKIPPED = 'skipped';
    /**
     * The product failed, so nothing was written in any of its scopes. A batch
     * is one transaction, so a failure anywhere in it rolls back every scope —
     * this status is never about the scope alone.
     */
    public const STATUS_ERROR = 'error';

    /**
     * The store view this result is about. 0 is the default scope, which a
     * block may name explicitly when the request scope is a store view.
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
     * One of: written, skipped, error.
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
     * Warnings and errors raised while writing this scope, untagged — the
     * scope is already named by store_id.
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
