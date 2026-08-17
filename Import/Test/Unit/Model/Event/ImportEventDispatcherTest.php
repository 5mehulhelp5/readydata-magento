<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Test\Unit\Model\Event;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ProductFactory;
use Magento\Framework\Event\ManagerInterface as EventManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReadyData\Import\Logger\Logger;
use ReadyData\Import\Model\BatchContext;
use ReadyData\Import\Model\Config;
use ReadyData\Import\Model\Data\Product as ProductDto;
use ReadyData\Import\Model\Event\ImportEventDispatcher;
use ReadyData\Import\Model\Event\ProductMediaHydrator;
use ReadyData\Import\Model\Processor\AttributeProcessor;
use ReadyData\Import\Model\Processor\CategoryLinkProcessor;
use ReadyData\Import\Model\Processor\EntityProcessor;
use ReadyData\Import\Model\Processor\MediaProcessor;
use ReadyData\Import\Model\ResourceModel\ProductEntity;

class ImportEventDispatcherTest extends TestCase
{
    private ProductFactory&MockObject $productFactory;
    private EventManager&MockObject $eventManager;
    private ProductEntity&MockObject $productEntity;
    private Config&MockObject $config;
    private Logger&MockObject $logger;
    private ProductMediaHydrator&MockObject $mediaHydrator;

    /**
     * @var array<int, array{name: string, data: array}>
     */
    private array $dispatched = [];

    protected function setUp(): void
    {
        $this->dispatched = [];

        $this->productFactory = $this->createMock(ProductFactory::class);
        // Real (constructor-less) product objects so setData/getId/lockAttribute
        // work. Accessors routed through the type instance (getSku(), getWeight())
        // do NOT — assert on getData() instead.
        $this->productFactory->method('create')->willReturnCallback(
            static fn (): Product =>
                (new \ReflectionClass(Product::class))->newInstanceWithoutConstructor()
        );

        $this->eventManager = $this->createMock(EventManager::class);
        $this->eventManager->method('dispatch')->willReturnCallback(
            function (string $name, array $data = []): void {
                $this->dispatched[] = ['name' => $name, 'data' => $data];
            }
        );

        $this->productEntity = $this->createMock(ProductEntity::class);
        $this->productEntity->method('getLinkField')->willReturn('entity_id');

        $this->config = $this->createMock(Config::class);
        $this->config->method('isDispatchProductEvents')->willReturn(true);
        // isHydrateEventMedia() defaults to false here, as it does in config.xml.

        $this->logger = $this->createMock(Logger::class);
        $this->mediaHydrator = $this->createMock(ProductMediaHydrator::class);
    }

    public function testCommitAfterFiresPerProductWithCorrectPayload(): void
    {
        $context = $this->createContext(['SKU-NEW' => 10, 'SKU-OLD' => 20], existing: ['SKU-OLD']);

        $this->newDispatcher()->dispatchAfterCommit($context);

        $commit = $this->eventsNamed('catalog_product_save_commit_after');
        self::assertCount(2, $commit);
        self::assertSame(2, count($this->eventsNamed('model_save_commit_after')));

        // Payload carries both keys and the same product instance, with the right id/sku.
        $byId = [];
        foreach ($commit as $event) {
            self::assertSame($event['data']['product'], $event['data']['data_object']);
            $byId[$event['data']['product']->getId()] = $event['data']['product']->getData('sku');
        }
        self::assertSame(['SKU-NEW', 'SKU-OLD'], [$byId[10], $byId[20]]);
    }

    public function testCustomProductsEventCarriesCreatedUpdatedSplit(): void
    {
        $context = $this->createContext(['SKU-NEW' => 10, 'SKU-OLD' => 20], existing: ['SKU-OLD']);

        $this->newDispatcher()->dispatchAfterCommit($context);

        $events = $this->eventsNamed(ImportEventDispatcher::EVENT_PRODUCTS_SAVE_AFTER);
        self::assertCount(1, $events);
        $data = $events[0]['data'];
        self::assertSame(['SKU-NEW' => 10, 'SKU-OLD' => 20], $data['sku_to_id']);
        self::assertSame(['SKU-NEW'], $data['created_skus']);
        self::assertSame(['SKU-OLD'], $data['updated_skus']);
        self::assertSame([10, 20], $data['entity_ids']);
    }

    public function testNoEventsWhenDispatchDisabled(): void
    {
        // Master switch off beats the save-after toggle being on.
        $config = $this->createMock(Config::class);
        $config->method('isDispatchProductEvents')->willReturn(false);
        $config->method('isDispatchSaveAfter')->willReturn(true);
        $dispatcher = $this->newDispatcher($config);

        $context = $this->createContext(['SKU-A' => 1], existing: []);
        $dispatcher->dispatchBeforeCommit($context);
        $dispatcher->dispatchAfterCommit($context);

        self::assertSame([], $this->dispatched);
    }

    public function testSaveAfterFiresOnlyWhenToggleOn(): void
    {
        $context = $this->createContext(['SKU-A' => 1], existing: []);

        // Default config (setUp): events on, save-after off → nothing.
        $this->newDispatcher()->dispatchBeforeCommit($context);
        self::assertSame([], $this->eventsNamed('catalog_product_save_after'));

        // Both on → the in-transaction save_after events fire per product.
        $config = $this->createMock(Config::class);
        $config->method('isDispatchProductEvents')->willReturn(true);
        $config->method('isDispatchSaveAfter')->willReturn(true);
        $this->newDispatcher($config)->dispatchBeforeCommit($context);
        self::assertCount(1, $this->eventsNamed('catalog_product_save_after'));
        self::assertCount(1, $this->eventsNamed('model_save_after'));
    }

    public function testCustomEventsAreGatedOnContextData(): void
    {
        // Without option/category data, only the products event fires.
        $context = $this->createContext(['SKU-A' => 1], existing: []);
        $this->newDispatcher()->dispatchAfterCommit($context);
        self::assertSame([], $this->eventsNamed(ImportEventDispatcher::EVENT_ATTRIBUTE_OPTIONS_CREATED));
        self::assertSame([], $this->eventsNamed(ImportEventDispatcher::EVENT_CATEGORY_PRODUCTS_CHANGED));

        // With them present, both fire with their payloads.
        $this->dispatched = [];
        $context->set(AttributeProcessor::CONTEXT_CREATED_OPTIONS, ['color' => ['red' => 55]]);
        $context->set(CategoryLinkProcessor::CONTEXT_AFFECTED_CATEGORY_IDS, [3, 4]);
        $context->set(CategoryLinkProcessor::CONTEXT_AFFECTED_PRODUCT_IDS, [1]);
        $this->newDispatcher()->dispatchAfterCommit($context);

        $options = $this->eventsNamed(ImportEventDispatcher::EVENT_ATTRIBUTE_OPTIONS_CREATED);
        self::assertCount(1, $options);
        self::assertSame(['color' => ['red' => 55]], $options[0]['data']['options_by_attribute']);

        $categories = $this->eventsNamed(ImportEventDispatcher::EVENT_CATEGORY_PRODUCTS_CHANGED);
        self::assertCount(1, $categories);
        self::assertSame([3, 4], $categories[0]['data']['category_ids']);
        self::assertSame([1], $categories[0]['data']['product_ids']);
    }

    public function testAfterCommitObserverFailureIsCaughtAndLogged(): void
    {
        $eventManager = $this->createMock(EventManager::class);
        $eventManager->method('dispatch')->willThrowException(new \RuntimeException('boom'));

        // Every dispatch throws, and each concern has a handler of its own, so
        // both report: once for the per-product events and once for the
        // batch-level custom ones. That separation is the point — one failing
        // observer must not cost the other kind of event its dispatch — so the
        // count is asserted per message rather than as a bare total.
        $logged = [];
        $this->logger->expects(self::exactly(2))->method('error')
            ->willReturnCallback(function (string $message) use (&$logged): void {
                $logged[] = $message;
            });

        $dispatcher = new ImportEventDispatcher(
            $this->productFactory,
            $eventManager,
            $this->productEntity,
            $this->config,
            $this->logger,
            $this->mediaHydrator
        );

        // Must not throw.
        $dispatcher->dispatchAfterCommit($this->createContext(['SKU-A' => 1], existing: []));

        self::assertStringContainsString('SKU-A', $logged[0]);
        self::assertStringContainsString('boom', $logged[0]);
        self::assertStringContainsString('Custom import event dispatch failed', $logged[1]);
        // Never the outer "abandoned for the batch" handler: that one means
        // buildProducts() failed and nothing was dispatched at all, which is a
        // different event from an observer throwing.
        self::assertStringNotContainsString('abandoned', $logged[0] . $logged[1]);
    }

    public function testCommitAfterObserverFailureDoesNotSkipOtherProducts(): void
    {
        $recorded = [];
        $eventManager = $this->createMock(EventManager::class);
        $eventManager->method('dispatch')->willReturnCallback(
            function (string $name, array $data = []) use (&$recorded): void {
                if ($name !== 'catalog_product_save_commit_after') {
                    return;
                }
                if ($data['product']->getData('sku') === 'SKU-BAD') {
                    throw new \RuntimeException('observer boom');
                }
                $recorded[] = $data['product']->getData('sku');
            }
        );
        $this->logger->expects(self::atLeastOnce())->method('error');

        $dispatcher = new ImportEventDispatcher(
            $this->productFactory,
            $eventManager,
            $this->productEntity,
            $this->config,
            $this->logger,
            $this->mediaHydrator
        );

        // SKU-BAD is processed first and throws; SKU-GOOD must still fire.
        $dispatcher->dispatchAfterCommit($this->createContext(['SKU-BAD' => 1, 'SKU-GOOD' => 2], existing: []));

        self::assertContains('SKU-GOOD', $recorded);
    }

    public function testProductInstanceIsSharedBetweenSaveAfterAndCommitAfter(): void
    {
        // Core fires both events on one object; the batch is therefore built —
        // and hydrated — exactly once, however many dispatches read it.
        $this->mediaHydrator->expects(self::once())->method('hydrate');
        $dispatcher = $this->newDispatcher($this->configWith(saveAfter: true, hydrateMedia: true));
        $context = $this->createContext(['SKU-A' => 10], existing: []);

        $dispatcher->dispatchBeforeCommit($context);
        $dispatcher->dispatchAfterCommit($context);

        $saveAfter = $this->eventsNamed('catalog_product_save_after');
        $commitAfter = $this->eventsNamed('catalog_product_save_commit_after');
        self::assertCount(1, $saveAfter);
        self::assertCount(1, $commitAfter);
        self::assertSame($saveAfter[0]['data']['product'], $commitAfter[0]['data']['product']);
    }

    public function testMediaIsNotHydratedWhenTheToggleIsOff(): void
    {
        // setUp's config leaves isHydrateEventMedia() false.
        $this->mediaHydrator->expects(self::never())->method('hydrate');

        $this->newDispatcher()->dispatchAfterCommit($this->createContext(['SKU-A' => 10], existing: []));

        $product = $this->eventsNamed('catalog_product_save_commit_after')[0]['data']['product'];
        self::assertFalse($product->hasData('media_gallery'));
    }

    public function testMediaIsNotHydratedWhenProductEventsAreOff(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isDispatchProductEvents')->willReturn(false);
        $config->method('isHydrateEventMedia')->willReturn(true);
        $this->mediaHydrator->expects(self::never())->method('hydrate');

        $dispatcher = $this->newDispatcher($config);
        $context = $this->createContext(['SKU-A' => 10], existing: []);
        $dispatcher->dispatchBeforeCommit($context);
        $dispatcher->dispatchAfterCommit($context);
    }

    public function testHydratorReceivesProductsKeyedByLinkField(): void
    {
        $captured = [];
        $capturedStoreId = null;
        $this->mediaHydrator->method('hydrate')->willReturnCallback(
            function (array $productsByLinkId, int $storeId) use (&$captured, &$capturedStoreId): void {
                $capturedStoreId = $storeId;
                foreach ($productsByLinkId as $linkId => $product) {
                    $captured[$linkId] = $product->getData('sku');
                }
            }
        );

        $context = $this->createContext(['SKU-A' => 10, 'SKU-B' => 20], existing: []);
        // SKU-A carries a resolved link ID; SKU-B has none, and on this install
        // the link field IS entity_id, so falling back to it is exact.
        $context->set(EntityProcessor::CONTEXT_LINK_IDS, ['SKU-A' => 71]);
        $this->newDispatcher($this->configWith(hydrateMedia: true))->dispatchAfterCommit($context);

        self::assertSame([71 => 'SKU-A', 20 => 'SKU-B'], $captured);
        self::assertSame(1, $capturedStoreId);
    }

    public function testAProductWithNoResolvedLinkIdIsNotHydrated(): void
    {
        // On Commerce the link field is row_id, and an entity_id used in its
        // place can land on a DIFFERENT product's row — which would hand the
        // observer someone else's gallery under this product's SKU.
        $captured = null;
        $this->mediaHydrator->method('hydrate')->willReturnCallback(
            function (array $productsByLinkId) use (&$captured): void {
                $captured = array_map(
                    static fn (Product $product): string => $product->getData('sku'),
                    $productsByLinkId
                );
            }
        );
        $this->logger->expects(self::once())->method('error');

        $context = $this->createContext(['SKU-A' => 10, 'SKU-B' => 20], existing: []);
        $context->set(EntityProcessor::CONTEXT_LINK_IDS, ['SKU-A' => 71]);
        $this->newDispatcherWithLinkField('row_id')->dispatchAfterCommit($context);

        self::assertSame([71 => 'SKU-A'], $captured);

        $bySku = [];
        foreach ($this->eventsNamed('catalog_product_save_commit_after') as $event) {
            $bySku[$event['data']['product']->getData('sku')] = $event['data']['product'];
        }
        self::assertSame(71, $bySku['SKU-A']->getData('row_id'));
        // Not entity_id-as-row_id: no link ID at all beats a wrong one.
        self::assertNull($bySku['SKU-B']->getData('row_id'));
    }

    public function testTwoSkusClaimingOneLinkIdHydrateNeither(): void
    {
        $captured = null;
        $this->mediaHydrator->method('hydrate')->willReturnCallback(
            function (array $productsByLinkId) use (&$captured): void {
                $captured = array_keys($productsByLinkId);
            }
        );

        $context = $this->createContext(['SKU-A' => 10, 'SKU-B' => 20], existing: []);
        $context->set(EntityProcessor::CONTEXT_LINK_IDS, ['SKU-A' => 71, 'SKU-B' => 71]);
        $this->newDispatcherWithLinkField('row_id')->dispatchAfterCommit($context);

        // At least one of the two is wrong and there is no telling which.
        self::assertSame([], $captured);
    }

    public function testEachBatchIsHydratedOnceAndReleasedAfterwards(): void
    {
        // Two batches → two reads: the memo is per batch, and dispatching the
        // same context again after its release rebuilds rather than replaying.
        $this->mediaHydrator->expects(self::exactly(3))->method('hydrate');
        $dispatcher = $this->newDispatcher($this->configWith(hydrateMedia: true));

        $first = $this->createContext(['SKU-A' => 10], existing: []);
        $second = $this->createContext(['SKU-B' => 20], existing: []);
        $dispatcher->dispatchAfterCommit($first);
        $dispatcher->dispatchAfterCommit($second);
        $dispatcher->dispatchAfterCommit($first);
    }

    public function testDispatchedProductCarriesNoOrigData(): void
    {
        // Pinned deliberately: a partial origData would answer "unchanged" for
        // fields nobody snapshotted, and populating it at all makes core skip
        // the protective reload it does for an entity with no original data.
        $this->newDispatcher()->dispatchAfterCommit($this->createContext(['SKU-A' => 10], existing: ['SKU-A']));

        $product = $this->eventsNamed('catalog_product_save_commit_after')[0]['data']['product'];
        self::assertSame([], $product->getOrigData() ?? []);
    }

    public function testMediaChangedEventCarriesThePerSkuDeltaAndDedupedFileUnions(): void
    {
        $context = $this->createContext(['SKU-A' => 10, 'SKU-B' => 20], existing: []);
        $changes = [
            'SKU-A' => [
                'entity_id' => 10,
                'created' => ['/s/h/shared.jpg', '/a/a/a.jpg'],
                'updated' => [],
                'removed' => ['/o/l/old.jpg'],
                'roles' => [],
                'partial' => false,
            ],
            'SKU-B' => [
                'entity_id' => 20,
                // Both products import the same file; a per-file consumer must
                // only hear about it once.
                'created' => ['/s/h/shared.jpg'],
                'updated' => ['/b/b/b.jpg'],
                'removed' => [],
                'roles' => ['image' => '/b/b/b.jpg'],
                'partial' => true,
            ],
        ];
        $context->set(MediaProcessor::CONTEXT_CHANGES, $changes);
        $context->set(MediaProcessor::CONTEXT_RETAINED_FILES, [
            '/s/h/shared.jpg',
            '/a/a/a.jpg',
            '/b/b/b.jpg',
        ]);

        $this->newDispatcher()->dispatchAfterCommit($context);

        $events = $this->eventsNamed(ImportEventDispatcher::EVENT_PRODUCT_MEDIA_CHANGED);
        self::assertCount(1, $events);
        $data = $events[0]['data'];
        self::assertSame(1, $data['store_id']);
        self::assertSame($changes, $data['changes']);
        self::assertSame(['SKU-A' => 10, 'SKU-B' => 20], $data['sku_to_id']);
        self::assertSame(['/s/h/shared.jpg', '/a/a/a.jpg'], $data['created_files']);
        self::assertSame(['/o/l/old.jpg'], $data['removed_files']);
    }

    public function testAFailureBuildingTheBatchIsLoggedAndSwallowedAfterCommit(): void
    {
        // ImportService calls this from inside processBatch()'s try. An escaping
        // exception would take that catch's rollBack() to a transaction that no
        // longer exists and then failAll() products that are persisted.
        $productEntity = $this->createMock(ProductEntity::class);
        $productEntity->method('getLinkField')->willThrowException(new \RuntimeException('resource boom'));
        $this->logger->expects(self::once())->method('error');

        $dispatcher = new ImportEventDispatcher(
            $this->productFactory,
            $this->eventManager,
            $productEntity,
            $this->config,
            $this->logger,
            $this->mediaHydrator
        );

        // Must not throw.
        $dispatcher->dispatchAfterCommit($this->createContext(['SKU-A' => 10], existing: []));

        self::assertSame([], $this->dispatched);
    }

    public function testAFailureBuildingTheBatchStillPropagatesBeforeCommit(): void
    {
        // The mirror image: before the commit, core semantics say a failure
        // rolls the batch back, so this one must NOT be swallowed.
        $productEntity = $this->createMock(ProductEntity::class);
        $productEntity->method('getLinkField')->willThrowException(new \RuntimeException('resource boom'));

        $dispatcher = new ImportEventDispatcher(
            $this->productFactory,
            $this->eventManager,
            $productEntity,
            $this->configWith(saveAfter: true),
            $this->logger,
            $this->mediaHydrator
        );

        $this->expectException(\RuntimeException::class);
        $dispatcher->dispatchBeforeCommit($this->createContext(['SKU-A' => 10], existing: []));
    }

    public function testRemovedFilesExcludeAFileTheBatchStillHolds(): void
    {
        // SKU-A drops the shared file, SKU-B gains it. Purging it because it
        // appeared in removed_files would delete a file the same batch needs.
        $context = $this->createContext(['SKU-A' => 10, 'SKU-B' => 20], existing: []);
        $context->set(MediaProcessor::CONTEXT_CHANGES, [
            'SKU-A' => [
                'entity_id' => 10,
                'created' => [],
                'updated' => [],
                'removed' => ['/s/h/shared.jpg', '/g/o/gone.jpg'],
                'roles' => [],
                'partial' => false,
            ],
            'SKU-B' => [
                'entity_id' => 20,
                'created' => ['/s/h/shared.jpg'],
                'updated' => [],
                'removed' => [],
                'roles' => [],
                'partial' => false,
            ],
        ]);
        $context->set(MediaProcessor::CONTEXT_RETAINED_FILES, ['/s/h/shared.jpg']);

        $this->newDispatcher()->dispatchAfterCommit($context);

        $data = $this->eventsNamed(ImportEventDispatcher::EVENT_PRODUCT_MEDIA_CHANGED)[0]['data'];
        self::assertSame(['/g/o/gone.jpg'], $data['removed_files']);
        self::assertSame(['/s/h/shared.jpg'], $data['created_files']);
        // The per-SKU delta stays exact: SKU-A really did detach it.
        self::assertSame(
            ['/s/h/shared.jpg', '/g/o/gone.jpg'],
            $data['changes']['SKU-A']['removed']
        );
    }

    public function testMediaChangedEventIsNotFiredWithoutChanges(): void
    {
        $this->newDispatcher()->dispatchAfterCommit($this->createContext(['SKU-A' => 10], existing: []));

        self::assertSame([], $this->eventsNamed(ImportEventDispatcher::EVENT_PRODUCT_MEDIA_CHANGED));
    }

    private function configWith(bool $saveAfter = false, bool $hydrateMedia = false): Config&MockObject
    {
        $config = $this->createMock(Config::class);
        $config->method('isDispatchProductEvents')->willReturn(true);
        $config->method('isDispatchSaveAfter')->willReturn($saveAfter);
        $config->method('isHydrateEventMedia')->willReturn($hydrateMedia);

        return $config;
    }

    /**
     * setUp() pins getLinkField() to entity_id, so a Commerce-shaped install
     * needs its own ProductEntity mock.
     */
    private function newDispatcherWithLinkField(string $linkField): ImportEventDispatcher
    {
        $productEntity = $this->createMock(ProductEntity::class);
        $productEntity->method('getLinkField')->willReturn($linkField);

        return new ImportEventDispatcher(
            $this->productFactory,
            $this->eventManager,
            $productEntity,
            $this->configWith(hydrateMedia: true),
            $this->logger,
            $this->mediaHydrator
        );
    }

    private function newDispatcher(?Config $config = null, ?ProductMediaHydrator $hydrator = null): ImportEventDispatcher
    {
        return new ImportEventDispatcher(
            $this->productFactory,
            $this->eventManager,
            $this->productEntity,
            $config ?? $this->config,
            $this->logger,
            $hydrator ?? $this->mediaHydrator
        );
    }

    /**
     * @param array<string, int> $entityIdsBySku
     * @param string[] $existing
     */
    private function createContext(array $entityIdsBySku, array $existing): BatchContext
    {
        $products = [];
        foreach (array_keys($entityIdsBySku) as $sku) {
            $products[] = (new ProductDto())->setSku($sku);
        }
        $context = new BatchContext($products, 1);
        foreach ($entityIdsBySku as $sku => $entityId) {
            $context->setEntityId($sku, $entityId);
        }
        foreach ($existing as $sku) {
            $context->markExisting($sku);
        }

        return $context;
    }

    /**
     * @return array<int, array{name: string, data: array}>
     */
    private function eventsNamed(string $name): array
    {
        return array_values(array_filter(
            $this->dispatched,
            static fn (array $event): bool => $event['name'] === $name
        ));
    }
}
