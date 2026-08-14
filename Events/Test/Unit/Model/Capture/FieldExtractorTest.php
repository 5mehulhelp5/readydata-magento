<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Test\Unit\Model\Capture;

use Magento\Framework\DataObject;
use PHPUnit\Framework\TestCase;
use ReadyData\Events\Model\Capture\FieldExtractor;

class FieldExtractorTest extends TestCase
{
    private FieldExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new FieldExtractor();
    }

    public function testThinDefaultExtractsIdentifiersFromThePrimaryEntity(): void
    {
        $product = new DataObject(['entity_id' => 42, 'sku' => 'ABC-1', 'name' => 'Widget']);

        $payload = $this->extractor->extract(['data_object' => $product, 'product' => $product], []);

        self::assertSame(42, $payload['entity_id']);
        self::assertSame('ABC-1', $payload['sku']);
        self::assertArrayNotHasKey('name', $payload, 'The thin default carries identifiers, not content.');
    }

    /**
     * Magento puts almost nothing at the top level of event data, so a
     * subscription naming "sku" has to find the product's sku or the obvious
     * configuration silently extracts nothing.
     */
    public function testUnqualifiedPathFallsBackToThePrimaryEntity(): void
    {
        $product = new DataObject(['sku' => 'ABC-1', 'price' => '9.99']);

        $payload = $this->extractor->extract(['product' => $product], ['sku', 'price']);

        self::assertSame(['sku' => 'ABC-1', 'price' => '9.99'], $payload);
    }

    public function testDotNotationDescendsThroughNestedObjects(): void
    {
        $order = new DataObject([
            'increment_id' => '000000123',
            'customer' => new DataObject(['email' => 'a@example.com']),
        ]);

        self::assertSame('a@example.com', $this->extractor->resolve(['order' => $order], 'order.customer.email'));
    }

    public function testTopLevelWinsOverThePrimaryEntity(): void
    {
        $product = new DataObject(['sku' => 'FROM-PRODUCT']);

        self::assertSame('FROM-TOP', $this->extractor->resolve(['sku' => 'FROM-TOP', 'product' => $product], 'sku'));
    }

    public function testMissingPathYieldsNothingRatherThanAnError(): void
    {
        $payload = $this->extractor->extract(['product' => new DataObject(['sku' => 'A'])], ['nope.not.here']);

        self::assertSame([], $payload);
    }

    /**
     * The queue row has to stay small enough that a 200k-product import is
     * affordable, and a payload must never drag a loaded model into JSON.
     */
    public function testObjectsAreNeverReturned(): void
    {
        $product = new DataObject(['sku' => 'A', 'stock_item' => new DataObject(['qty' => 5])]);

        self::assertNull($this->extractor->resolve(['product' => $product], 'stock_item'));
    }

    public function testWildcardTakesEveryScalarButNoObjects(): void
    {
        $product = new DataObject([
            'sku' => 'A',
            'price' => 1.5,
            'resource' => new DataObject(['x' => 1]),
        ]);

        $payload = $this->extractor->extract(['product' => $product], ['*']);

        self::assertSame(['sku' => 'A', 'price' => 1.5], $payload);
    }
}
