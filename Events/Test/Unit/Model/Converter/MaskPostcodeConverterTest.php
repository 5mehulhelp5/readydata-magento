<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Test\Unit\Model\Converter;

use PHPUnit\Framework\TestCase;
use ReadyData\Events\Model\Converter\MaskPostcodeConverter;

class MaskPostcodeConverterTest extends TestCase
{
    private MaskPostcodeConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new MaskPostcodeConverter();
    }

    public function testKeepsEnoughToBeUsefulAndHidesTheRest(): void
    {
        // 'SW1A 1AA' is 8 characters: two kept, six masked.
        self::assertSame('SW******', $this->converter->convert('SW1A 1AA', 'postcode'));
        self::assertSame('10***', $this->converter->convert('10115', 'postcode'));
    }

    /** A postcode short enough to identify nothing extra is masked entirely. */
    public function testVeryShortValuesAreFullyMasked(): void
    {
        self::assertSame('**', $this->converter->convert('AB', 'postcode'));
        self::assertSame('*', $this->converter->convert('X', 'postcode'));
    }

    public function testLeavesNonStringsAlone(): void
    {
        self::assertNull($this->converter->convert(null, 'postcode'));
        self::assertSame(12345, $this->converter->convert(12345, 'postcode'));
        self::assertSame('', $this->converter->convert('', 'postcode'));
    }

    public function testMaskLengthDoesNotLeakTheOriginalLengthBeyondWhatIsShown(): void
    {
        // The mask is the same length as the input, which is a deliberate
        // trade: it keeps the field recognisable as a postcode for a human
        // reading a payload, at the cost of revealing its length.
        self::assertSame(mb_strlen('SW1A 1AA'), mb_strlen((string)$this->converter->convert('SW1A 1AA', 'postcode')));
    }
}
