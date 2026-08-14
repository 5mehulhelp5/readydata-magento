<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Cron;

use ReadyData\Events\Logger\Logger;
use ReadyData\Events\Model\Config;
use ReadyData\Events\Model\Delivery\Dispatcher;

/**
 * Drains the queue every minute.
 *
 * Cron is the least-maintained subsystem on many client stores, and events
 * stuck at "waiting" is Adobe's single most common support case. It will be ours
 * too, which is why GET queue reports depth and the oldest waiting event rather
 * than only a total.
 */
class DispatchQueue
{
    public function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly Config $config,
        private readonly Logger $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        try {
            $result = $this->dispatcher->dispatch();
        } catch (\Throwable $e) {
            $this->logger->critical('Event dispatch cron failed: ' . $e->getMessage(), ['exception' => $e]);

            return;
        }

        if ($result['sent'] > 0 || $result['failed'] > 0 || $result['reclaimed'] > 0) {
            $this->logger->info(sprintf(
                'Event dispatch: %d sent, %d failed, %d reclaimed.',
                $result['sent'],
                $result['failed'],
                $result['reclaimed']
            ));
        }
    }
}
