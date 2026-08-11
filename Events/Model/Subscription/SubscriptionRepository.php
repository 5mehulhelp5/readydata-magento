<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Model\Subscription;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;
use ReadyData\Events\Api\EventDataProcessorInterface;
use ReadyData\Events\Api\EventGateInterface;
use ReadyData\Events\Api\FieldConverterInterface;
use ReadyData\Events\Model\Capture\RuleEvaluator;
use ReadyData\Events\Model\Catalogue;

/**
 * CRUD over readydata_event_subscription.
 *
 * Every write invalidates the cached subscription map, which is what makes a
 * REST change take effect on the next request with no deploy — the property
 * option B exists to protect. Writing this table by any other route leaves the
 * map stale and the change apparently ignored.
 */
class SubscriptionRepository
{
    public const TABLE = 'readydata_event_subscription';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Json $json,
        private readonly SubscriptionMap $map,
        private readonly Catalogue $catalogue
    ) {
    }

    public function getTable(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }

    /** @return array<int, array<string, mixed>> */
    public function getList(): array
    {
        $connection = $this->resource->getConnection();

        return $connection->fetchAll(
            $connection->select()->from($this->getTable())->order('event_code ASC')
        );
    }

    /** @return array<string, mixed> */
    public function getById(int $id): array
    {
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()->from($this->getTable())->where('subscription_id = ?', $id)
        );

        if (!$row) {
            throw new NoSuchEntityException(__('No subscription with id "%1".', $id));
        }

        return $row;
    }

    /**
     * Creates or updates by (event_code, subscriber_id), which is the pair the
     * unique key covers — so subscribing twice to one code updates rather than
     * failing on a constraint the caller cannot see.
     *
     * @param array<string, mixed> $data
     */
    public function save(int $subscriberId, array $data): int
    {
        $eventCode = trim((string)($data['event_code'] ?? ''));
        $this->assertSubscribable($eventCode);
        $this->assertRulesValid($data['rules'] ?? []);
        $this->assertGateValid($data['gate_class'] ?? null);
        $this->assertImplement($data['processors'] ?? [], EventDataProcessorInterface::class, 'processor');
        $this->assertImplement(array_values((array)($data['converters'] ?? [])), FieldConverterInterface::class, 'converter');

        $connection = $this->resource->getConnection();

        $row = [
            'event_code' => $eventCode,
            'subscriber_id' => $subscriberId,
            'enabled' => !empty($data['enabled']) ? 1 : 0,
            'fields' => $this->encodeList($data['fields'] ?? []),
            'rules' => $this->encodeRules($data['rules'] ?? []),
            'gate_class' => $data['gate_class'] ?? null,
            'priority' => !empty($data['priority']) ? 1 : 0,
            'store_ids' => $this->encodeStoreIds($data['store_ids'] ?? []),
            'ignore_readydata_origin' => array_key_exists('ignore_readydata_origin', $data)
                ? (!empty($data['ignore_readydata_origin']) ? 1 : 0)
                : 1,
            'coalesce_by' => $data['coalesce_by'] ?? null,
            'processors' => $this->encodeList($data['processors'] ?? []),
            'converters' => $this->encodeMap($data['converters'] ?? []),
        ];

        $existing = $connection->fetchOne(
            $connection->select()
                ->from($this->getTable(), 'subscription_id')
                ->where('event_code = ?', $eventCode)
                ->where('subscriber_id = ?', $subscriberId)
        );

        if ($existing) {
            $connection->update($this->getTable(), $row, ['subscription_id = ?' => (int)$existing]);
            $id = (int)$existing;
        } else {
            $connection->insert($this->getTable(), $row);
            $id = (int)$connection->lastInsertId($this->getTable());
        }

        $this->map->invalidate();

        return $id;
    }

    public function deleteById(int $id): void
    {
        $connection = $this->resource->getConnection();
        $connection->delete($this->getTable(), ['subscription_id = ?' => $id]);
        $this->map->invalidate();
    }

    public function deleteByCode(string $eventCode): void
    {
        $connection = $this->resource->getConnection();
        $connection->delete($this->getTable(), ['event_code = ?' => $eventCode]);
        $this->map->invalidate();
    }

    /**
     * A code outside the generated catalogue has no hook registered against it,
     * so subscribing would appear to succeed and then emit nothing forever.
     * Refusing here turns a silent dead end into an error naming the fix.
     */
    private function assertSubscribable(string $eventCode): void
    {
        if ($eventCode === '') {
            throw new LocalizedException(__('An event code is required.'));
        }

        if (!$this->catalogue->has($eventCode)) {
            throw new LocalizedException(__(
                'Event code "%1" is not in the generated catalogue, so nothing would capture it. '
                . 'Add it to ReadyData\\Events\\Model\\Catalogue, run bin/magento readydata:events:generate '
                . 'and recompile, then subscribe.',
                $eventCode
            ));
        }
    }

    /** @param mixed $rules */
    private function assertRulesValid($rules): void
    {
        foreach ((array)$rules as $rule) {
            if (!is_array($rule) || !isset($rule['field'], $rule['operator'])) {
                throw new LocalizedException(__('Each rule needs a "field" and an "operator".'));
            }

            if (!in_array((string)$rule['operator'], RuleEvaluator::OPERATORS, true)) {
                throw new LocalizedException(__(
                    'Unknown rule operator "%1". Supported: %2.',
                    $rule['operator'],
                    implode(', ', RuleEvaluator::OPERATORS)
                ));
            }
        }
    }

    private function assertGateValid(?string $gateClass): void
    {
        if ($gateClass === null || $gateClass === '') {
            return;
        }

        if (!class_exists($gateClass)) {
            throw new LocalizedException(__('Gate class "%1" does not exist.', $gateClass));
        }

        if (!is_subclass_of($gateClass, EventGateInterface::class)) {
            throw new LocalizedException(__(
                'Gate class "%1" must implement %2.',
                $gateClass,
                EventGateInterface::class
            ));
        }
    }

    /**
     * Refusing an unusable class name here turns a silent misconfiguration into
     * an error at the moment somebody can still fix it: a processor that never
     * loads delivers thin payloads forever, and a converter that never loads
     * would drop the field it was meant to redact.
     *
     * @param mixed $classNames
     */
    private function assertImplement($classNames, string $contract, string $label): void
    {
        foreach ((array)$classNames as $className) {
            if (!is_string($className) || $className === '') {
                continue;
            }

            if (!class_exists($className)) {
                throw new LocalizedException(__('%1 class "%2" does not exist.', ucfirst($label), $className));
            }

            if (!is_subclass_of($className, $contract)) {
                throw new LocalizedException(
                    __('%1 class "%2" must implement %3.', ucfirst($label), $className, $contract)
                );
            }
        }
    }

    /** @param mixed $map @return string|null */
    private function encodeMap($map): ?string
    {
        $clean = [];
        foreach ((array)$map as $field => $className) {
            if (is_string($field) && is_string($className) && $field !== '' && $className !== '') {
                $clean[$field] = $className;
            }
        }

        return $clean === [] ? null : $this->json->serialize($clean);
    }

    /** @param mixed $list */
    private function encodeList($list): ?string
    {
        $values = array_values(array_filter(array_map('strval', (array)$list), static fn($v) => $v !== ''));

        return $values === [] ? null : $this->json->serialize($values);
    }

    /** @param mixed $rules */
    private function encodeRules($rules): ?string
    {
        $normalised = [];
        foreach ((array)$rules as $rule) {
            $normalised[] = [
                'field' => (string)$rule['field'],
                'operator' => (string)$rule['operator'],
                'value' => $rule['value'] ?? null,
            ];
        }

        return $normalised === [] ? null : $this->json->serialize($normalised);
    }

    /** @param mixed $storeIds */
    private function encodeStoreIds($storeIds): ?string
    {
        $ids = array_filter(array_map('intval', (array)$storeIds));

        return $ids === [] ? null : implode(',', $ids);
    }
}
