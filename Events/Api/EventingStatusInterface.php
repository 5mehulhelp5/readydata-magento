<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Api;

use ReadyData\Events\Api\Data\QueueStatusInterface;

/**
 * Discovery and health.
 *
 * @api
 */
interface EventingStatusInterface
{
    /**
     * Queue depth, status counts and whether the hooks are registered at all.
     *
     * @return \ReadyData\Events\Api\Data\QueueStatusInterface
     */
    public function getStatus(): QueueStatusInterface;

    /**
     * Every subscribable code on this store.
     *
     * Feeds ReadyData's event picker. Without it an operator is typing event
     * codes from memory, which is how a subscription to a code that does not
     * exist gets created and then quietly never fires.
     *
     * @return string[]
     */
    public function getSupported(): array;

    /**
     * What one event code carries: field suggestions and a worked sample payload.
     *
     * Feeds ReadyData's field picker. Without it an operator types dot paths
     * from memory, and a path that resolves to nothing fails silently — the
     * subscription looks configured, events arrive, every payload is empty.
     *
     * @param string $code
     * @return \ReadyData\Events\Api\Data\EventDescriptionInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getSupportedDetail(string $code): \ReadyData\Events\Api\Data\EventDescriptionInterface;

    /**
     * Queue a synthetic event and deliver it immediately, proving capture,
     * signing and the subscriber endpoint end to end.
     *
     * @return string Human-readable outcome.
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function test(): string;
}
