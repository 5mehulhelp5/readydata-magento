<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Test\Unit\Model;

use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Lock\LockManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReadyData\Import\Api\Data\AttributeSyncResponseInterfaceFactory;
use ReadyData\Import\Api\Data\AttributeSyncResultInterface;
use ReadyData\Import\Api\Data\AttributeSyncResultInterfaceFactory;
use ReadyData\Import\Logger\Logger;
use ReadyData\Import\Model\AttributeSyncService;
use ReadyData\Import\Model\AttributeValidator;
use ReadyData\Import\Model\Cache\AttributeMetadataCache;
use ReadyData\Import\Model\Config;
use ReadyData\Import\Model\Data\AttributeDefinition;
use ReadyData\Import\Model\Data\AttributeSetPlacement;
use ReadyData\Import\Model\Data\AttributeSyncResponse;
use ReadyData\Import\Model\Data\AttributeSyncResult;
use ReadyData\Import\Model\Amasty\AmastyAttributeWriter;
use ReadyData\Import\Model\Exception\ImportLockedException;
use ReadyData\Import\Model\ImportLocks;
use ReadyData\Import\Model\Indexer\AttributeInvalidationHandler;
use ReadyData\Import\Model\ResourceModel\AttributeDefinition as AttributeDefinitionResource;
use ReadyData\Import\Model\ResourceModel\AttributeOption;

class AttributeSyncServiceTest extends TestCase
{
    private const ENTITY_TYPE_ID = 4;
    private const DEFAULT_SET_ID = 4;

    private Config&MockObject $config;
    private LockManagerInterface&MockObject $lockManager;
    private ResourceConnection&MockObject $resourceConnection;
    private AdapterInterface&MockObject $connection;
    private EavSetup&MockObject $eavSetup;
    private AttributeMetadataCache&MockObject $metadataCache;
    private AttributeDefinitionResource&MockObject $resource;
    private AttributeOption&MockObject $attributeOption;
    private AmastyAttributeWriter&MockObject $amastyWriter;
    private AttributeInvalidationHandler&MockObject $invalidationHandler;
    private AttributeSyncService $service;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('isAutoCreateAttributes')->willReturn(true);

        $this->lockManager = $this->createMock(LockManagerInterface::class);
        $this->lockManager->method('lock')->willReturn(true);

        $this->connection = $this->createMock(AdapterInterface::class);
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->resourceConnection->method('getConnection')->willReturn($this->connection);

        $this->eavSetup = $this->createMock(EavSetup::class);
        $eavSetupFactory = $this->createMock(EavSetupFactory::class);
        $eavSetupFactory->method('create')->willReturn($this->eavSetup);

        $this->metadataCache = $this->createMock(AttributeMetadataCache::class);
        $this->metadataCache->method('getEntityTypeId')->willReturn(self::ENTITY_TYPE_ID);
        $this->metadataCache->method('resolveAttributeSetId')
            ->willReturnCallback(static fn (?string $n): ?int => $n === 'Nope' ? null : self::DEFAULT_SET_ID);

        $this->resource = $this->createMock(AttributeDefinitionResource::class);
        $this->attributeOption = $this->createMock(AttributeOption::class);
        $this->attributeOption->method('createOptions')->willReturn([]);
        $this->amastyWriter = $this->createMock(AmastyAttributeWriter::class);
        $this->invalidationHandler = $this->createMock(AttributeInvalidationHandler::class);

        $resultFactory = $this->createMock(AttributeSyncResultInterfaceFactory::class);
        $resultFactory->method('create')->willReturnCallback(static fn (): AttributeSyncResult => new AttributeSyncResult());
        $responseFactory = $this->createMock(AttributeSyncResponseInterfaceFactory::class);
        $responseFactory->method('create')
            ->willReturnCallback(static fn (): AttributeSyncResponse => new AttributeSyncResponse());

        $this->service = new AttributeSyncService(
            $this->config,
            $this->lockManager,
            $this->resourceConnection,
            $eavSetupFactory,
            new AttributeValidator(),
            $this->metadataCache,
            $this->resource,
            $this->attributeOption,
            $this->amastyWriter,
            $this->invalidationHandler,
            $responseFactory,
            $resultFactory,
            $this->createMock(Logger::class)
        );
    }

    public function testCreatesNewAttribute(): void
    {
        $this->resource->method('getExistingByCodes')->willReturn([]);
        $this->resource->method('isAttributeInSet')->willReturn(false);
        $this->eavSetup->method('getAttributeId')->willReturn(100);
        $this->eavSetup->method('getDefaultAttributeGroupId')->willReturn(7);

        $captured = null;
        $this->eavSetup->expects(self::once())->method('addAttribute')
            ->willReturnCallback(function ($entityType, $code, $data) use (&$captured): EavSetup {
                $captured = ['entityType' => $entityType, 'code' => $code, 'data' => $data];
                return $this->eavSetup;
            });
        $this->eavSetup->expects(self::once())->method('addAttributeToSet')
            ->with(self::ENTITY_TYPE_ID, self::DEFAULT_SET_ID, 7, 'color', null);
        $this->eavSetup->expects(self::never())->method('updateAttribute');
        $this->invalidationHandler->expects(self::once())->method('execute')->with(true);

        $definition = (new AttributeDefinition())
            ->setAttributeCode('color')
            ->setFrontendInput('select')
            ->setFrontendLabel('Color')
            ->setScope('global');

        $response = $this->service->sync([$definition]);

        self::assertSame(1, $response->getCreated());
        self::assertSame(AttributeSyncResultInterface::STATUS_CREATED, $response->getResults()[0]->getStatus());
        self::assertSame('catalog_product', $captured['entityType']);
        self::assertSame('int', $captured['data']['type']);
        self::assertSame('select', $captured['data']['input']);
        self::assertSame(1, $captured['data']['global']);
        self::assertSame(1, $captured['data']['user_defined']);
    }

    public function testExistingIdenticalIsUnchanged(): void
    {
        $this->resource->method('getExistingByCodes')
            ->willReturn(['color' => $this->existingRow(['frontend_label' => 'Color'])]);
        $this->resource->method('isAttributeInSet')->willReturn(true);

        $this->eavSetup->expects(self::never())->method('addAttribute');
        $this->eavSetup->expects(self::never())->method('updateAttribute');
        $this->eavSetup->expects(self::never())->method('addAttributeToSet');
        $this->invalidationHandler->expects(self::once())->method('execute')->with(false);

        $definition = (new AttributeDefinition())
            ->setAttributeCode('color')
            ->setFrontendInput('select')
            ->setFrontendLabel('Color');

        $response = $this->service->sync([$definition]);

        self::assertSame(1, $response->getUnchanged());
        self::assertSame(AttributeSyncResultInterface::STATUS_UNCHANGED, $response->getResults()[0]->getStatus());
    }

    public function testExistingSafeDiffIsUpdated(): void
    {
        $this->resource->method('getExistingByCodes')
            ->willReturn(['color' => $this->existingRow(['frontend_label' => 'Old', 'is_searchable' => 0])]);
        $this->resource->method('isAttributeInSet')->willReturn(true);

        $captured = null;
        $this->eavSetup->expects(self::once())->method('updateAttribute')
            ->willReturnCallback(function ($entityType, $id, $fields) use (&$captured): EavSetup {
                $captured = ['id' => $id, 'fields' => $fields];
                return $this->eavSetup;
            });
        $this->invalidationHandler->expects(self::once())->method('execute')->with(true);

        $definition = (new AttributeDefinition())
            ->setAttributeCode('color')
            ->setFrontendInput('select')
            ->setFrontendLabel('New')
            ->setIsSearchable(1);

        $response = $this->service->sync([$definition]);

        self::assertSame(1, $response->getUpdated());
        self::assertSame(100, $captured['id']);
        self::assertSame('New', $captured['fields']['frontend_label']);
        self::assertSame(1, $captured['fields']['is_searchable']);
    }

    public function testStructuralDiffIsSkippedAndReported(): void
    {
        $this->resource->method('getExistingByCodes')->willReturn([
            'color' => $this->existingRow(['backend_type' => 'varchar', 'frontend_input' => 'text']),
        ]);

        $this->eavSetup->expects(self::never())->method('addAttribute');
        $this->eavSetup->expects(self::never())->method('updateAttribute');
        $this->invalidationHandler->expects(self::once())->method('execute')->with(false);

        // select -> backend int, so backend_type and frontend_input both differ.
        $definition = (new AttributeDefinition())->setAttributeCode('color')->setFrontendInput('select');

        $response = $this->service->sync([$definition]);

        $result = $response->getResults()[0];
        self::assertSame(1, $response->getSkipped());
        self::assertSame(AttributeSyncResultInterface::STATUS_SKIPPED, $result->getStatus());
        self::assertSame(
            AttributeSyncResultInterface::REASON_STRUCTURAL_CHANGE_REQUIRED,
            $result->getReason()
        );
        self::assertStringContainsString('have {', $result->getMessages()[0]);
        self::assertStringContainsString('requested {', $result->getMessages()[0]);
    }

    public function testDuplicateKeyOnCreateReReadsAndUpdates(): void
    {
        // First (bulk) read: not present. Second (re-check after failed create): present.
        $this->resource->method('getExistingByCodes')->willReturnOnConsecutiveCalls(
            [],
            ['color' => $this->existingRow(['frontend_label' => 'Old'])]
        );
        $this->resource->method('isAttributeInSet')->willReturn(true);

        $this->eavSetup->method('addAttribute')->willThrowException(new \RuntimeException('Duplicate entry'));
        $this->eavSetup->expects(self::once())->method('updateAttribute');
        $this->invalidationHandler->expects(self::once())->method('execute')->with(true);

        $definition = (new AttributeDefinition())
            ->setAttributeCode('color')
            ->setFrontendInput('select')
            ->setFrontendLabel('New');

        $response = $this->service->sync([$definition]);

        self::assertSame(0, $response->getFailed());
        self::assertSame(1, $response->getUpdated());
    }

    public function testUpdateFailureIsReportedPerAttributeWithoutAbortingBatch(): void
    {
        $this->resource->method('getExistingByCodes')
            ->willReturn(['color' => $this->existingRow(['frontend_label' => 'Old'])]);
        $this->resource->method('isAttributeInSet')->willReturn(true);

        $this->eavSetup->method('updateAttribute')
            ->willThrowException(new \RuntimeException('DB gone'));
        // The failed attribute's partial writes are rolled back...
        $this->connection->expects(self::once())->method('rollBack');
        $this->connection->expects(self::never())->method('commit');
        // ...and the batch must still finish and invalidation must still run.
        $this->invalidationHandler->expects(self::once())->method('execute')->with(false);

        $definition = (new AttributeDefinition())
            ->setAttributeCode('color')
            ->setFrontendInput('select')
            ->setFrontendLabel('New');

        $response = $this->service->sync([$definition]);

        $result = $response->getResults()[0];
        self::assertSame(1, $response->getFailed());
        self::assertSame(AttributeSyncResultInterface::STATUS_ERROR, $result->getStatus());
        self::assertStringContainsString('Sync failed', $result->getMessages()[0]);
    }

    /**
     * This endpoint seeds options too, so it takes the SAME option lock the
     * product import takes. With one lock per endpoint the two could both insert
     * the same new label — the race this module used to document rather than fix.
     */
    public function testTheOptionLockIsSharedWithTheProductImport(): void
    {
        $this->resource->method('getExistingByCodes')->willReturn([]);
        $this->eavSetup->method('getAttributeId')->willReturn(100);

        $taken = [];
        $released = [];
        $this->lockManager = $this->createMock(LockManagerInterface::class);
        $this->lockManager->method('lock')->willReturnCallback(
            static function (string $name) use (&$taken): bool {
                $taken[] = $name;

                return true;
            }
        );
        $this->lockManager->method('unlock')->willReturnCallback(
            static function (string $name) use (&$released): bool {
                $released[] = $name;

                return true;
            }
        );

        $this->serviceWithConfig($this->config)->sync([
            (new AttributeDefinition())->setAttributeCode('color')->setFrontendInput('select'),
        ]);

        // In the canonical order, and back in reverse: two requests taking
        // overlapping sets in opposite orders would wait on each other.
        self::assertSame([ImportLocks::ATTRIBUTE_SYNC, ImportLocks::ATTRIBUTE_OPTIONS], $taken);
        self::assertSame([ImportLocks::ATTRIBUTE_OPTIONS, ImportLocks::ATTRIBUTE_SYNC], $released);
    }

    /**
     * The wording names what actually blocked: the shared option lock means a
     * product import is the other candidate, and that is the message the caller
     * already backs off on.
     */
    public function testAHeldOptionLockIsReportedAsAnImportInProgress(): void
    {
        $this->lockManager = $this->createMock(LockManagerInterface::class);
        $this->lockManager->method('lock')->willReturnCallback(
            static fn (string $name): bool => $name !== ImportLocks::ATTRIBUTE_OPTIONS
        );
        // The definition lock it did get has to go back, or the retry it just
        // asked for would block on this request.
        $this->lockManager->expects(self::once())->method('unlock')->with(ImportLocks::ATTRIBUTE_SYNC);

        $this->expectExceptionMessage('Another import is already running. Try again later.');

        $this->serviceWithConfig($this->config)->sync([
            (new AttributeDefinition())->setAttributeCode('color')->setFrontendInput('select'),
        ]);
    }

    /**
     * This endpoint's own wording never matched the string callers were looking
     * for, so before there was a status code for it, its rejections were never
     * retried at all. The type is what fixes that — the message stays as it was.
     */
    public function testItsOwnLockRejectionIsAlsoRetryable(): void
    {
        $this->lockManager = $this->createMock(LockManagerInterface::class);
        $this->lockManager->method('lock')->willReturn(false);

        try {
            $this->serviceWithConfig($this->config)->sync([
                (new AttributeDefinition())->setAttributeCode('color')->setFrontendInput('select'),
            ]);
            self::fail('the rejection should have been thrown');
        } catch (ImportLockedException $e) {
            self::assertSame('Another attribute sync is already running. Try again later.', $e->getMessage());
            self::assertSame(429, $e->getHttpCode());
            self::assertSame(ImportLockedException::REASON, $e->getDetails()['reason']);
            self::assertSame([ImportLocks::ATTRIBUTE_SYNC], $e->getDetails()['locks']);
        }
    }

    public function testDisabledNoOps(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isAutoCreateAttributes')->willReturn(false);
        $service = $this->serviceWithConfig($config);

        $this->lockManager->expects(self::never())->method('lock');
        $this->invalidationHandler->expects(self::never())->method('execute');

        $definition = (new AttributeDefinition())->setAttributeCode('color')->setFrontendInput('select');
        $response = $service->sync([$definition]);

        $result = $response->getResults()[0];
        self::assertSame(1, $response->getSkipped());
        self::assertSame(AttributeSyncResultInterface::REASON_DISABLED, $result->getReason());
    }

    public function testUnknownSetPlacementWarnsButStillCreates(): void
    {
        $this->resource->method('getExistingByCodes')->willReturn([]);
        $this->eavSetup->method('getAttributeId')->willReturn(100);

        $this->eavSetup->expects(self::once())->method('addAttribute');
        $this->eavSetup->expects(self::never())->method('addAttributeToSet');

        $definition = (new AttributeDefinition())
            ->setAttributeCode('color')
            ->setFrontendInput('select')
            ->setPlacements([(new AttributeSetPlacement())->setSet('Nope')]);

        $response = $this->service->sync([$definition]);

        $result = $response->getResults()[0];
        self::assertSame(AttributeSyncResultInterface::STATUS_CREATED, $result->getStatus());
        self::assertStringContainsString('not found', $result->getMessages()[0]);
    }

    public function testDedupeLastWins(): void
    {
        $this->resource->expects(self::once())->method('getExistingByCodes')
            ->with(self::ENTITY_TYPE_ID, ['color'])
            ->willReturn(['color' => $this->existingRow(['frontend_label' => 'Kept'])]);
        $this->resource->method('isAttributeInSet')->willReturn(true);

        $first = (new AttributeDefinition())->setAttributeCode('color')->setFrontendInput('select')->setFrontendLabel('First');
        $second = (new AttributeDefinition())->setAttributeCode('color')->setFrontendInput('select')->setFrontendLabel('Kept');

        $response = $this->service->sync([$first, $second]);

        self::assertSame(2, $response->getReceived());
        self::assertCount(1, $response->getResults());
        self::assertSame(AttributeSyncResultInterface::STATUS_UNCHANGED, $response->getResults()[0]->getStatus());
    }

    /**
     * A fully-populated existing-attribute column map with sane defaults.
     *
     * @param array<string, int|string|null> $overrides
     * @return array<string, int|string|null>
     */
    private function existingRow(array $overrides = []): array
    {
        return array_merge([
            'attribute_id' => 100,
            'backend_type' => 'int',
            'frontend_input' => 'select',
            'frontend_label' => 'Color',
            'default_value' => null,
            'note' => null,
            'is_global' => 1,
            'is_required' => 0,
            'is_unique' => 0,
            'is_searchable' => 0,
            'is_filterable' => 0,
            'is_filterable_in_search' => 0,
            'is_comparable' => 0,
            'is_visible_on_front' => 0,
            'is_html_allowed_on_front' => 0,
            'is_wysiwyg_enabled' => 0,
            'used_in_product_listing' => 0,
            'used_for_sort_by' => 0,
            'is_visible_in_grid' => 0,
            'is_filterable_in_grid' => 0,
            'is_used_in_grid' => 0,
        ], $overrides);
    }

    private function serviceWithConfig(Config $config): AttributeSyncService
    {
        $eavSetupFactory = $this->createMock(EavSetupFactory::class);
        $eavSetupFactory->method('create')->willReturn($this->eavSetup);
        $resultFactory = $this->createMock(AttributeSyncResultInterfaceFactory::class);
        $resultFactory->method('create')->willReturnCallback(static fn (): AttributeSyncResult => new AttributeSyncResult());
        $responseFactory = $this->createMock(AttributeSyncResponseInterfaceFactory::class);
        $responseFactory->method('create')
            ->willReturnCallback(static fn (): AttributeSyncResponse => new AttributeSyncResponse());

        return new AttributeSyncService(
            $config,
            $this->lockManager,
            $this->resourceConnection,
            $eavSetupFactory,
            new AttributeValidator(),
            $this->metadataCache,
            $this->resource,
            $this->attributeOption,
            $this->amastyWriter,
            $this->invalidationHandler,
            $responseFactory,
            $resultFactory,
            $this->createMock(Logger::class)
        );
    }
}
