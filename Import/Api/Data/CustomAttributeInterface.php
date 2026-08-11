<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Api\Data;

/**
 * Simple attribute code/value pair. Values are transported as strings and
 * cast to the attribute backend type during import.
 *
 * @api
 */
interface CustomAttributeInterface
{
    public const ATTRIBUTE_CODE = 'attribute_code';
    public const VALUE = 'value';

    /**
     * @return string
     */
    public function getAttributeCode(): string;

    /**
     * @param string $attributeCode
     * @return $this
     */
    public function setAttributeCode(string $attributeCode): self;

    /**
     * @return string|null
     */
    public function getValue(): ?string;

    /**
     * @param string|null $value
     * @return $this
     */
    public function setValue(?string $value): self;
}
