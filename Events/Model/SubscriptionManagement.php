<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Model;

use Magento\Framework\Exception\LocalizedException;
use ReadyData\Events\Api\Data\SubscriptionInterface;
use ReadyData\Events\Api\Data\SubscriptionInterfaceFactory;
use ReadyData\Events\Api\Data\SubscriptionConverterInterface;
use ReadyData\Events\Api\Data\SubscriptionConverterInterfaceFactory;
use ReadyData\Events\Api\Data\SubscriptionRuleInterface;
use ReadyData\Events\Api\Data\SubscriptionRuleInterfaceFactory;
use ReadyData\Events\Api\SubscriptionManagementInterface;
use ReadyData\Events\Model\Subscriber\SubscriberRepository;
use ReadyData\Events\Model\Subscription\SubscriptionRepository;

class SubscriptionManagement implements SubscriptionManagementInterface
{
    public function __construct(
        private readonly SubscriptionRepository $repository,
        private readonly SubscriberRepository $subscribers,
        private readonly SubscriptionInterfaceFactory $subscriptionFactory,
        private readonly SubscriptionRuleInterfaceFactory $ruleFactory,
        private readonly SubscriptionConverterInterfaceFactory $converterFactory
    ) {
    }

    /** @return SubscriptionInterface[] */
    public function getList(): array
    {
        return array_map([$this, 'toDto'], $this->repository->getList());
    }

    public function save(SubscriptionInterface $subscription): SubscriptionInterface
    {
        $subscriber = $this->subscribers->getActive();
        if ($subscriber === null) {
            throw new LocalizedException(
                __('Register a subscriber before subscribing to events — there is nowhere to deliver them.')
            );
        }

        $id = $this->repository->save($subscriber->id, [
            'event_code' => $subscription->getEventCode(),
            'enabled' => $subscription->getEnabled() ?? true,
            'fields' => $subscription->getFields() ?? [],
            'rules' => $this->rulesToArray($subscription->getRules() ?? []),
            'gate_class' => $subscription->getGateClass(),
            'store_ids' => $subscription->getStoreIds() ?? [],
            'ignore_readydata_origin' => $subscription->getIgnoreReadydataOrigin() ?? true,
            'coalesce_by' => $subscription->getCoalesceBy(),
            'processors' => $subscription->getProcessors() ?? [],
            'converters' => $this->convertersToMap($subscription->getConverters() ?? []),
        ]);

        return $this->toDto($this->repository->getById($id));
    }

    public function deleteById(int $id): bool
    {
        $this->repository->getById($id);
        $this->repository->deleteById($id);

        return true;
    }

    /**
     * @param SubscriptionRuleInterface[] $rules
     * @return array<int, array{field: string, operator: string, value: string|null}>
     */
    private function rulesToArray(array $rules): array
    {
        $out = [];
        foreach ($rules as $rule) {
            $out[] = [
                'field' => (string)$rule->getField(),
                'operator' => (string)$rule->getOperator(),
                'value' => $rule->getValue(),
            ];
        }

        return $out;
    }

    /**
     * @param SubscriptionConverterInterface[] $converters
     * @return array<string, string>
     */
    private function convertersToMap(array $converters): array
    {
        $map = [];
        foreach ($converters as $converter) {
            $field = (string)$converter->getField();
            $class = (string)$converter->getConverterClass();
            if ($field !== '' && $class !== '') {
                $map[$field] = $class;
            }
        }

        return $map;
    }

    /** @param array<string, mixed> $row */
    private function toDto(array $row): SubscriptionInterface
    {
        $model = Subscription\Subscription::fromRow($row);

        $rules = [];
        foreach ($model->rules as $rule) {
            $rules[] = $this->ruleFactory->create()
                ->setField($rule['field'])
                ->setOperator($rule['operator'])
                ->setValue($rule['value'] === null ? null : (string)$rule['value']);
        }

        return $this->subscriptionFactory->create()
            ->setId($model->id)
            ->setEventCode($model->eventCode)
            ->setEnabled((bool)$row['enabled'])
            ->setFields($model->fields)
            ->setRules($rules)
            ->setGateClass($model->gateClass)
            ->setStoreIds($model->storeIds)
            ->setIgnoreReadydataOrigin($model->ignoreReadyDataOrigin)
            ->setCoalesceBy($model->coalesceBy)
            ->setProcessors($model->processors)
            ->setConverters(array_map(
                fn(string $field, string $class): SubscriptionConverterInterface
                    => $this->converterFactory->create()->setField($field)->setConverterClass($class),
                array_keys($model->converters),
                array_values($model->converters)
            ));
    }
}
