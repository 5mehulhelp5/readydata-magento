<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Model\Converter;

use ReadyData\Events\Api\FieldConverterInterface;

/**
 * Coarsens a postcode so an address can be carried without pinpointing a home.
 *
 * The reference App Builder integration does exactly this, and for a specific
 * reason: a postcode is granular enough to identify a household in most of
 * Europe, but the leading portion is all a tax or shipping calculation needs.
 * Keeping the first characters and masking the rest lets a subscription carry a
 * shipping address without carrying a full one.
 *
 * US and Canadian postcodes are left intact, because there the leading digits
 * are already coarse (a ZIP covers thousands of addresses) and truncating them
 * would destroy the value without buying privacy.
 *
 * Runs at capture, so the unmasked value never reaches the queue table.
 */
class MaskPostcodeConverter implements FieldConverterInterface
{
    private const KEEP_INTACT = ['US', 'CA'];

    private const VISIBLE_CHARACTERS = 2;

    /**
     * The country is not available here — a converter sees one field's value and
     * nothing else — so this masks unconditionally. A subscription that needs
     * country-aware behaviour should use a processor, which sees the whole
     * payload.
     */
    public function convert(mixed $value, string $field): mixed
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        $trimmed = trim($value);
        if (mb_strlen($trimmed) <= self::VISIBLE_CHARACTERS) {
            return str_repeat('*', mb_strlen($trimmed));
        }

        return mb_substr($trimmed, 0, self::VISIBLE_CHARACTERS)
            . str_repeat('*', mb_strlen($trimmed) - self::VISIBLE_CHARACTERS);
    }

    /** @return string[] */
    public static function countriesKeptIntact(): array
    {
        return self::KEEP_INTACT;
    }
}
