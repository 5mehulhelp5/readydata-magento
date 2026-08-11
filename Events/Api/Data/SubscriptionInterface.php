<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Api\Data;

/**
 * What to capture for one event code.
 *
 * @api
 */
interface SubscriptionInterface
{
    public function getId(): ?int;

    public function setId(?int $id): self;

    /** An `observer.*` or `plugin.*` code from the generated catalogue. */
    public function getEventCode(): ?string;

    public function setEventCode(?string $eventCode): self;

    public function getEnabled(): ?bool;

    public function setEnabled(?bool $enabled): self;

    /**
     * Dot-notation field paths, e.g. ["sku", "order.customer_email"].
     *
     * Empty means the thin default: identifiers only, with ReadyData re-reading
     * the source of truth. ["*"] sends every scalar the entity carries and is
     * how payment and personal data ends up on the wire, so name fields instead.
     *
     * @return string[]|null
     */
    public function getFields(): ?array;

    /** @param string[]|null $fields */
    public function setFields(?array $fields): self;

    /** @return \ReadyData\Events\Api\Data\SubscriptionRuleInterface[]|null */
    public function getRules(): ?array;

    /** @param \ReadyData\Events\Api\Data\SubscriptionRuleInterface[]|null $rules */
    public function setRules(?array $rules): self;

    /**
     * Optional EventGateInterface implementation, run after the rules pass.
     *
     * A gate is a deploy where a rule is remote configuration, so prefer rules.
     */
    public function getGateClass(): ?string;

    public function setGateClass(?string $gateClass): self;

    /** @return int[]|null Null or empty means every store. */
    public function getStoreIds(): ?array;

    /** @param int[]|null $storeIds */
    public function setStoreIds(?array $storeIds): self;

    /**
     * Skip capture while a ReadyData import owns the writes. Defaults to true.
     *
     * Turning this off on a product event points the pipeline at itself:
     * ReadyData writes, the importer re-emits the core save events, capture
     * queues them, ReadyData writes again.
     */
    public function getIgnoreReadydataOrigin(): ?bool;

    public function setIgnoreReadydataOrigin(?bool $ignore): self;

    /** Field path to collapse repeated events on within one request. */
    public function getCoalesceBy(): ?string;

    public function setCoalesceBy(?string $coalesceBy): self;
}
