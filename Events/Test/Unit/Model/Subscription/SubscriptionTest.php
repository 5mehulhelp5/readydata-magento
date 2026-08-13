<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Test\Unit\Model\Subscription;

use PHPUnit\Framework\TestCase;
use ReadyData\Events\Model\Subscription\Subscription;

class SubscriptionTest extends TestCase
{
    public function testDecodesAFullRow(): void
    {
        $subscription = Subscription::fromRow([
            'subscription_id' => '3',
            'event_code' => 'observer.catalog_product_save_commit_after',
            'subscriber_id' => '1',
            'fields' => '["sku","entity_id"]',
            'rules' => '[{"field":"status","operator":"eq","value":"1"}]',
            'gate_class' => 'My\Gate',
            'store_ids' => '1,2',
            'ignore_readydata_origin' => '1',
            'coalesce_by' => 'sku',
        ]);

        self::assertSame(3, $subscription->id);
        self::assertSame(['sku', 'entity_id'], $subscription->fields);
        self::assertSame([['field' => 'status', 'operator' => 'eq', 'value' => '1']], $subscription->rules);
        self::assertSame([1, 2], $subscription->storeIds);
        self::assertTrue($subscription->ignoreReadyDataOrigin);
        self::assertSame('sku', $subscription->coalesceBy);
    }

    public function testNullColumnsBecomeSafeDefaults(): void
    {
        $subscription = Subscription::fromRow([
            'subscription_id' => '1',
            'event_code' => 'observer.customer_save_commit_after',
            'subscriber_id' => '1',
            'fields' => null,
            'rules' => null,
            'gate_class' => '',
            'store_ids' => null,
            'coalesce_by' => '',
        ]);

        self::assertSame([], $subscription->fields);
        self::assertSame([], $subscription->rules);
        self::assertNull($subscription->gateClass);
        self::assertNull($subscription->storeIds);
        self::assertNull($subscription->coalesceBy);
        self::assertTrue($subscription->ignoreReadyDataOrigin, 'The loop guard defaults to on.');
    }

    /** Malformed JSON must not take down capture on every dispatch. */
    public function testMalformedJsonDegradesToEmpty(): void
    {
        $subscription = Subscription::fromRow([
            'subscription_id' => '1',
            'event_code' => 'observer.customer_save_commit_after',
            'subscriber_id' => '1',
            'fields' => 'not json',
            'rules' => '{"not":"a list"}',
        ]);

        self::assertSame([], $subscription->fields);
        self::assertSame([], $subscription->rules);
    }

    public function testStoreScoping(): void
    {
        $scoped = Subscription::fromRow([
            'subscription_id' => '1',
            'event_code' => 'c',
            'subscriber_id' => '1',
            'store_ids' => '2,3',
        ]);

        self::assertTrue($scoped->matchesStore(2));
        self::assertFalse($scoped->matchesStore(9));
        self::assertTrue(
            $scoped->matchesStore(null),
            'An event carrying no store id is not evidence of the wrong store.'
        );

        $unscoped = Subscription::fromRow(['subscription_id' => '2', 'event_code' => 'c', 'subscriber_id' => '1']);
        self::assertTrue($unscoped->matchesStore(9));
    }
}
