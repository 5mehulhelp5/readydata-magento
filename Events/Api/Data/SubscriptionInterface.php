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
    /**
     * @return int|null
     */
    public function getId(): ?int;

    /**
     * @param int|null $id
     * @return $this
     */
    public function setId(?int $id): self;

    /**
     * An `observer.*` or `plugin.*` code from the generated catalogue.
     *
     * @return string|null
     */
    public function getEventCode(): ?string;

    /**
     * @param string|null $eventCode
     * @return $this
     */
    public function setEventCode(?string $eventCode): self;

    /**
     * @return bool|null
     */
    public function getEnabled(): ?bool;

    /**
     * @param bool|null $enabled
     * @return $this
     */
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

    /**
     * @param string[]|null $fields
     *
     * @return $this
     */
    public function setFields(?array $fields): self;

    /** @return \ReadyData\Events\Api\Data\SubscriptionRuleInterface[]|null */
    public function getRules(): ?array;

    /**
     * @param \ReadyData\Events\Api\Data\SubscriptionRuleInterface[]|null $rules
     *
     * @return $this
     */
    public function setRules(?array $rules): self;

    /**
     * Optional EventGateInterface implementation, run after the rules pass.
     *
     * A gate is a deploy where a rule is remote configuration, so prefer rules.
     *
     * @return string|null
     */
    public function getGateClass(): ?string;

    /**
     * @param string|null $gateClass
     * @return $this
     */
    public function setGateClass(?string $gateClass): self;

    /** @return int[]|null Null or empty means every store. */
    public function getStoreIds(): ?array;

    /**
     * @param int[]|null $storeIds
     *
     * @return $this
     */
    public function setStoreIds(?array $storeIds): self;

    /**
     * Skip capture while a ReadyData import owns the writes. Defaults to true.
     *
     * Turning this off on a product event points the pipeline at itself:
     * ReadyData writes, the importer re-emits the core save events, capture
     * queues them, ReadyData writes again.
     *
     * @return bool|null
     */
    public function getIgnoreReadydataOrigin(): ?bool;

    /**
     * @param bool|null $ignore
     * @return $this
     */
    public function setIgnoreReadydataOrigin(?bool $ignore): self;

    /**
     * Field path to collapse repeated events on within one request.
     *
     * @return string|null
     */
    public function getCoalesceBy(): ?string;

    /**
     * @param string|null $coalesceBy
     * @return $this
     */
    public function setCoalesceBy(?string $coalesceBy): self;
}
