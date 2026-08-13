<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Model\Data;

use ReadyData\Events\Api\Data\SubscriptionConverterInterface;

class SubscriptionConverter implements SubscriptionConverterInterface
{
    private ?string $field = null;
    private ?string $converterClass = null;

    public function getField(): ?string
    {
        return $this->field;
    }

    public function setField(?string $field): SubscriptionConverterInterface
    {
        $this->field = $field;

        return $this;
    }

    public function getConverterClass(): ?string
    {
        return $this->converterClass;
    }

    public function setConverterClass(?string $converterClass): SubscriptionConverterInterface
    {
        $this->converterClass = $converterClass;

        return $this;
    }
}
