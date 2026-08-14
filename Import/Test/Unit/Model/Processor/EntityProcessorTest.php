<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Test\Unit\Model\Processor;

use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReadyData\Import\Model\BatchContext;
use ReadyData\Import\Model\Cache\AttributeMetadataCache;
use ReadyData\Import\Model\Data\Product;
use ReadyData\Import\Model\ImportLocks;
use ReadyData\Import\Model\Processor\EntityProcessor;
use ReadyData\Import\Model\ResourceModel\ProductEntity;

/**
 * The product-row lock predicate, and the guard for the window it cannot close.
 *
 * This predicate was always exact — `catalog_product_entity.sku` carries a plain
 * index, not a unique key, so an insert of an unknown SKU is itself the race and
 * only the database can say whether the row is there.
 */
class EntityProcessorTest extends TestCase
{
    private ProductEntity&MockObject $productEntity;

    /** @var string[] SKUs the catalog holds */
    private array $existingSkus = [];

    protected function setUp(): void
    {
        $this->existingSkus = ['P1', 'P2'];

        $this->productEntity = $this->createMock(ProductEntity::class);
        $this->productEntity->method('getLinkField')->willReturn('entity_id');
        $this->productEntity->method('isStagingEnvironment')->willReturn(false);
        $this->productEntity->method('getExistingBySkus')->willReturnCallback(
            fn (array $skus): array => array_fill_keys(
                array_values(array_intersect($skus, $this->existingSkus)),
                ['entity_id' => 42, 'link_id' => 42, 'attribute_set_id' => 4, 'type_id' => 'simple']
            )
        );
    }

    private function processor(): EntityProcessor
    {
        $metadataCache = $this->createMock(AttributeMetadataCache::class);
        $metadataCache->method('resolveAttributeSetId')->willReturn(4);

        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtDate')->willReturn('2026-08-12 00:00:00');

        return new EntityProcessor($this->productEntity, $metadataCache, $dateTime);
    }

    /**
     * @param string[] $skus
     */
    private function context(array $skus, bool $holdsLock = false): BatchContext
    {
        $products = array_map(
            static fn (string $sku): Product => (new Product())->setSku($sku)->setName('A name'),
            $skus
        );

        $context = new BatchContext($products);
        if ($holdsLock) {
            $context->setHeldLocks([ImportLocks::PRODUCT_CREATE]);
        }

        return $context;
    }

    public function testABatchOfProductsThatAllExistTakesNoLock(): void
    {
        self::assertSame([], $this->processor()->requiredLocks($this->context(['P1', 'P2'])));
    }

    public function testOneUnknownSkuTakesTheProductLock(): void
    {
        self::assertSame(
            [ImportLocks::PRODUCT_CREATE],
            $this->processor()->requiredLocks($this->context(['P1', 'NEW-1']))
        );
    }

    public function testAnEmptyBatchTakesNothingAndAsksNothing(): void
    {
        $this->productEntity->expects(self::never())->method('getExistingBySkus');

        self::assertSame([], $this->processor()->requiredLocks(new BatchContext([])));
    }

    /**
     * The window the predicate cannot close: the SKU read as existing when the
     * lock decision was made, and its row is gone now. Inserting here is exactly
     * the unguarded read-then-create the lock exists to prevent, so the product
     * is reported instead — the retry's predicate sees the gap, takes the lock,
     * and creates it.
     */
    public function testASkuThatVanishesAfterTheLockDecisionIsReportedNotCreated(): void
    {
        $context = $this->context(['P1'], holdsLock: false);
        $this->existingSkus = [];

        $this->productEntity->expects(self::once())->method('upsert')->with([]);

        $this->processor()->process($context);

        self::assertTrue($context->isFailed('P1'));
        self::assertStringContainsString('vanished', $context->getMessages('P1')[0]);
    }

    public function testTheSameSkuIsCreatedNormallyWhenTheBatchHoldsTheLock(): void
    {
        $context = $this->context(['NEW-1'], holdsLock: true);
        $this->existingSkus = [];

        $inserted = [];
        $this->productEntity->expects(self::once())->method('upsert')
            ->willReturnCallback(function (array $rows) use (&$inserted): void {
                $inserted = $rows;
                // The row exists from here on, which is what the re-select that
                // picks up generated IDs depends on.
                $this->existingSkus = array_column($rows, 'sku');
            });

        $this->processor()->process($context);

        self::assertSame(['NEW-1'], array_column($inserted, 'sku'));
        self::assertFalse($context->isFailed('NEW-1'));
        self::assertSame(42, $context->getEntityId('NEW-1'));
    }
}
