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
    public const ROOT_CATEGORY_ID = 'root_category_id';
    public const STATUS = 'status';
    public const REASON = 'reason';
    public const MESSAGES = 'messages';
    public const STORE_RESULTS = 'store_results';

    public const STATUS_CREATED = 'created';
    public const STATUS_UPDATED = 'updated';
    public const STATUS_UNCHANGED = 'unchanged';
    public const STATUS_DELETED = 'deleted';
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
    public const REASON_WRONG_STORE_ROOT = 'wrong_store_root';
    public const REASON_UNKNOWN_CATEGORY = 'unknown_category';
    public const REASON_RENAME_REQUIRES_CATEGORY_ID = 'rename_requires_category_id';
    /**
     * A path implies a parent the category is not under, and the payload named no
     * destination. Reparenting is expressed by parent_path/parent_category_id, so
     * this stays what it always was: a mismatch nobody asked us to act on.
     */
    public const REASON_MOVE_NOT_SUPPORTED = 'move_not_supported';
    public const REASON_MOVE_REQUIRES_CATEGORY_ID = 'move_requires_category_id';
    public const REASON_MOVE_INTO_DESCENDANT = 'move_into_descendant';
    /**
     * A sibling under the parent the category would end up under already carries
     * the name it would land with. Nothing in the schema forbids that — there is
     * no unique key on (parent_id, name) — but it makes the path permanently
     * ambiguous, so the write refuses rather than creating the duplicate.
     */
    public const REASON_DESTINATION_NAME_TAKEN = 'destination_name_taken';
    /**
     * Likewise for url_key, where the backstop does exist (url_rewrite is unique
     * on (request_path, store_id)) but only fires deep inside the save as an
     * opaque exception. Checked up front so the caller gets the conflicting ID.
     */
    public const REASON_DESTINATION_URL_KEY_TAKEN = 'destination_url_key_taken';
    public const REASON_MOVE_DISABLED = 'move_disabled';
    public const REASON_DELETE_DISABLED = 'delete_disabled';
    public const REASON_ROOT_IN_USE = 'root_in_use';
    public const REASON_HAS_CHILDREN = 'has_children';
    /**
     * A delete whose target does not exist. Paired with STATUS_UNCHANGED rather
     * than a skip: the desired state is already the stored state, which is what
     * makes a replayed delete free.
     */
    public const REASON_ALREADY_ABSENT = 'already_absent';
    public const REASON_STORE_SCOPE_STRUCTURAL_CHANGE = 'store_scope_structural_change';
    /**
     * A move whose destination sits under a different root category. The two
     * roots are two different catalogs, so the move takes the category, its
     * whole subtree and their product assignments out of one storefront and
     * into another — an outcome large enough to need its own config switch
     * rather than riding along with ordinary reparenting.
     */
    public const REASON_CROSS_ROOT_MOVE = 'cross_root_move';
    /** A store_values block naming a store view that does not exist. */
    public const REASON_UNKNOWN_STORE = 'unknown_store';
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
     * One of: created, updated, unchanged, deleted, skipped, error.
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

    /**
     * The root category of the tree this entry resolved into — the other half
     * of its scope, and the half a path cannot state when two roots share a
     * name. Null when the entry never resolved to a tree.
     *
     * @return int|null
     */
    public function getRootCategoryId(): ?int;

    /**
     * @param int|null $rootCategoryId
     * @return $this
     */
    public function setRootCategoryId(?int $rootCategoryId): self;

    /**
     * One entry per store scope this category's payload named beyond the
     * request's own — that is, per `store_values` block. Null when the payload
     * named none, which is every payload that predates `store_values`.
     *
     * @return \ReadyData\Import\Api\Data\CategoryStoreResultInterface[]|null
     */
    public function getStoreResults(): ?array;

    /**
     * @param \ReadyData\Import\Api\Data\CategoryStoreResultInterface[] $storeResults
     * @return $this
     */
    public function setStoreResults(array $storeResults): self;
}
