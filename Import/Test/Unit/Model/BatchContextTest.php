<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use ReadyData\Import\Api\Data\ImportResultInterface;
use ReadyData\Import\Api\Data\StoreResultInterface;
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
        $context->registerScope('A', 3);
        $context->addMessage('A', 'product-wide');
        $context->addMessage('A', 'scoped', 3);

        self::assertSame(['product-wide', '[store 3] scoped'], $context->getMessages('A'));
        self::assertSame(['product-wide'], $context->getScopeMessages('A', null));
        self::assertSame(['scoped'], $context->getScopeMessages('A', 3));
        self::assertSame([], $context->getScopeMessages('A', 4));
    }

    /**
     * The product's own list is the complement of the per-scope lists: a
     * message tagged to a scope that never registered a result row would
     * otherwise reach no response field at all.
     */
    public function testAMessageTaggedToAScopeWithNoResultRowFallsBackToTheProduct(): void
    {
        $context = $this->createContext();
        $context->addMessage('A', 'merged into the base pass', 2);

        self::assertSame(['[store 2] merged into the base pass'], $context->getScopeMessages('A', null));
        self::assertSame(['merged into the base pass'], $context->getScopeMessages('A', 2));
    }

    public function testAScopeIsSkippedUntilSomethingIsAppliedInIt(): void
    {
        $context = $this->createContext();
        $context->registerScope('A', 3);
        $context->registerScope('A', 4);
        $context->markScopeApplied('A', 4);

        self::assertSame(
            [
                ['store_id' => 3, 'status' => StoreResultInterface::STATUS_SKIPPED,
                 'reason' => null, 'messages' => []],
                ['store_id' => 4, 'status' => StoreResultInterface::STATUS_UPDATED,
                 'reason' => null, 'messages' => []],
            ],
            $context->getScopeResults('A')
        );
    }

    /**
     * A batch is one transaction, so a failed product fails every scope it
     * carried — including ones that had already been written.
     */
    public function testAFailedProductFailsEveryScopeItCarried(): void
    {
        $context = $this->createContext();
        $context->registerScope('A', 3);
        $context->markScopeApplied('A', 3);
        $context->fail('A', 'rolled back');

        self::assertSame(
            StoreResultInterface::STATUS_ERROR,
            $context->getScopeResults('A')[0]['status']
        );
    }

    /**
     * A block whose store view could not be resolved still gets a row: the
     * caller matches rows against the blocks it sent, and there is no store ID
     * to key it by — 0 would name the default scope, which this list never
     * covers.
     */
    public function testAnUnresolvedBlockReportsAsASkippedScopeWithNoStoreId(): void
    {
        $context = $this->createContext();
        $context->registerScope('A', 3);
        $context->markScopeApplied('A', 3);
        $context->registerUnresolvedScope('A', 'unknown_store', 'no such store view');

        self::assertSame(
            [
                ['store_id' => 3, 'status' => StoreResultInterface::STATUS_UPDATED,
                 'reason' => null, 'messages' => []],
                ['store_id' => null, 'status' => StoreResultInterface::STATUS_SKIPPED,
                 'reason' => 'unknown_store', 'messages' => ['no such store view']],
            ],
            $context->getScopeResults('A')
        );
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
