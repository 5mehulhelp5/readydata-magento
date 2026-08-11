<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Model\Capture;

use Magento\Framework\DataObject;

/**
 * Turns raw event data into the payload a subscription asked for.
 *
 * Two rules shape this class. It never serializes a whole model — a product
 * carries hundreds of attributes and the queue row has to stay small enough
 * that a 200k-product import is affordable. And it never returns an object:
 * anything that is not a scalar or a list of scalars is refused, so a payload
 * cannot accidentally drag a loaded entity, its resource model and its
 * connection into JSON.
 *
 * The default is deliberately thin. With no fields configured a subscription
 * sends identifiers only and ReadyData re-reads the source of truth, which is
 * both cheaper and immune to the ordering hazard of at-least-once delivery:
 * two saves of one SKU collapse to one re-read of present-tense state rather
 * than two snapshots that can be applied backwards.
 */
class FieldExtractor
{
    /**
     * Identifier fields tried, in order, when a subscription names no fields.
     * Anything that would let ReadyData find the entity again.
     */
    private const IDENTITY_FIELDS = [
        'entity_id',
        'sku',
        'increment_id',
        'id',
        'customer_id',
        'order_id',
        'category_id',
        'email',
        'store_id',
        'website_id',
    ];

    /**
     * @param array<string, mixed> $eventData
     * @param string[] $fields
     * @return array<string, mixed>
     */
    public function extract(array $eventData, array $fields): array
    {
        if ($fields === []) {
            return $this->extractIdentity($eventData);
        }

        if (in_array('*', $fields, true)) {
            return $this->extractAllScalars($eventData);
        }

        $payload = [];
        foreach ($fields as $path) {
            $value = $this->resolve($eventData, $path);
            if ($value !== null) {
                $payload[$path] = $value;
            }
        }

        return $payload;
    }

    /**
     * Walks a dot-notation path such as `order.customer_email`, descending
     * through arrays and DataObjects alike.
     *
     * A path that finds nothing at the top level is retried against the event's
     * primary entity, so a subscription can name `sku` and mean the product's
     * sku. Magento almost never puts the interesting values at the top level of
     * event data — `catalog_product_save_commit_after` carries only
     * `['data_object' => $product, 'product' => $product]` — so requiring
     * `product.sku` everywhere would make the obvious configuration silently
     * extract nothing.
     *
     * @param array<string, mixed> $eventData
     */
    public function resolve(array $eventData, string $path): mixed
    {
        $value = $this->walk($eventData, $path);
        if ($value !== null) {
            return $value;
        }

        $primary = $this->primary($eventData);

        return $primary === null ? null : $this->walk($primary, $path);
    }

    private function walk(mixed $root, string $path): mixed
    {
        $cursor = $root;

        foreach (explode('.', $path) as $segment) {
            $cursor = $this->step($cursor, $segment);
            if ($cursor === null) {
                return null;
            }
        }

        return $this->scalarize($cursor);
    }

    private function step(mixed $cursor, string $segment): mixed
    {
        if (is_array($cursor)) {
            return $cursor[$segment] ?? null;
        }

        if ($cursor instanceof DataObject) {
            return $cursor->getData($segment);
        }

        if (is_object($cursor)) {
            // Service-contract DTOs expose getters rather than getData().
            $getter = 'get' . str_replace('_', '', ucwords($segment, '_'));
            if (method_exists($cursor, $getter)) {
                return $cursor->$getter();
            }
        }

        return null;
    }

    /**
     * The primary entity of an event, for identity extraction. Magento's
     * conventions put it under one of these keys.
     *
     * @param array<string, mixed> $eventData
     */
    private function primary(array $eventData): mixed
    {
        foreach (['product', 'category', 'customer', 'order', 'object', 'data_object', 'item', 'quote'] as $key) {
            if (isset($eventData[$key])) {
                return $eventData[$key];
            }
        }

        return null;
    }

    /** @param array<string, mixed> $eventData @return array<string, mixed> */
    private function extractIdentity(array $eventData): array
    {
        $payload = [];

        foreach (self::IDENTITY_FIELDS as $field) {
            if (array_key_exists($field, $eventData)) {
                $value = $this->scalarize($eventData[$field]);
                if ($value !== null) {
                    $payload[$field] = $value;
                }
            }
        }

        $primary = $this->primary($eventData);
        if ($primary !== null) {
            foreach (self::IDENTITY_FIELDS as $field) {
                if (isset($payload[$field])) {
                    continue;
                }
                $value = $this->scalarize($this->step($primary, $field));
                if ($value !== null) {
                    $payload[$field] = $value;
                }
            }
        }

        return $payload;
    }

    /**
     * Every scalar the primary entity carries.
     *
     * Adobe supports this and warns against it in the same breath, and so do we:
     * it is the setting that puts PII and payment data on the wire, and it makes
     * the queue row as large as the entity. Prefer naming fields.
     *
     * @param array<string, mixed> $eventData
     * @return array<string, mixed>
     */
    private function extractAllScalars(array $eventData): array
    {
        $source = $this->primary($eventData);

        if ($source instanceof DataObject) {
            $source = $source->getData();
        } elseif (!is_array($source)) {
            $source = $eventData;
        }

        $payload = [];
        foreach ((array)$source as $key => $value) {
            $scalar = $this->scalarize($value);
            if ($scalar !== null) {
                $payload[(string)$key] = $scalar;
            }
        }

        return $payload;
    }

    /**
     * Scalars pass, lists of scalars pass, everything else is refused. Returning
     * null for an object is what keeps a loaded model out of the payload.
     */
    private function scalarize(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                if ($item === null || is_scalar($item)) {
                    $out[$key] = $item;
                }
            }

            return $out === [] ? null : $out;
        }

        return null;
    }
}
