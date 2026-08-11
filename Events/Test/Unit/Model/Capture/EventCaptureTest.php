<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Test\Unit\Model\Capture;

use Magento\Framework\DataObject;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReadyData\Events\Api\EventGateInterface;
use ReadyData\Events\Logger\Logger;
use ReadyData\Events\Model\Capture\EventCapture;
use ReadyData\Events\Model\Capture\FieldExtractor;
use ReadyData\Events\Model\Capture\GateRegistry;
use ReadyData\Events\Model\Capture\QueueBuffer;
use ReadyData\Events\Model\Capture\RuleEvaluator;
use ReadyData\Events\Model\Config;
use ReadyData\Events\Model\Subscription\Subscription;
use ReadyData\Events\Model\Subscription\SubscriptionMap;
use ReadyData\Import\Model\ImportState;

class EventCaptureTest extends TestCase
{
    private const CODE = 'observer.catalog_product_save_commit_after';

    private Config&MockObject $config;
    private SubscriptionMap&MockObject $map;
    private GateRegistry&MockObject $gates;
    private QueueBuffer&MockObject $buffer;
    private ImportState $importState;
    private EventCapture $capture;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->map = $this->createMock(SubscriptionMap::class);
        $this->gates = $this->createMock(GateRegistry::class);
        $this->buffer = $this->createMock(QueueBuffer::class);
        $this->importState = new ImportState();

        $this->config->method('isEnabled')->willReturn(true);

        $extractor = new FieldExtractor();
        $this->capture = new EventCapture(
            $this->config,
            $this->map,
            new RuleEvaluator($extractor),
            $extractor,
            $this->gates,
            $this->buffer,
            $this->importState,
            $this->createMock(Logger::class)
        );
    }

    private function subscription(array $overrides = []): Subscription
    {
        return new Subscription(
            $overrides['id'] ?? 1,
            self::CODE,
            $overrides['subscriberId'] ?? 7,
            $overrides['fields'] ?? ['sku'],
            $overrides['rules'] ?? [],
            $overrides['gateClass'] ?? null,
            $overrides['storeIds'] ?? null,
            $overrides['ignoreReadyDataOrigin'] ?? true,
            $overrides['coalesceBy'] ?? null
        );
    }

    private function event(string $sku): array
    {
        $product = new DataObject(['sku' => $sku, 'entity_id' => crc32($sku)]);

        return ['data_object' => $product, 'product' => $product];
    }

    public function testCapturesASubscribedEvent(): void
    {
        $this->map->method('forCode')->with(self::CODE)->willReturn([$this->subscription()]);
        $this->buffer->expects(self::once())->method('add')->with(self::CODE, 7, ['sku' => 'ABC-1']);

        $this->capture->capture(self::CODE, $this->event('ABC-1'));
    }

    public function testUnsubscribedCodeCostsNothing(): void
    {
        $this->map->method('forCode')->willReturn([]);
        $this->buffer->expects(self::never())->method('add');

        $this->capture->capture('observer.something_else', $this->event('ABC-1'));
    }

    /**
     * Risk 2. ReadyData writes, ReadyData_Import re-emits the core save events
     * so third-party observers still see imports, and without this guard those
     * re-emitted events feed straight back into ReadyData. An infinite loop
     * against a client's production store is the one failure here that damages
     * rather than merely fails.
     */
    public function testImportOriginEventsAreSuppressedWhileAnImportRuns(): void
    {
        $this->map->method('forCode')->willReturn([$this->subscription(['ignoreReadyDataOrigin' => true])]);
        $this->buffer->expects(self::never())->method('add');

        $this->importState->enter();
        $this->capture->capture(self::CODE, $this->event('ABC-1'));
    }

    public function testSuppressionEndsWithTheImport(): void
    {
        $this->map->method('forCode')->willReturn([$this->subscription()]);
        $this->buffer->expects(self::once())->method('add');

        $this->importState->enter();
        $this->importState->leave();
        $this->capture->capture(self::CODE, $this->event('ABC-1'));
    }

    /** Depth-counted, so nested imports do not lift the guard early. */
    public function testNestedImportsKeepTheGuardUp(): void
    {
        $this->map->method('forCode')->willReturn([$this->subscription()]);
        $this->buffer->expects(self::never())->method('add');

        $this->importState->enter();
        $this->importState->enter();
        $this->importState->leave();
        $this->capture->capture(self::CODE, $this->event('ABC-1'));
    }

    public function testASubscriptionCanOptIntoImportOriginEvents(): void
    {
        $this->map->method('forCode')->willReturn([$this->subscription(['ignoreReadyDataOrigin' => false])]);
        $this->buffer->expects(self::once())->method('add');

        $this->importState->enter();
        $this->capture->capture(self::CODE, $this->event('ABC-1'));
    }

    /**
     * Magento saves the same entity several times in one request routinely;
     * without this every order would reach ReadyData two or three times.
     */
    public function testSameEntityInOneRequestQueuesOnce(): void
    {
        $this->map->method('forCode')->willReturn([$this->subscription()]);
        $this->buffer->expects(self::once())->method('add');

        $this->capture->capture(self::CODE, $this->event('ABC-1'));
        $this->capture->capture(self::CODE, $this->event('ABC-1'));
    }

    public function testDifferentEntitiesAreNotDeduped(): void
    {
        $this->map->method('forCode')->willReturn([$this->subscription()]);
        $this->buffer->expects(self::exactly(2))->method('add');

        $this->capture->capture(self::CODE, $this->event('ABC-1'));
        $this->capture->capture(self::CODE, $this->event('ABC-2'));
    }

    /**
     * A misconfigured field list resolving to nothing must cost duplicates, not
     * silently drop every event after the first.
     */
    public function testEventsWithNoExtractableIdentityAreNotCollapsed(): void
    {
        $this->map->method('forCode')->willReturn([$this->subscription(['fields' => ['does.not.exist']])]);
        $this->buffer->expects(self::exactly(2))->method('add');

        $this->capture->capture(self::CODE, $this->event('ABC-1'));
        $this->capture->capture(self::CODE, $this->event('ABC-2'));
    }

    public function testRulesFilterBeforeAnythingIsQueued(): void
    {
        $this->map->method('forCode')->willReturn([$this->subscription([
            'rules' => [['field' => 'sku', 'operator' => 'starts_with', 'value' => 'KEEP-']],
        ])]);
        $this->buffer->expects(self::once())->method('add')->with(self::CODE, 7, ['sku' => 'KEEP-1']);

        $this->capture->capture(self::CODE, $this->event('DROP-1'));
        $this->capture->capture(self::CODE, $this->event('KEEP-1'));
    }

    public function testGateCanRefuseAnEvent(): void
    {
        $gate = $this->createMock(EventGateInterface::class);
        $gate->method('shouldEmit')->willReturn(false);
        $this->gates->method('get')->willReturn($gate);

        $this->map->method('forCode')->willReturn([$this->subscription(['gateClass' => 'Some\Gate'])]);
        $this->buffer->expects(self::never())->method('add');

        $this->capture->capture(self::CODE, $this->event('ABC-1'));
    }

    /**
     * A deleted or renamed gate class must not turn a filtered subscription
     * into an unfiltered one.
     */
    public function testUnresolvableGateDropsRatherThanEmits(): void
    {
        $this->gates->method('get')->willReturn(null);
        $this->map->method('forCode')->willReturn([$this->subscription(['gateClass' => 'Gone\Gate'])]);
        $this->buffer->expects(self::never())->method('add');

        $this->capture->capture(self::CODE, $this->event('ABC-1'));
    }

    /** Capture runs inside a merchant's save; it may never break one. */
    public function testAFailureInsideCaptureNeverEscapes(): void
    {
        $this->map->method('forCode')->willThrowException(new \RuntimeException('database is on fire'));

        $this->capture->capture(self::CODE, $this->event('ABC-1'));

        self::assertTrue(true, 'capture() swallowed the failure instead of failing the save');
    }

    public function testDisabledModuleCapturesNothing(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isEnabled')->willReturn(false);

        $extractor = new FieldExtractor();
        $capture = new EventCapture(
            $config,
            $this->map,
            new RuleEvaluator($extractor),
            $extractor,
            $this->gates,
            $this->buffer,
            $this->importState,
            $this->createMock(Logger::class)
        );

        $this->buffer->expects(self::never())->method('add');
        $capture->capture(self::CODE, $this->event('ABC-1'));
    }
}
