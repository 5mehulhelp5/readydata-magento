<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Model;

/**
 * The curated superset of subscribable event codes.
 *
 * Adobe generates hooks for exactly the subscribed set, which makes a REST
 * subscribe inert until someone runs a CLI generate and a di:compile on the
 * server. That trades away remote configurability, which is the whole point of
 * this module, so instead we register a curated catalogue at build time and let
 * REST toggle subscriptions inside it with no deploy.
 *
 * Phase 0 priced that choice on a real 2.4.8-p5 store: 122 registered-but-idle
 * hooks cost ~0.06 ms per request (~0.5 us per registered event) and ~0.79 us
 * per event that actually fires, with no measurable effect on page render time
 * and no catalogue event firing at all during ordinary storefront traffic.
 *
 * Codes are addressed the way Adobe addresses them — `observer.<event_name>`
 * for Magento's own dispatch, `plugin.<service>.<method>` for an intercepted
 * service contract — so the naming and the mental model transfer.
 */
class Catalogue
{
    public const PREFIX_OBSERVER = 'observer.';
    public const PREFIX_PLUGIN = 'plugin.';

    /**
     * Entity prefixes whose lifecycle events are subscribable.
     *
     * Verified against the 2.4.8-p5 vendor tree: Magento\Framework\Model\AbstractModel
     * dispatches {_eventPrefix}_save_after / _delete_after, and
     * {_eventPrefix}_save_commit_after / _delete_commit_after from
     * afterCommitCallback(). These prefixes are declared on the models themselves.
     */
    public const ENTITIES = [
        'catalog_product',
        'catalog_category',
        'cataloginventory_stock_item',
        'customer',
        'customer_address',
        'customer_group',
        'sales_order',
        'sales_order_invoice',
        'sales_order_creditmemo',
        'sales_order_shipment',
        'sales_quote',
        'cms_page',
        'cms_block',
        'newsletter_subscriber',
        'review',
    ];

    public const LIFECYCLE = [
        'save_after',
        'save_commit_after',
        'delete_after',
        'delete_commit_after',
    ];

    /** Events dispatched directly rather than derived from an entity prefix. */
    public const STANDALONE = [
        'sales_order_place_after',
        'checkout_submit_all_after',
        'customer_login',
        'customer_register_success',
    ];

    /**
     * Intercepted service contracts.
     *
     * The MSI entry is not optional. Magento\Inventory\Model\SourceItem\Command\SourceItemsSave
     * dispatches no events at all — verified, not assumed — so on any store with
     * MSI installed a stock change is invisible to every observer, and a plugin
     * is the only hook. Neither mechanism alone covers this catalogue, which is
     * why both kinds are generated.
     *
     * @var array<string, array{type: string, method: string}>
     */
    public const PLUGINS = [
        'plugin.magento.inventory_api.source_items_save' => [
            'type' => \Magento\InventoryApi\Api\SourceItemsSaveInterface::class,
            'method' => 'execute',
        ],
        'plugin.magento.catalog.product_repository.save' => [
            'type' => \Magento\Catalog\Api\ProductRepositoryInterface::class,
            'method' => 'save',
        ],
        'plugin.magento.catalog.product_repository.delete_by_id' => [
            'type' => \Magento\Catalog\Api\ProductRepositoryInterface::class,
            'method' => 'deleteById',
        ],
    ];

    /**
     * Magento event names the catalogue registers observers for.
     *
     * @return string[]
     */
    public function eventNames(): array
    {
        $names = [];
        foreach (self::ENTITIES as $entity) {
            foreach (self::LIFECYCLE as $suffix) {
                $names[] = $entity . '_' . $suffix;
            }
        }

        return array_values(array_unique(array_merge($names, self::STANDALONE)));
    }

    /**
     * Every subscribable code, observer and plugin alike.
     *
     * @return string[]
     */
    public function codes(): array
    {
        $codes = array_map(
            static fn(string $name): string => self::PREFIX_OBSERVER . $name,
            $this->eventNames()
        );

        return array_merge($codes, array_keys(self::PLUGINS));
    }

    public function has(string $code): bool
    {
        return in_array($code, $this->codes(), true);
    }

    /** The code a dispatched Magento event maps to. */
    public function codeForEvent(string $eventName): string
    {
        return self::PREFIX_OBSERVER . $eventName;
    }
}
