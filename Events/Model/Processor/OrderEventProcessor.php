<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Model\Processor;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use ReadyData\Events\Api\EventDataProcessorInterface;

/**
 * Composes a whole order from a queue row carrying little more than an id.
 *
 * This is the case that justifies enrich-at-send existing at all. An order is
 * the one entity where the thin default is genuinely wrong: ReadyData would
 * otherwise make four or five follow-up REST calls per order to reassemble the
 * items, addresses, payment and totals into the single logical object it
 * actually needs, and it would make them for every order.
 *
 * Contrast with catalog events, which should stay thin: `product-import`
 * re-reads the source of truth anyway, so a fat product payload is work nobody
 * consumes.
 *
 * The order is loaded **now**, during dispatch, so the payload is current. An
 * order that changed twice between cron runs delivers once, describing where it
 * ended up rather than replaying where it passed through.
 */
class OrderEventProcessor implements EventDataProcessorInterface
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderCollectionFactory $orderCollectionFactory
    ) {
    }

    public function getPriority(): int
    {
        return 10;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function process(string $eventCode, array $payload): array
    {
        $order = $this->load($payload);
        if ($order === null) {
            // The order was deleted, or the payload carries no id we recognise.
            // Returning the payload unchanged keeps the event deliverable; the
            // subscriber will find nothing on re-read, which is the truth.
            return $payload;
        }

        return array_merge($payload, [
            'entity_id' => (int)$order->getEntityId(),
            'increment_id' => $order->getIncrementId(),
            'state' => $order->getState(),
            'status' => $order->getStatus(),
            'store_id' => (int)$order->getStoreId(),
            'created_at' => $order->getCreatedAt(),
            'updated_at' => $order->getUpdatedAt(),
            'customer' => [
                'id' => $order->getCustomerId() !== null ? (int)$order->getCustomerId() : null,
                'email' => $order->getCustomerEmail(),
                'is_guest' => (bool)$order->getCustomerIsGuest(),
                'group_id' => (int)$order->getCustomerGroupId(),
            ],
            'totals' => [
                'currency' => $order->getOrderCurrencyCode(),
                'grand_total' => (float)$order->getGrandTotal(),
                'subtotal' => (float)$order->getSubtotal(),
                'shipping' => (float)$order->getShippingAmount(),
                'tax' => (float)$order->getTaxAmount(),
                'discount' => (float)$order->getDiscountAmount(),
            ],
            'items' => $this->items($order),
            'addresses' => $this->addresses($order),
            'payment' => $this->payment($order),
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function load(array $payload): ?OrderInterface
    {
        if (isset($payload['entity_id']) && is_numeric($payload['entity_id'])) {
            try {
                return $this->orderRepository->get((int)$payload['entity_id']);
            } catch (\Throwable) {
                return null;
            }
        }

        // An order event may carry only the increment id — it is the identifier
        // a merchant recognises, so it is what most subscriptions ask for.
        if (isset($payload['increment_id']) && is_string($payload['increment_id'])) {
            $collection = $this->orderCollectionFactory->create()
                ->addFieldToFilter('increment_id', $payload['increment_id'])
                ->setPageSize(1);

            $order = $collection->getFirstItem();

            return $order->getId() ? $order : null;
        }

        return null;
    }

    /** @return array<int, array<string, mixed>> */
    private function items(OrderInterface $order): array
    {
        $items = [];

        foreach ($order->getItems() ?? [] as $item) {
            // Child rows of a configurable duplicate the parent's line; sending
            // both would double every quantity a subscriber sums.
            if ($item->getParentItemId()) {
                continue;
            }

            $items[] = [
                'sku' => $item->getSku(),
                'name' => $item->getName(),
                'qty_ordered' => (float)$item->getQtyOrdered(),
                'qty_shipped' => (float)$item->getQtyShipped(),
                'qty_refunded' => (float)$item->getQtyRefunded(),
                'price' => (float)$item->getPrice(),
                'row_total' => (float)$item->getRowTotal(),
                'tax_amount' => (float)$item->getTaxAmount(),
                'discount_amount' => (float)$item->getDiscountAmount(),
                'product_type' => $item->getProductType(),
            ];
        }

        return $items;
    }

    /** @return array<string, mixed> */
    private function addresses(OrderInterface $order): array
    {
        $shape = static function ($address): ?array {
            if ($address === null) {
                return null;
            }

            return [
                'firstname' => $address->getFirstname(),
                'lastname' => $address->getLastname(),
                'company' => $address->getCompany(),
                'street' => $address->getStreet(),
                'city' => $address->getCity(),
                'region' => $address->getRegion(),
                'postcode' => $address->getPostcode(),
                'country_id' => $address->getCountryId(),
                'telephone' => $address->getTelephone(),
            ];
        };

        return [
            'billing' => $shape($order->getBillingAddress()),
            'shipping' => $shape($order->getShippingAddress()),
        ];
    }

    /** @return array<string, mixed> */
    private function payment(OrderInterface $order): array
    {
        $payment = $order->getPayment();
        if ($payment === null) {
            return [];
        }

        return [
            'method' => $payment->getMethod(),
            'amount_ordered' => (float)$payment->getAmountOrdered(),
            'amount_paid' => (float)$payment->getAmountPaid(),
            'last_trans_id' => $payment->getLastTransId(),
        ];
    }
}
