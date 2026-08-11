<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Test\Unit\Model\Capture;

use Magento\Framework\DataObject;
use PHPUnit\Framework\TestCase;
use ReadyData\Events\Model\Capture\FieldExtractor;
use ReadyData\Events\Model\Capture\RuleEvaluator;

class RuleEvaluatorTest extends TestCase
{
    private RuleEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new RuleEvaluator(new FieldExtractor());
    }

    /**
     * Magento hands most attribute values back as strings, so `status eq 1`
     * has to match the string "1" or every numeric rule silently never fires.
     */
    public function testNumericComparisonIgnoresStringTyping(): void
    {
        self::assertTrue($this->evaluator->evaluate('1', 'eq', 1));
        self::assertTrue($this->evaluator->evaluate(10, 'gt', '9'));
        self::assertFalse($this->evaluator->evaluate('9', 'gt', 10));
    }

    public function testRulesAreAnded(): void
    {
        $data = ['product' => new DataObject(['status' => '1', 'type_id' => 'simple'])];

        $pass = [
            ['field' => 'status', 'operator' => 'eq', 'value' => '1'],
            ['field' => 'type_id', 'operator' => 'eq', 'value' => 'simple'],
        ];
        $fail = [
            ['field' => 'status', 'operator' => 'eq', 'value' => '1'],
            ['field' => 'type_id', 'operator' => 'eq', 'value' => 'configurable'],
        ];

        self::assertTrue($this->evaluator->matches($pass, $data));
        self::assertFalse($this->evaluator->matches($fail, $data));
    }

    public function testNoRulesMatchesEverything(): void
    {
        self::assertTrue($this->evaluator->matches([], ['anything' => 1]));
    }

    /**
     * A typo in an operator must not quietly disable the filter: that would
     * send exactly the data the rule existed to withhold.
     */
    public function testUnknownOperatorFailsClosed(): void
    {
        self::assertFalse($this->evaluator->evaluate('x', 'nearly_equals', 'x'));
    }

    public function testInListAcceptsCommaSeparatedAndArrayForms(): void
    {
        self::assertTrue($this->evaluator->evaluate('simple', 'in', 'simple,virtual'));
        self::assertTrue($this->evaluator->evaluate('simple', 'in', ['simple', 'virtual']));
        self::assertFalse($this->evaluator->evaluate('bundle', 'in', 'simple,virtual'));
    }

    public function testEmptinessDistinguishesNullFromZero(): void
    {
        self::assertTrue($this->evaluator->evaluate(null, 'empty', null));
        self::assertTrue($this->evaluator->evaluate('', 'empty', null));
        self::assertFalse($this->evaluator->evaluate('0', 'empty', null), '"0" is a value, not an absence.');
        self::assertTrue($this->evaluator->evaluate('0', 'not_empty', null));
    }

    /** A malformed pattern must not warn on every dispatch nor break a save. */
    public function testMalformedRegexFailsQuietly(): void
    {
        self::assertFalse($this->evaluator->evaluate('abc', 'regex', '/unterminated'));
        self::assertTrue($this->evaluator->evaluate('abc', 'regex', '/^a/'));
    }
}
