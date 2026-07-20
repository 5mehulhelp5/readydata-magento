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
use ReadyData\Import\Model\Processor\AttributeProcessor;
use ReadyData\Import\Model\Processor\CategoryLinkProcessor;
use ReadyData\Import\Model\ResourceModel\ProductEntity;

class ImportEventDispatcherTest extends TestCase
{
    private ProductFactory&MockObject $productFactory;
    private EventManager&MockObject $eventManager;
    private ProductEntity&MockObject $productEntity;
    private Config&MockObject $config;
    private Logger&MockObject $logger;

    /**
     * @var array<int, array{name: string, data: array}>
     */
    private array $dispatched = [];

    protected function setUp(): void
    {
        $this->dispatched = [];

        $this->productFactory = $this->createMock(ProductFactory::class);
        // Real (constructor-less) product objects so setData/getId/getSku work.
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

        $this->logger = $this->createMock(Logger::class);
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
            $byId[$event['data']['product']->getId()] = $event['data']['product']->getSku();
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
        $config = $this->createMock(Config::class);
        $config->method('isDispatchProductEvents')->willReturn(false);
        $dispatcher = new ImportEventDispatcher(
            $this->productFactory,
            $this->eventManager,
            $this->productEntity,
            $config,
            $this->logger,
            true
        );

        $context = $this->createContext(['SKU-A' => 1], existing: []);
        $dispatcher->dispatchBeforeCommit($context);
        $dispatcher->dispatchAfterCommit($context);

        self::assertSame([], $this->dispatched);
    }

    public function testSaveAfterFiresOnlyWhenToggleOn(): void
    {
        $context = $this->createContext(['SKU-A' => 1], existing: []);

        $this->newDispatcher(saveAfter: false)->dispatchBeforeCommit($context);
        self::assertSame([], $this->eventsNamed('catalog_product_save_after'));

        $this->newDispatcher(saveAfter: true)->dispatchBeforeCommit($context);
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
        $this->logger->expects(self::once())->method('error');

        $dispatcher = new ImportEventDispatcher(
            $this->productFactory,
            $eventManager,
            $this->productEntity,
            $this->config,
            $this->logger,
            false
        );

        // Must not throw.
        $dispatcher->dispatchAfterCommit($this->createContext(['SKU-A' => 1], existing: []));
    }

    private function newDispatcher(bool $saveAfter = false): ImportEventDispatcher
    {
        return new ImportEventDispatcher(
            $this->productFactory,
            $this->eventManager,
            $this->productEntity,
            $this->config,
            $this->logger,
            $saveAfter
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
