<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Api;

/**
 * A PHP decision about whether one event instance is worth emitting.
 *
 * Declarative rules cover the easy cases and stay configurable from ReadyData's
 * UI, but they are `field|operator|value` and real integrations need more than
 * that: "not until increment_id is assigned", "not this payment method's known
 * double-save", "not if we already handled this entity in this request". None of
 * that is expressible as a comparison, and a subscription that needs it would
 * otherwise have to fork the module.
 *
 * The trade is explicit: a rule is remote configuration, a gate is a deploy. Reach
 * for a gate only when a rule genuinely cannot express the condition, or the
 * remote-configurability this module exists for erodes one gate at a time.
 *
 * @api
 */
interface EventGateInterface
{
    /**
     * @param string $eventCode The subscribed code, e.g. observer.catalog_product_save_commit_after
     * @param array $eventData Raw event data, before field extraction
     * @return bool False drops the event; nothing is queued and no row is written.
     */
    public function shouldEmit(string $eventCode, array $eventData): bool;
}
