<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use ReadyData\Events\Model\Capture\EventCapture;
use ReadyData\Events\Model\Catalogue;

/**
 * One observer registered against every event in the curated catalogue.
 *
 * A single class rather than one generated per event: Magento resolves an
 * observer instance once per request and reuses it, so the whole catalogue costs
 * one instantiation no matter how many of its events fire. Phase 0 confirmed
 * that directly — 1000 dispatches in one request, one constructor call.
 */
class CaptureObserver implements ObserverInterface
{
    public function __construct(
        private readonly EventCapture $capture,
        private readonly Catalogue $catalogue
    ) {
    }

    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();

        $this->capture->capture(
            $this->catalogue->codeForEvent((string)$event->getName()),
            $event->getData()
        );
    }
}
