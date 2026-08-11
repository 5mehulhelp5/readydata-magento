<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Cron;

use ReadyData\Events\Logger\Logger;
use ReadyData\Events\Model\Config;
use ReadyData\Events\Model\ResourceModel\Queue;

/**
 * Daily retention sweep.
 *
 * Deletes settled events only — delivered or dead-lettered. An event still
 * waiting is never deleted however old it is: age there means delivery has been
 * broken for days, and deleting the backlog would convert a visible, fixable
 * outage into silent data loss.
 */
class CleanQueue
{
    public function __construct(
        private readonly Queue $queue,
        private readonly Config $config,
        private readonly Logger $logger
    ) {
    }

    public function execute(): void
    {
        try {
            $deleted = $this->queue->deleteSettledOlderThan($this->config->getRetentionDays());
        } catch (\Throwable $e) {
            $this->logger->error('Event retention cron failed: ' . $e->getMessage(), ['exception' => $e]);

            return;
        }

        if ($deleted > 0) {
            $this->logger->info(sprintf('Event retention: deleted %d settled event(s).', $deleted));
        }
    }
}
