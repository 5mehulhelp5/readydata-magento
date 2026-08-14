<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Api;

use ReadyData\Events\Api\Data\SubscriptionInterface;

/**
 * Per-event subscribe, update and unsubscribe.
 *
 * Every write here takes effect on the next request with no deploy, which is
 * the property the curated-catalogue design exists to provide.
 *
 * @api
 */
interface SubscriptionManagementInterface
{
    /**
     * @return \ReadyData\Events\Api\Data\SubscriptionInterface[]
     */
    public function getList(): array;

    /**
     * Subscribe to an event code, or update an existing subscription to it.
     *
     * @param \ReadyData\Events\Api\Data\SubscriptionInterface $subscription
     * @return \ReadyData\Events\Api\Data\SubscriptionInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function save(SubscriptionInterface $subscription): SubscriptionInterface;

    /**
     * @param int $id
     * @return bool
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function deleteById(int $id): bool;
}
