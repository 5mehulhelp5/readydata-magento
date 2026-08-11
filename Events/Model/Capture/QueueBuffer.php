<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Model\Capture;

use Magento\Framework\DataObject\IdentityGeneratorInterface;
use Magento\Framework\Serialize\Serializer\Json;
use ReadyData\Events\Logger\Logger;
use ReadyData\Events\Model\Config;
use ReadyData\Events\Model\ResourceModel\Queue;

/**
 * Accumulates captured events and writes them as one multi-row INSERT.
 *
 * Flush cadence is the whole point of this class, and phase 0 settled it on real
 * numbers: re-importing 500 SKUs emitted 1000 events in a single request, in
 * five clean batches. Buffering to request end would therefore hold 1000 rows
 * for 500 products — and at ReadyData_Import's default batch size of 500, a
 * 200k-product import would hold roughly 400k. So the buffer flushes on a size
 * cap, and request end is only the safety net that catches the tail.
 *
 * ReadyData_Import's own ImportEventDispatcher documents the same trap from the
 * other side: it memoises exactly one batch because keying by context would pin
 * every batch's products for the whole request.
 */
class QueueBuffer
{
    /** @var array<int, array<string, mixed>> */
    private array $rows = [];

    private bool $shutdownRegistered = false;

    /** Set when the volume guard trips; capture stops writing for this request. */
    private bool $halted = false;

    private bool $depthChecked = false;

    public function __construct(
        private readonly Queue $queue,
        private readonly Config $config,
        private readonly Json $json,
        private readonly IdentityGeneratorInterface $identityGenerator,
        private readonly Logger $logger
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function add(string $eventCode, int $subscriberId, array $payload): void
    {
        if ($this->halted) {
            return;
        }

        $this->rows[] = [
            'event_id' => $this->identityGenerator->generateId(),
            'event_code' => $eventCode,
            'subscriber_id' => $subscriberId,
            'payload' => $this->json->serialize($payload),
            'status' => Queue::STATUS_WAITING,
            'retries' => 0,
        ];

        $this->registerShutdownFlush();

        if (count($this->rows) >= $this->config->getBufferSize()) {
            $this->flush();
        }
    }

    /**
     * Nothing here may throw. Capture runs inside someone else's save, and an
     * exception escaping into a product save — or worse, into an already
     * committed import batch — would turn "we failed to record an event" into
     * "the merchant's save failed". A lost event is recoverable by the scheduled
     * reconciliation run; a broken save is not.
     */
    public function flush(): void
    {
        if ($this->rows === []) {
            return;
        }

        $rows = $this->rows;
        $this->rows = [];

        try {
            if (!$this->withinDepthLimit(count($rows))) {
                return;
            }

            $this->queue->insertMultiple($rows);
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf('Failed to queue %d captured event(s): %s', count($rows), $e->getMessage()),
                ['exception' => $e]
            );
        }
    }

    public function count(): int
    {
        return count($this->rows);
    }

    public function isHalted(): bool
    {
        return $this->halted;
    }

    /**
     * The volume guard. A reindex or a 200k-product import must not be able to
     * fill the client's disk, and a queue this deep already means delivery is
     * broken — continuing to capture only makes the outage more expensive to
     * clean up. Checked once per request: the count is a scan we do not want on
     * every flush.
     */
    private function withinDepthLimit(int $incoming): bool
    {
        if ($this->depthChecked) {
            return !$this->halted;
        }

        $this->depthChecked = true;
        $limit = $this->config->getMaxQueueDepth();
        $pending = $this->queue->countPending();

        if ($pending + $incoming <= $limit) {
            return true;
        }

        $this->halted = true;
        $this->logger->critical(
            sprintf(
                'ReadyData eventing queue depth %d exceeds the configured maximum of %d. '
                . 'Capture is suspended for this request and events are being dropped. '
                . 'Delivery is almost certainly broken — check the dispatch cron and the subscriber endpoint.',
                $pending,
                $limit
            )
        );

        return false;
    }

    /**
     * The safety net, not the primary path: whatever the size cap left behind at
     * the end of the request still has to reach the queue.
     */
    private function registerShutdownFlush(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }

        $this->shutdownRegistered = true;
        register_shutdown_function(function (): void {
            $this->flush();
        });
    }
}
