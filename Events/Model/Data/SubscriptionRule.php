<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Model\Data;

use ReadyData\Events\Api\Data\SubscriptionRuleInterface;

class SubscriptionRule implements SubscriptionRuleInterface
{
    private ?string $field = null;
    private ?string $operator = null;
    private ?string $value = null;

    public function getField(): ?string
    {
        return $this->field;
    }

    public function setField(?string $field): SubscriptionRuleInterface
    {
        $this->field = $field;

        return $this;
    }

    public function getOperator(): ?string
    {
        return $this->operator;
    }

    public function setOperator(?string $operator): SubscriptionRuleInterface
    {
        $this->operator = $operator;

        return $this;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function setValue(?string $value): SubscriptionRuleInterface
    {
        $this->value = $value;

        return $this;
    }
}
