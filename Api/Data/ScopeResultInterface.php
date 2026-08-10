<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Api\Data;

/**
 * What happened to one entity in one of the store scopes its payload named
 * beyond the request's own — one entry per `store_values` block, in payload
 * order.
 *
 * One shape and one vocabulary for both endpoints: a caller recording history
 * rows per (entity, scope) reads the same four fields whether the entity was a
 * product or a category, and does not have to learn that "written" and
 * "updated" were the same outcome under two names.
 *
 * The request's own scope is NOT in this list. The entity's top-level result IS
 * that scope's outcome, and the response's `store_id` says which scope it was,
 * so nothing is described twice.
 *
 * @api
 */
interface ScopeResultInterface
{
    public const STORE_ID = 'store_id';
    public const STATUS = 'status';
    public const REASON = 'reason';
    public const MESSAGES = 'messages';

    /** Values or clears were applied in this scope. A clear counts. */
    public const STATUS_UPDATED = 'updated';
    /**
     * The scope resolved and nothing differed — no write at all, which is the
     * property that makes a replayed payload free. Reported by the category
     * endpoint, which compares before saving; the product endpoint upserts and
     * so never reports it.
     */
    public const STATUS_UNCHANGED = 'unchanged';
    /** Nothing was applied; `reason` and the messages say why. */
    public const STATUS_SKIPPED = 'skipped';
    /**
     * The entity itself failed, so nothing survives in any of its scopes —
     * one entity is one transaction. Never about the scope alone.
     */
    public const STATUS_ERROR = 'error';

    /**
     * The reason codes a SCOPE can be skipped with. Deliberately the same
     * strings {@see CategorySyncResultInterface} uses for the entry-level
     * equivalents — the vocabulary is the endpoint's, not the field's, and a
     * caller should not have to keep two mappings for one meaning.
     */
    public const REASON_UNKNOWN_STORE = 'unknown_store';
    /** The scope exists, but its storefront cannot show this entity. */
    public const REASON_WRONG_STORE_ROOT = 'wrong_store_root';
    /** The block itself is not something the endpoint will interpret. */
    public const REASON_INVALID_DEFINITION = 'invalid_definition';

    /**
     * The store view this result is about, or **null** when the block named a
     * scope that could not be resolved at all: there is no store view to
     * attribute it to, and reporting 0 would name the default scope, which is
     * the one scope this list never covers.
     *
     * @return int|null
     */
    public function getStoreId(): ?int;

    /**
     * @param int|null $storeId
     * @return $this
     */
    public function setStoreId(?int $storeId): self;

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
     * Machine-readable reason code accompanying a skip (`unknown_store`,
     * `wrong_store_root`, `invalid_definition`, …), null otherwise.
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
     * Warnings and errors raised for this scope, untagged — the scope is already
     * named by store_id.
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
