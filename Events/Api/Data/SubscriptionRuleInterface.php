<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Api\Data;

/**
 * One declarative filter on a subscription.
 *
 * Values are carried as strings because comparison is value-based and Magento
 * hands most attribute values back as strings anyway; the evaluator promotes
 * both sides to numbers when both are numeric.
 *
 * @api
 */
interface SubscriptionRuleInterface
{
    public function getField(): ?string;

    public function setField(?string $field): self;

    /** One of ReadyData\Events\Model\Capture\RuleEvaluator::OPERATORS. */
    public function getOperator(): ?string;

    public function setOperator(?string $operator): self;

    public function getValue(): ?string;

    public function setValue(?string $value): self;
}
