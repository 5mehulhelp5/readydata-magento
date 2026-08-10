<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use ReadyData\Import\Api\Data\ImportResultInterface;
use ReadyData\Import\Model\BatchContext;
use ReadyData\Import\Model\Data\Product;

class BatchContextTest extends TestCase
{
    private function createContext(): BatchContext
    {
        return new BatchContext([
            (new Product())->setSku('A'),
            (new Product())->setSku('B'),
            (new Product())->setSku('C'),
        ], 2);
    }

    public function testProductsAreKeyedBySku(): void
    {
        $context = $this->createContext();

        self::assertSame(['A', 'B', 'C'], $context->getSkus());
        self::assertSame(2, $context->getStoreId());
        self::assertSame('B', $context->getProduct('B')?->getSku());
    }

    public function testFailExcludesProductFromValidSet(): void
    {
        $context = $this->createContext();
        $context->fail('B', 'broken');

        self::assertSame(['A', 'C'], array_keys($context->getValidProducts()));
        self::assertTrue($context->isFailed('B'));
        self::assertSame(['broken'], $context->getMessages('B'));
        self::assertSame(ImportResultInterface::STATUS_ERROR, $context->getStatus('B'));
    }

    public function testStatusReflectsExistence(): void
    {
        $context = $this->createContext();
        $context->markExisting('A');

        self::assertSame(ImportResultInterface::STATUS_UPDATED, $context->getStatus('A'));
        self::assertSame(ImportResultInterface::STATUS_CREATED, $context->getStatus('B'));
    }

    public function testValidEntityIdsSkipFailedProducts(): void
    {
        $context = $this->createContext();
        $context->setEntityId('A', 10);
        $context->setEntityId('B', 11);
        $context->fail('B', 'broken');

        self::assertSame([10], $context->getValidEntityIds());
    }

    /**
     * A scoped message says which store view it came from in the flat list, and
     * stays separable for callers that report per scope.
     */
    public function testScopedMessagesArePrefixedFlatAndSeparablePerScope(): void
    {
        $context = $this->createContext();
        $context->addMessage('A', 'product-wide');
        $context->addMessage('A', 'scoped', 3);

        self::assertSame(['product-wide', '[store 3] scoped'], $context->getMessages('A'));
        self::assertSame(['product-wide'], $context->getScopeMessages('A', null));
        self::assertSame(['scoped'], $context->getScopeMessages('A', 3));
        self::assertSame([], $context->getScopeMessages('A', 4));
    }

    public function testDataBagRoundTrip(): void
    {
        $context = $this->createContext();
        $context->set('link_ids', ['A' => 10]);

        self::assertSame(['A' => 10], $context->get('link_ids'));
        self::assertSame('fallback', $context->get('missing', 'fallback'));
    }

    public function testFailAllMarksEveryProduct(): void
    {
        $context = $this->createContext();
        $context->failAll('rollback');

        self::assertSame([], $context->getValidProducts());
        self::assertSame(['rollback'], $context->getMessages('C'));
    }
}
