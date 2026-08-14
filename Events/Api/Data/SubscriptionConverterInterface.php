<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Api\Data;

/**
 * Binds one field path to the converter that redacts it.
 *
 * A typed pair rather than a free-form map, so the contract is discoverable
 * through /rest/schema and a caller cannot send a shape the module has to
 * guess at.
 *
 * @api
 */
interface SubscriptionConverterInterface
{
    /**
     * The field path this converter applies to.
     *
     * @return string|null
     */
    public function getField(): ?string;

    /**
     * @param string|null $field
     * @return $this
     */
    public function setField(?string $field): self;

    /**
     * A FieldConverterInterface implementation.
     *
     * @return string|null
     */
    public function getConverterClass(): ?string;

    /**
     * @param string|null $converterClass
     * @return $this
     */
    public function setConverterClass(?string $converterClass): self;
}
