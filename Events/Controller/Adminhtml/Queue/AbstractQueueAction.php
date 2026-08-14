<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Controller\Adminhtml\Queue;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\ForwardFactory;
use Magento\Framework\Controller\ResultInterface;
use ReadyData\Events\Model\Config;

/**
 * Shared guard for the Events Status grid and its actions.
 *
 * The grid can be switched off in configuration, and `dependsOnConfig` on the
 * menu item only removes the link — the route still resolves, so a bookmark, a
 * browser history entry or a guessed URL would reach it. Both actions therefore
 * ask the same question, in one place, rather than each carrying its own copy
 * of a policy that would drift the first time one of them changed.
 *
 * A disabled grid answers 404 rather than 403. It is not a permissions
 * decision — the ACL resource is still granted and still meaningful — it is a
 * page the store has been configured not to have, and saying "forbidden" would
 * send whoever hit it to check role permissions that are not the problem.
 */
abstract class AbstractQueueAction extends Action
{
    public const ADMIN_RESOURCE = 'ReadyData_Events::queue';

    public function __construct(
        Context $context,
        private readonly Config $eventsConfig,
        private readonly ForwardFactory $forwardFactory
    ) {
        parent::__construct($context);
    }

    /**
     * The 404 to return when the grid is switched off, or null when it is on.
     */
    protected function gridDisabledResult(): ?ResultInterface
    {
        if ($this->eventsConfig->isQueueGridEnabled()) {
            return null;
        }

        return $this->forwardFactory->create()->forward('noroute');
    }
}
