<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Model\Subscription;

/**
 * One row of readydata_event_subscription, resolved and immutable.
 *
 * Built once per request when the subscription map loads, then read on the hot
 * path, so everything expensive (JSON decoding, store-id splitting) happens here
 * rather than per dispatch.
 */
class Subscription
{
    /**
     * @param string[] $fields Dot-notation field paths; empty means the thin default
     * @param array<int, array{field: string, operator: string, value: mixed}> $rules
     * @param int[]|null $storeIds Null means every store
     */
    public function __construct(
        public readonly int $id,
        public readonly string $eventCode,
        public readonly int $subscriberId,
        public readonly array $fields = [],
        public readonly array $rules = [],
        public readonly ?string $gateClass = null,
        public readonly ?array $storeIds = null,
        public readonly bool $ignoreReadyDataOrigin = true,
        public readonly ?string $coalesceBy = null
    ) {
    }

    public function sendsEveryField(): bool
    {
        return in_array('*', $this->fields, true);
    }

    public function matchesStore(?int $storeId): bool
    {
        if ($this->storeIds === null || $this->storeIds === []) {
            return true;
        }

        // An event that carries no store id is not evidence of the wrong store,
        // so a store-scoped subscription still takes it rather than silently
        // dropping every event whose payload happens to omit the field.
        if ($storeId === null) {
            return true;
        }

        return in_array($storeId, $this->storeIds, true);
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int)$row['subscription_id'],
            (string)$row['event_code'],
            (int)$row['subscriber_id'],
            self::decodeList($row['fields'] ?? null),
            self::decodeRules($row['rules'] ?? null),
            isset($row['gate_class']) && $row['gate_class'] !== '' ? (string)$row['gate_class'] : null,
            self::decodeStoreIds($row['store_ids'] ?? null),
            (bool)($row['ignore_readydata_origin'] ?? true),
            isset($row['coalesce_by']) && $row['coalesce_by'] !== '' ? (string)$row['coalesce_by'] : null
        );
    }

    /** @return string[] */
    private static function decodeList(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    /** @return array<int, array{field: string, operator: string, value: mixed}> */
    private static function decodeRules(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $rules = [];
        foreach ($decoded as $rule) {
            if (is_array($rule) && isset($rule['field'], $rule['operator'])) {
                $rules[] = [
                    'field' => (string)$rule['field'],
                    'operator' => (string)$rule['operator'],
                    'value' => $rule['value'] ?? null,
                ];
            }
        }

        return $rules;
    }

    /** @return int[]|null */
    private static function decodeStoreIds(?string $csv): ?array
    {
        if ($csv === null || trim($csv) === '') {
            return null;
        }

        $ids = array_filter(array_map('trim', explode(',', $csv)), static fn($v) => $v !== '');

        return $ids === [] ? null : array_values(array_map('intval', $ids));
    }
}
