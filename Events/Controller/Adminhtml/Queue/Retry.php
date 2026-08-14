<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Controller\Adminhtml\Queue;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\ForwardFactory;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultInterface;
use ReadyData\Events\Model\Config;
use ReadyData\Events\Model\ResourceModel\Queue;

/**
 * Returns failed and dead-lettered events to the queue.
 *
 * Dead-lettering is deliberately terminal — an endpoint that rejected an event
 * seven times will reject it an eighth, and retrying forever turns one broken
 * subscriber into an ever-growing table. But "terminal" has to mean "until a
 * human says otherwise", because the usual cause is a misconfiguration that
 * someone has just fixed, and the events are still sitting there, still valid.
 *
 * Resets the attempt counter as well as the status: leaving `retries` at the
 * maximum would have the row dead-letter again on its first failure, which is
 * not a retry so much as a formality.
 */
class Retry extends AbstractQueueAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        Config $eventsConfig,
        ForwardFactory $forwardFactory,
        private readonly Queue $queue
    ) {
        parent::__construct($context, $eventsConfig, $forwardFactory);
    }

    public function execute(): ResultInterface
    {
        // The grid is this action's only entry point, so it goes away with it —
        // otherwise a stale form or a bookmarked POST could still requeue.
        $disabled = $this->gridDisabledResult();
        if ($disabled !== null) {
            return $disabled;
        }

        $ids = $this->getRequest()->getParam('queue_ids');
        $scope = (string)$this->getRequest()->getParam('scope', '');

        try {
            if ($scope === 'dead_letter') {
                $count = $this->queue->requeueByStatus([Queue::STATUS_DEAD_LETTER]);
            } elseif ($scope === 'failed') {
                $count = $this->queue->requeueByStatus([Queue::STATUS_FAILED, Queue::STATUS_DEAD_LETTER]);
            } else {
                $ids = array_values(array_filter(array_map('intval', (array)$ids)));
                $count = $this->queue->requeue($ids);
            }

            if ($count > 0) {
                $this->messageManager->addSuccessMessage(
                    __('%1 event(s) queued for another attempt. The dispatch cron picks them up within a minute.', $count)
                );
            } else {
                $this->messageManager->addNoticeMessage(__('Nothing to retry.'));
            }
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Could not retry: %1', $e->getMessage()));
        }

        return $this->resultRedirectFactory->create()->setPath('readydata_events/queue/index');
    }
}
