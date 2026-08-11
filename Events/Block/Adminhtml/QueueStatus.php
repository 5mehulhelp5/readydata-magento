<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use ReadyData\Events\Model\Catalogue;
use ReadyData\Events\Model\Config;
use ReadyData\Events\Model\ResourceModel\Queue;
use ReadyData\Events\Model\Subscriber\SubscriberRepository;

/**
 * Backing data for the Events Status grid.
 *
 * A plain block and template rather than a UI component listing: the grid is
 * read-mostly with two bulk actions, and a ui_component would add four XML
 * files and a data provider to render the same table.
 */
class QueueStatus extends Template
{
    public function __construct(
        Context $context,
        private readonly Queue $queue,
        private readonly Config $config,
        private readonly Catalogue $catalogue,
        private readonly SubscriberRepository $subscribers,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /** @return array<int, array<string, mixed>> */
    public function getRows(): array
    {
        $status = $this->getRequest()->getParam('status');

        return $this->queue->recent(200, is_numeric($status) ? (int)$status : null);
    }

    /** @return array<int, int> */
    public function getStatusCounts(): array
    {
        return $this->queue->statusCounts();
    }

    public function getStatusLabel(int $status): string
    {
        return [
            Queue::STATUS_WAITING => 'Waiting',
            Queue::STATUS_SENT => 'Sent',
            Queue::STATUS_FAILED => 'Failed',
            Queue::STATUS_IN_PROGRESS => 'Sending',
            Queue::STATUS_DEAD_LETTER => 'Dead-lettered',
        ][$status] ?? (string)$status;
    }

    public function getStatusClass(int $status): string
    {
        return match ($status) {
            Queue::STATUS_SENT => 'grid-severity-notice',
            Queue::STATUS_FAILED, Queue::STATUS_DEAD_LETTER => 'grid-severity-critical',
            default => 'grid-severity-minor',
        };
    }

    public function isEnabled(): bool
    {
        return $this->config->isEnabled();
    }

    public function getSubscriberDescription(): ?string
    {
        $subscriber = $this->subscribers->getActive();

        return $subscriber === null ? null : sprintf('%s → %s', $subscriber->code, $subscriber->endpointUrl);
    }

    public function getCatalogueSize(): int
    {
        return count($this->catalogue->codes());
    }

    /**
     * The oldest event still waiting.
     *
     * Depth alone cannot distinguish a busy store from a broken cron; age can,
     * and this is the number that tells an operator which one they are looking
     * at before they start reading rows.
     */
    public function getOldestWaitingAt(): ?string
    {
        return $this->queue->getOldestWaitingAt();
    }

    public function getRetryUrl(): string
    {
        return $this->getUrl('readydata_events/queue/retry');
    }

    public function getFilterUrl(?int $status): string
    {
        return $this->getUrl('readydata_events/queue/index', $status === null ? [] : ['status' => $status]);
    }

    public function shortenPayload(string $payload, int $length = 120): string
    {
        return mb_strlen($payload) > $length ? mb_substr($payload, 0, $length) . '…' : $payload;
    }
}
