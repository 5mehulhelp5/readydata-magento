<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Controller\Adminhtml\Queue;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\ForwardFactory;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;
use ReadyData\Events\Model\Config;

/**
 * The Events Status grid.
 *
 * The first thing anyone asks for when events "aren't arriving", and the reason
 * it lives in Magento's admin rather than only in ReadyData: when the pipeline
 * is broken, the person looking is often a Magento developer with no ReadyData
 * login, and the question they need answered — is anything queued, did it fail,
 * what did the endpoint say — is answerable entirely from this store's data.
 *
 * Which is also why it is on by default and has to be switched off deliberately
 * (§ ReadyData → Eventing → Admin).
 */
class Index extends AbstractQueueAction implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        Config $eventsConfig,
        ForwardFactory $forwardFactory,
        private readonly PageFactory $pageFactory
    ) {
        parent::__construct($context, $eventsConfig, $forwardFactory);
    }

    public function execute(): ResultInterface
    {
        $disabled = $this->gridDisabledResult();
        if ($disabled !== null) {
            return $disabled;
        }

        $page = $this->pageFactory->create();
        $page->setActiveMenu(self::ADMIN_RESOURCE);
        $page->getConfig()->getTitle()->prepend(__('ReadyData Events'));

        return $page;
    }
}
