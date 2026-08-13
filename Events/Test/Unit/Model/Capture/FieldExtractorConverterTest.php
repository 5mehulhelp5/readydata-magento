<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Test\Unit\Model\Capture;

use Magento\Framework\DataObject;
use PHPUnit\Framework\TestCase;
use ReadyData\Events\Model\Capture\FieldExtractor;

/**
 * Converters run at capture, so the raw value never reaches the queue table.
 * These pin that placement, not just the transformation.
 */
class FieldExtractorConverterTest extends TestCase
{
    private FieldExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new FieldExtractor();
    }

    private function order(): array
    {
        $order = new DataObject([
            'increment_id' => '000000123',
            'postcode' => 'SW1A 1AA',
            'customer_email' => 'a@example.com',
        ]);

        return ['order' => $order];
    }

    public function testConverterRewritesTheStoredValue(): void
    {
        $payload = $this->extractor->extract(
            $this->order(),
            ['increment_id', 'postcode'],
            ['postcode' => static fn(mixed $v): string => 'MASKED'],
        );

        self::assertSame('000000123', $payload['increment_id']);
        self::assertSame('MASKED', $payload['postcode'], 'The raw postcode must never be stored.');
    }

    public function testConverterReturningNullDropsTheFieldEntirely(): void
    {
        $payload = $this->extractor->extract(
            $this->order(),
            ['increment_id', 'customer_email'],
            ['customer_email' => static fn(): mixed => null],
        );

        self::assertArrayHasKey('increment_id', $payload);
        self::assertArrayNotHasKey('customer_email', $payload, 'Redact-entirely needs no second mechanism.');
    }

    public function testFieldsWithoutAConverterAreUntouched(): void
    {
        $payload = $this->extractor->extract(
            $this->order(),
            ['increment_id', 'postcode'],
            ['nothing_matches_this' => static fn(): string => 'X'],
        );

        self::assertSame('SW1A 1AA', $payload['postcode']);
    }

    public function testConvertersAlsoApplyToTheThinDefault(): void
    {
        // The thin default is the most common configuration, so a converter that
        // only worked on explicit field lists would silently not apply where it
        // is most likely to be needed.
        $payload = $this->extractor->extract(
            $this->order(),
            [],
            ['increment_id' => static fn(): string => 'REDACTED'],
        );

        self::assertSame('REDACTED', $payload['increment_id']);
    }

    public function testConverterReceivesTheFieldNameSoOneClassCanServeManyFields(): void
    {
        $seen = [];
        $payload = $this->extractor->extract(
            $this->order(),
            ['increment_id', 'postcode'],
            [
                'increment_id' => static function (mixed $v, string $field) use (&$seen): mixed {
                    $seen[] = $field;
                    return $v;
                },
                'postcode' => static function (mixed $v, string $field) use (&$seen): mixed {
                    $seen[] = $field;
                    return $v;
                },
            ],
        );

        self::assertSame(['increment_id', 'postcode'], $seen);
        self::assertCount(2, $payload);
    }
}
