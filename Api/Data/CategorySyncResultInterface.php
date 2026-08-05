<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Api\Data;

/**
 * Per-category sync outcome.
 *
 * @api
 */
interface CategorySyncResultInterface
{
    public const PATH = 'path';
    public const ENTITY_ID = 'entity_id';
    public const STATUS = 'status';
    public const REASON = 'reason';
    public const MESSAGES = 'messages';

    public const STATUS_CREATED = 'created';
    public const STATUS_UPDATED = 'updated';
    public const STATUS_UNCHANGED = 'unchanged';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_ERROR = 'error';

    /**
     * Machine-readable reason codes accompanying skipped outcomes.
     */
    public const REASON_DISABLED = 'disabled';
    public const REASON_INVALID_DEFINITION = 'invalid_definition';
    public const REASON_UNKNOWN_ROOT = 'unknown_root';
    public const REASON_PARENT_NOT_FOUND = 'parent_not_found';
    public const REASON_AMBIGUOUS_PATH = 'ambiguous_path';
    public const REASON_ROOT_NOT_WRITABLE = 'root_not_writable';
    public const REASON_UNKNOWN_CATEGORY = 'unknown_category';
    public const REASON_RENAME_REQUIRES_CATEGORY_ID = 'rename_requires_category_id';
    public const REASON_MOVE_NOT_SUPPORTED = 'move_not_supported';
    public const REASON_STORE_SCOPE_STRUCTURAL_CHANGE = 'store_scope_structural_change';
    public const REASON_STALE_PARENT_PATH = 'stale_parent_path';
    public const REASON_PROTECTED_ATTRIBUTE = 'protected_attribute';
    public const REASON_ABORTED = 'aborted';

    /**
     * The path this entry was identified by, echoed back so a caller that sent
     * a category_id can still correlate the result.
     *
     * @return string
     */
    public function getPath(): string;

    /**
     * @param string $path
     * @return $this
     */
    public function setPath(string $path): self;

    /**
     * Resolved category ID, or null when the entry never resolved to a row.
     *
     * @return int|null
     */
    public function getEntityId(): ?int;

    /**
     * @param int|null $entityId
     * @return $this
     */
    public function setEntityId(?int $entityId): self;

    /**
     * One of: created, updated, unchanged, skipped, error.
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
     * Machine-readable reason code, or null.
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
     * Warnings and errors collected for this category.
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
