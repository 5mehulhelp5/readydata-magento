<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Plugin;

use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;
use ReadyData\Events\Model\Capture\EventCapture;

/**
 * The MSI stock hook, and the reason this module generates plugins as well as
 * observers.
 *
 * Magento\Inventory\Model\SourceItem\Command\SourceItemsSave dispatches no
 * events at all — verified against the 2.4.8-p5 tree, not assumed. On any store
 * with MSI installed, a stock change is therefore invisible to every observer
 * in Magento, and intercepting the service contract is the only way to see it.
 * No single hook mechanism covers the catalogue.
 *
 * After, not around: an event is only worth emitting once the write succeeded,
 * and wrapping the call would put this code in the path of the exception.
 */
class SourceItemsSaveCapture
{
    private const CODE = 'plugin.magento.inventory_api.source_items_save';

    public function __construct(private readonly EventCapture $capture)
    {
    }

    /**
     * @param SourceItemInterface[] $sourceItems
     */
    public function afterExecute(
        SourceItemsSaveInterface $subject,
        mixed $result,
        array $sourceItems
    ): mixed {
        foreach ($sourceItems as $sourceItem) {
            if (!$sourceItem instanceof SourceItemInterface) {
                continue;
            }

            // One event per source item rather than one per call: a subscriber
            // cares about "this SKU's stock moved at this source", and a batched
            // save would otherwise collapse into a single event naming none of them.
            $this->capture->capture(self::CODE, [
                'sku' => $sourceItem->getSku(),
                'source_code' => $sourceItem->getSourceCode(),
                'quantity' => $sourceItem->getQuantity(),
                'status' => $sourceItem->getStatus(),
                'source_item' => $sourceItem,
            ]);
        }

        return $result;
    }
}
