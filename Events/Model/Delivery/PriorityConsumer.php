<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Model\Delivery;

use ReadyData\Events\Logger\Logger;
use ReadyData\Events\Model\Config;

/**
 * Drains the queue on demand, for subscriptions flagged priority.
 *
 * Runs the same dispatcher the cron runs — same claim, same batching, same
 * retry and dead-lettering. Anything else would mean two delivery paths with
 * two sets of bugs, and a priority event that failed differently from an
 * ordinary one.
 *
 * Started with:
 *   bin/magento queue:consumers:start readydata.events.publish
 */
class PriorityConsumer
{
    public function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly Config $config,
        private readonly Logger $logger
    ) {
    }

    /**
     * @param string $eventCode The code that triggered the nudge; informational only.
     */
    public function process(string $eventCode): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        try {
            $result = $this->dispatcher->dispatch();

            if ($result['sent'] > 0 || $result['failed'] > 0) {
                $this->logger->info(sprintf(
                    'Priority dispatch for "%s": %d sent, %d failed.',
                    $eventCode,
                    $result['sent'],
                    $result['failed']
                ));
            }
        } catch (\Throwable $e) {
            // Swallowed rather than rethrown: an exception here would return the
            // message to the broker to be retried forever, while the cron would
            // deliver the same events a minute later anyway.
            $this->logger->error(
                sprintf('Priority dispatch for "%s" failed: %s', $eventCode, $e->getMessage())
            );
        }
    }
}
