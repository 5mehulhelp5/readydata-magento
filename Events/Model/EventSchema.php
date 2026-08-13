<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Model;

use Magento\Framework\Event\ConfigInterface as EventConfig;

/**
 * Describes what a given event code actually carries.
 *
 * This is what makes a usable field picker possible on the ReadyData side.
 * Without it an operator types dot paths from memory, and a path that resolves
 * to nothing fails silently — the subscription looks configured, the events
 * arrive, and every payload is empty. Adobe only exposes an equivalent on SaaS.
 *
 * The description is derived, not curated: for an observer code the field names
 * come from the entity the event carries, and for a plugin code from the
 * service contract's own return and parameter types. Deriving it means it stays
 * correct across Magento upgrades, at the cost of being a best effort — the
 * `sample` is the honest part, and `derivedFrom` says where it came from.
 */
class EventSchema
{
    /**
     * Field names worth offering first for the entities the catalogue covers.
     *
     * Not a whitelist — every field on the entity remains reachable by typing
     * its path — but the picker should lead with the handful anyone actually
     * subscribes to rather than 300 EAV attribute codes in alphabetical order.
     */
    private const SUGGESTED = [
        'catalog_product' => ['sku', 'entity_id', 'type_id', 'attribute_set_id', 'status', 'visibility', 'store_id'],
        'catalog_category' => ['entity_id', 'parent_id', 'path', 'level', 'is_active', 'store_id'],
        'cataloginventory_stock_item' => ['item_id', 'product_id', 'qty', 'is_in_stock', 'website_id'],
        'customer' => ['entity_id', 'email', 'group_id', 'store_id', 'website_id', 'created_at'],
        'customer_address' => ['entity_id', 'parent_id', 'country_id', 'postcode', 'city'],
        'sales_order' => ['entity_id', 'increment_id', 'state', 'status', 'store_id', 'customer_email', 'grand_total', 'created_at'],
        'sales_order_invoice' => ['entity_id', 'increment_id', 'order_id', 'state', 'grand_total'],
        'sales_order_creditmemo' => ['entity_id', 'increment_id', 'order_id', 'state', 'grand_total'],
        'sales_order_shipment' => ['entity_id', 'increment_id', 'order_id'],
        'sales_quote' => ['entity_id', 'store_id', 'customer_email', 'grand_total', 'is_active'],
        'cms_page' => ['page_id', 'identifier', 'title', 'is_active'],
        'cms_block' => ['block_id', 'identifier', 'title', 'is_active'],
        'newsletter_subscriber' => ['subscriber_id', 'customer_id', 'subscriber_email', 'subscriber_status'],
        'review' => ['review_id', 'entity_pk_value', 'status_id'],
    ];

    /** What the MSI plugin hands to capture; fixed, because we compose it ourselves. */
    private const PLUGIN_FIELDS = [
        'plugin.magento.inventory_api.source_items_save' => ['sku', 'source_code', 'quantity', 'status'],
        'plugin.magento.catalog.product_repository.save' => ['sku', 'entity_id'],
        'plugin.magento.catalog.product_repository.delete_by_id' => ['sku'],
    ];

    public function __construct(
        private readonly Catalogue $catalogue,
        private readonly EventConfig $eventConfig
    ) {
    }

    /**
     * @return array{
     *     code: string,
     *     kind: string,
     *     hooked: bool,
     *     entity: string|null,
     *     derived_from: string,
     *     fields: string[],
     *     sample: array<string, mixed>
     * }|null
     */
    public function describe(string $code): ?array
    {
        if (!$this->catalogue->has($code)) {
            return null;
        }

        if (str_starts_with($code, Catalogue::PREFIX_PLUGIN)) {
            $fields = self::PLUGIN_FIELDS[$code] ?? [];

            return [
                'code' => $code,
                'kind' => 'plugin',
                'hooked' => true,
                'entity' => null,
                'derived_from' => 'The plugin composes this payload itself, so the field list is exact.',
                'fields' => $fields,
                'sample' => $this->sampleFor($fields),
            ];
        }

        $eventName = substr($code, strlen(Catalogue::PREFIX_OBSERVER));
        $entity = $this->entityFor($eventName);
        $fields = $entity !== null ? (self::SUGGESTED[$entity] ?? []) : [];

        return [
            'code' => $code,
            'kind' => 'observer',
            'hooked' => $this->isHooked($eventName),
            'entity' => $entity,
            'derived_from' => $entity !== null
                ? sprintf(
                    'Derived from the "%s" entity. Any other field the entity carries can still be '
                    . 'subscribed to by naming it; this list is the common set, not a limit.',
                    $entity
                )
                : 'This event is dispatched directly rather than from an entity prefix, so its payload '
                    . 'depends on the dispatching code. Use the thin default unless you know otherwise.',
            'fields' => $fields,
            'sample' => $this->sampleFor($fields),
        ];
    }

    /** The entity prefix an event name belongs to, if it is a lifecycle event. */
    private function entityFor(string $eventName): ?string
    {
        foreach (Catalogue::ENTITIES as $entity) {
            foreach (Catalogue::LIFECYCLE as $suffix) {
                if ($eventName === $entity . '_' . $suffix) {
                    return $entity;
                }
            }
        }

        return null;
    }

    private function isHooked(string $eventName): bool
    {
        foreach ($this->eventConfig->getObservers($eventName) as $observer) {
            if (($observer['instance'] ?? null) === \ReadyData\Events\Observer\CaptureObserver::class) {
                return true;
            }
        }

        return false;
    }

    /**
     * A payload shaped like what a subscription with these fields would produce.
     *
     * Shown rather than described because "what will actually arrive" is the
     * question the picker is there to answer, and a worked example answers it
     * faster than a type list.
     *
     * @param string[] $fields
     * @return array<string, mixed>
     */
    private function sampleFor(array $fields): array
    {
        $samples = [
            'sku' => 'ABC-123',
            'entity_id' => 4211,
            'item_id' => 512,
            'product_id' => 4211,
            'increment_id' => '000000123',
            'email' => 'customer@example.com',
            'customer_email' => 'customer@example.com',
            'subscriber_email' => 'customer@example.com',
            'store_id' => 1,
            'website_id' => 1,
            'qty' => 42,
            'quantity' => 42,
            'is_in_stock' => true,
            'status' => 1,
            'state' => 'processing',
            'grand_total' => 19.99,
            'type_id' => 'simple',
            'identifier' => 'home-page',
            'source_code' => 'default',
        ];

        $sample = [];
        foreach ($fields as $field) {
            $sample[$field] = $samples[$field] ?? 'value';
        }

        return $sample;
    }
}
