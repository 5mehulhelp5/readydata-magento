<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Api;

use ReadyData\Events\Api\Data\SubscriberInterface;

/**
 * Register and deregister ReadyData as a delivery destination.
 *
 * @api
 */
interface SubscriberManagementInterface
{
    /**
     * The registered subscriber, with its secret withheld.
     *
     * @return \ReadyData\Events\Api\Data\SubscriberInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function get(): SubscriberInterface;

    /**
     * Register (or re-register) the destination.
     *
     * Returns the subscriber with a generated secret in clear — the only time
     * it is readable. Store it on receipt.
     *
     * @param \ReadyData\Events\Api\Data\SubscriberInterface $subscriber
     * @return \ReadyData\Events\Api\Data\SubscriberInterface
     * @throws \Magento\Framework\Exception\AlreadyExistsException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function register(SubscriberInterface $subscriber): SubscriberInterface;

    /**
     * Deregister. Cascades to this destination's subscriptions.
     *
     * @param string $code
     * @return bool
     */
    public function delete(string $code): bool;
}
