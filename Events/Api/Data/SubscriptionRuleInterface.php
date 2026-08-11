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
    /**
     * @return string|null
     */
    public function getField(): ?string;

    /**
     * @param string|null $field
     * @return $this
     */
    public function setField(?string $field): self;

    /**
     * One of ReadyData\Events\Model\Capture\RuleEvaluator::OPERATORS.
     *
     * @return string|null
     */
    public function getOperator(): ?string;

    /**
     * @param string|null $operator
     * @return $this
     */
    public function setOperator(?string $operator): self;

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
