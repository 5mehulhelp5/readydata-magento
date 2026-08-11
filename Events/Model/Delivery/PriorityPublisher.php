<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Model\Delivery;

use Magento\Framework\MessageQueue\PublisherInterface;
use ReadyData\Events\Logger\Logger;
use ReadyData\Events\Model\Subscription\SubscriptionMap;

/**
 * The near-real-time path, for subscriptions that cannot wait for the cron.
 *
 * The default cadence is the one-minute dispatch cron, and that is deliberate:
 * it needs no extra process, so a store gets the whole feature by installing a
 * module. This path exists for the cases where a minute is genuinely too long —
 * an order reaching a fulfilment system, say — and it is **opt-in per
 * subscription** precisely so no store has to run a consumer to get value.
 *
 * Publishing is best-effort by design. The event is already committed to the
 * queue table before this is called, so a broker that is down, misconfigured or
 * simply not running costs latency and nothing else: the cron picks the row up
 * on its next pass. That is why a failure here is logged and swallowed rather
 * than raised — a store whose message queue is not running must still deliver.
 */
class PriorityPublisher
{
    public const TOPIC = 'readydata.events.publish';

    public function __construct(
        private readonly PublisherInterface $publisher,
        private readonly SubscriptionMap $subscriptions,
        private readonly Logger $logger
    ) {
    }

    /**
     * Whether any subscription for this code asked for the priority path.
     *
     * Checked before publishing rather than inside the consumer so an ordinary
     * store — every subscription on the default path — never touches the
     * message queue at all.
     */
    public function isPriority(string $eventCode): bool
    {
        foreach ($this->subscriptions->forCode($eventCode) as $subscription) {
            if ($subscription->priority) {
                return true;
            }
        }

        return false;
    }

    /**
     * Nudges the consumer to drain the queue now.
     *
     * The message carries no payload beyond the event code. The queue table is
     * the source of truth, and passing the event through the broker instead
     * would create a second copy that can disagree with it — and would put
     * customer data into a transport that has its own retention and its own
     * access rules.
     */
    public function publish(string $eventCode): void
    {
        try {
            $this->publisher->publish(self::TOPIC, $eventCode);
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf(
                'Could not publish priority event "%s"; it stays queued for the dispatch cron: %s',
                $eventCode,
                $e->getMessage()
            ));
        }
    }
}
