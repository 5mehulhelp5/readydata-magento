<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Model\Capture;

/**
 * Declarative `field|operator|value` filtering, evaluated before anything is queued.
 *
 * An event that fails a rule never becomes a row, which is what makes rules
 * worth having: they are the cheapest possible filter and they are configurable
 * from ReadyData's UI without a deploy.
 *
 * Every operator here is **value-based**, and there is deliberately no
 * "changed since" predicate. ReadyData_Import re-emits product events with no
 * origData — it never reads pre-image state, so dataHasChangedFor() reports
 * every field as changed. A change-based rule would therefore be meaningless on
 * exactly the events this module most needs to filter, and offering one would
 * be offering a predicate we cannot honour.
 */
class RuleEvaluator
{
    public const OPERATORS = [
        'eq', 'neq', 'gt', 'gte', 'lt', 'lte',
        'in', 'nin', 'contains', 'starts_with', 'ends_with',
        'empty', 'not_empty', 'regex',
    ];

    public function __construct(private readonly FieldExtractor $extractor)
    {
    }

    /**
     * Rules are ANDed: every one must pass. An unknown operator fails closed —
     * a typo in a rule silently disabling the filter would send data the rule
     * existed to withhold.
     *
     * @param array<int, array{field: string, operator: string, value: mixed}> $rules
     * @param array<string, mixed> $eventData
     */
    public function matches(array $rules, array $eventData): bool
    {
        foreach ($rules as $rule) {
            $actual = $this->extractor->resolve($eventData, (string)$rule['field']);
            if (!$this->evaluate($actual, (string)$rule['operator'], $rule['value'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    public function evaluate(mixed $actual, string $operator, mixed $expected): bool
    {
        return match ($operator) {
            'eq' => $this->looseEquals($actual, $expected),
            'neq' => !$this->looseEquals($actual, $expected),
            'gt' => $actual !== null && $this->compare($actual, $expected) > 0,
            'gte' => $actual !== null && $this->compare($actual, $expected) >= 0,
            'lt' => $actual !== null && $this->compare($actual, $expected) < 0,
            'lte' => $actual !== null && $this->compare($actual, $expected) <= 0,
            'in' => in_array((string)$actual, $this->toList($expected), true),
            'nin' => !in_array((string)$actual, $this->toList($expected), true),
            'contains' => $actual !== null && str_contains((string)$actual, (string)$expected),
            'starts_with' => $actual !== null && str_starts_with((string)$actual, (string)$expected),
            'ends_with' => $actual !== null && str_ends_with((string)$actual, (string)$expected),
            'empty' => $actual === null || $actual === '' || $actual === [],
            'not_empty' => !($actual === null || $actual === '' || $actual === []),
            'regex' => $actual !== null && $this->safeMatch((string)$expected, (string)$actual),
            default => false,
        };
    }

    /**
     * Magento hands most attribute values back as strings, so a rule written as
     * `status eq 1` has to match the string "1". Comparison is string-based
     * unless both sides are numeric.
     */
    private function looseEquals(mixed $actual, mixed $expected): bool
    {
        if ($actual === null || $expected === null) {
            return $actual === $expected;
        }

        if (is_numeric($actual) && is_numeric($expected)) {
            return (float)$actual === (float)$expected;
        }

        return (string)$actual === (string)$expected;
    }

    private function compare(mixed $actual, mixed $expected): int
    {
        if (is_numeric($actual) && is_numeric($expected)) {
            return (float)$actual <=> (float)$expected;
        }

        return (string)$actual <=> (string)$expected;
    }

    /** @return string[] */
    private function toList(mixed $expected): array
    {
        if (is_array($expected)) {
            return array_map('strval', $expected);
        }

        return array_map('trim', explode(',', (string)$expected));
    }

    /**
     * A malformed pattern must not emit a PHP warning on every dispatch, nor
     * take down a page render. It fails the rule instead.
     */
    private function safeMatch(string $pattern, string $subject): bool
    {
        if ($pattern === '' || @preg_match($pattern, '') === false) {
            return false;
        }

        return (bool)preg_match($pattern, $subject);
    }
}
