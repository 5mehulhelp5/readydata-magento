<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Api\Data;

/**
 * What one event code carries, for ReadyData's field picker.
 *
 * @api
 */
interface EventDescriptionInterface
{
    /**
     * @return string|null
     */
    public function getCode(): ?string;

    /**
     * @param string|null $code
     * @return $this
     */
    public function setCode(?string $code): self;

    /**
     * `observer` or `plugin`.
     *
     * @return string|null
     */
    public function getKind(): ?string;

    /**
     * @param string|null $kind
     * @return $this
     */
    public function setKind(?string $kind): self;

    /**
     * Whether a hook is actually registered for this code on this store.
     *
     * @return bool|null
     */
    public function getHooked(): ?bool;

    /**
     * @param bool|null $hooked
     * @return $this
     */
    public function setHooked(?bool $hooked): self;

    /**
     * The entity prefix the event belongs to, or null for a directly dispatched event.
     *
     * @return string|null
     */
    public function getEntity(): ?string;

    /**
     * @param string|null $entity
     * @return $this
     */
    public function setEntity(?string $entity): self;

    /**
     * How the field list was arrived at, and what it does not mean.
     *
     * The list is the common set rather than a limit — any field the entity
     * carries can still be subscribed to by naming it — and saying so here is
     * what stops the picker being read as an exhaustive schema.
     *
     * @return string|null
     */
    public function getDerivedFrom(): ?string;

    /**
     * @param string|null $derivedFrom
     * @return $this
     */
    public function setDerivedFrom(?string $derivedFrom): self;

    /** @return string[]|null */
    public function getFields(): ?array;

    /**
     * @param string[]|null $fields
     *
     * @return $this
     */
    public function setFields(?array $fields): self;

    /**
     * A worked example of the payload these fields would produce, as JSON.
     *
     * @return string|null
     */
    public function getSample(): ?string;

    /**
     * @param string|null $sample
     * @return $this
     */
    public function setSample(?string $sample): self;
}
