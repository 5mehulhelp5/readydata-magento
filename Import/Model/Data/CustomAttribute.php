<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Data;

use ReadyData\Import\Api\Data\CustomAttributeInterface;

class CustomAttribute implements CustomAttributeInterface
{
    private string $attributeCode = '';
    private ?string $value = null;

    public function getAttributeCode(): string
    {
        return $this->attributeCode;
    }

    public function setAttributeCode(string $attributeCode): CustomAttributeInterface
    {
        $this->attributeCode = $attributeCode;
        return $this;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function setValue(?string $value): CustomAttributeInterface
    {
        $this->value = $value;
        return $this;
    }
}
