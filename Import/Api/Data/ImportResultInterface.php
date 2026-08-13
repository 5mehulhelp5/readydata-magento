<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Api\Data;

/**
 * Per-product import outcome.
 *
 * @api
 */
interface ImportResultInterface
{
    public const SKU = 'sku';
    public const ENTITY_ID = 'entity_id';
    public const STATUS = 'status';
    public const MESSAGES = 'messages';
    public const STORE_RESULTS = 'store_results';

    public const STATUS_CREATED = 'created';
    public const STATUS_UPDATED = 'updated';
    public const STATUS_ERROR = 'error';

    /**
     * @return string
     */
    public function getSku(): string;

    /**
     * @param string $sku
     * @return $this
     */
    public function setSku(string $sku): self;

    /**
     * @return int|null
     */
    public function getEntityId(): ?int;

    /**
     * @param int $entityId
     * @return $this
     */
    public function setEntityId(int $entityId): self;

    /**
     * One of: created, updated, error. Describes the product — the entity row,
     * its links and its values in the request's own scope. The scopes named by
     * `store_values` report separately, in {@see getStoreResults()}.
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
     * Warnings and errors collected for this product — the ones that belong to
     * the product itself rather than to one of its store scopes. A message
     * raised while writing a `store_values` block is on that block's own result
     * instead, so no message is reported twice.
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
     * One entry per store scope this product's payload named beyond the
     * request's own — that is, one per `store_values` block, in payload order.
     * Null when the payload named none, which is every payload that predates
     * `store_values`.
     *
     * A block whose store view could not be resolved still gets its row, with
     * `store_id: null` and a `reason`, so the rows and the blocks stay in step.
     * The exception is a block naming the request's own scope: it is merged into
     * the product's own pass, and the product's top-level result is already that
     * scope's outcome.
     *
     * @return \ReadyData\Import\Api\Data\StoreResultInterface[]|null
     */
    public function getStoreResults(): ?array;

    /**
     * @param \ReadyData\Import\Api\Data\StoreResultInterface[] $storeResults
     * @return $this
     */
    public function setStoreResults(array $storeResults): self;
}
