<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Controller\Adminhtml\Queue;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * The Events Status grid.
 *
 * The first thing anyone asks for when events "aren't arriving", and the reason
 * it lives in Magento's admin rather than only in ReadyData: when the pipeline
 * is broken, the person looking is often a Magento developer with no ReadyData
 * login, and the question they need answered — is anything queued, did it fail,
 * what did the endpoint say — is answerable entirely from this store's data.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'ReadyData_Events::queue';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $page = $this->pageFactory->create();
        $page->setActiveMenu(self::ADMIN_RESOURCE);
        $page->getConfig()->getTitle()->prepend(__('ReadyData Events'));

        return $page;
    }
}
