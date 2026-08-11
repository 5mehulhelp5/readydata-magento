<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Api;

/**
 * Transforms one field's value as it is extracted.
 *
 * Runs at **capture** time, deliberately — the opposite end from
 * {@see EventDataProcessorInterface}. A converter is where masking belongs, and
 * masking is only worth anything if the unmasked value never lands anywhere: if
 * conversion happened at send, the raw postcode or email would already be
 * sitting in the queue table, in a database backup, and in whatever the
 * retention window has not deleted yet.
 *
 * So the rule is: **converters redact, processors enrich.** A converter can
 * make a value smaller, coarser or safer. It cannot go and read something,
 * because at capture time the transaction it is running inside may not have
 * committed.
 *
 * @api
 */
interface FieldConverterInterface
{
    /**
     * @param mixed $value The extracted value, before it reaches the payload.
     * @param string $field The field path this value came from.
     * @return mixed The value to store and deliver. Null drops the field entirely.
     */
    public function convert(mixed $value, string $field): mixed;
}
