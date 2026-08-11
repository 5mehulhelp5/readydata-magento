<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Api;

/**
 * Enriches an event's payload at **send** time, not at capture time.
 *
 * This is the piece that makes thin capture and rich delivery the same design
 * rather than opposing ones. The queue row stays small — an identifier and
 * little else — which is what makes a 200k-product import affordable to
 * capture. The wire payload can still be as rich as a subscriber needs,
 * because it is composed here, during the dispatch cron.
 *
 * Composing at send has a property a capture-time snapshot cannot have: the
 * read is **current**. Two saves of one SKU collapse into one delivery carrying
 * present-tense state, instead of two snapshots that at-least-once delivery may
 * apply in the wrong order. That is why this exists rather than a "fat capture"
 * switch.
 *
 * The trade is real and worth stating: a processor re-reads the entity, so it
 * costs a query per event at dispatch. Reach for one only where ReadyData would
 * otherwise make N follow-up calls to reassemble a single logical object — an
 * order with its items, addresses and payments being the case that justifies
 * it. Catalog events almost never need one, because product-import re-reads
 * anyway; adding payload there buys nothing.
 *
 * Implementations must not throw. A processor that fails should return the
 * payload it was given: a thinner event still delivers and the subscriber
 * re-reads, whereas an exception fails the whole batch.
 *
 * @api
 */
interface EventDataProcessorInterface
{
    /**
     * @param string $eventCode The subscribed code the payload belongs to.
     * @param array $payload The stored payload — whatever capture extracted.
     * @return array The payload to deliver. Return $payload unchanged to opt out.
     */
    public function process(string $eventCode, array $payload): array;

    /**
     * Lower runs first. Lets one processor depend on another having already
     * loaded and added the entity it needs.
     *
     * @return int
     */
    public function getPriority(): int;
}
