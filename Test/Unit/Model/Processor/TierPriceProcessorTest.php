<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Test\Unit\Model\Processor;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReadyData\Import\Api\Data\TierPriceInterface;
use ReadyData\Import\Logger\Logger;
use ReadyData\Import\Model\BatchContext;
use ReadyData\Import\Model\Cache\AttributeMetadataCache;
use ReadyData\Import\Model\Cache\CustomerGroupMap;
use ReadyData\Import\Model\Cache\StoreWebsiteMap;
use ReadyData\Import\Model\Data\Product;
use ReadyData\Import\Model\Data\TierPrice as TierPriceData;
use ReadyData\Import\Model\Processor\EntityProcessor;
use ReadyData\Import\Model\Processor\TierPriceProcessor;
use ReadyData\Import\Model\ResourceModel\TierPrice;

class TierPriceProcessorTest extends TestCase
{
    /** apply_to as a stock install leaves it for the tier_price attribute. */
    private const APPLY_TO = 'simple,virtual,bundle,downloadable';

    private const GROUPS = ['not logged in' => 0, 'general' => 1, 'wholesale' => 2, 'retailer' => 3];

    private TierPrice&MockObject $tierPrice;
    private CustomerGroupMap&MockObject $customerGroupMap;
    private StoreWebsiteMap&MockObject $storeWebsiteMap;
    private AttributeMetadataCache&MockObject $attributeCache;
    private Logger&MockObject $logger;
    private TierPriceProcessor $processor;

    /**
     * Per-test knobs. They are properties rather than per-test willReturn()
     * calls because PHPUnit keeps the FIRST matcher registered for a method, so
     * re-stubbing something setUp() already stubbed silently does nothing.
     */
    private bool $priceScopeGlobal = true;
    private ?string $applyTo = self::APPLY_TO;

    protected function setUp(): void
    {
        $this->tierPrice = $this->createMock(TierPrice::class);
        $this->customerGroupMap = $this->createMock(CustomerGroupMap::class);
        $this->storeWebsiteMap = $this->createMock(StoreWebsiteMap::class);
        $this->attributeCache = $this->createMock(AttributeMetadataCache::class);
        $this->logger = $this->createMock(Logger::class);

        // Mirrors the real resolver: digits are IDs, codes match case-insensitively.
        $this->customerGroupMap->method('getGroupId')->willReturnCallback(
            static function (string $reference): ?int {
                $reference = trim($reference);
                if (ctype_digit($reference)) {
                    return in_array((int)$reference, self::GROUPS, true) ? (int)$reference : null;
                }

                return self::GROUPS[mb_strtolower($reference)] ?? null;
            }
        );
        $this->storeWebsiteMap->method('getWebsiteId')->willReturnCallback(
            static fn (string $code): ?int => ['base' => 1, 'second' => 2][$code] ?? null
        );
        $this->tierPrice->method('isPriceScopeGlobal')->willReturnCallback(
            fn (): bool => $this->priceScopeGlobal
        );
        $this->attributeCache->method('get')->willReturnCallback(
            fn (): ?array => $this->applyTo === null ? null : [
                'attribute_id' => 71,
                'attribute_code' => TierPriceProcessor::TIER_PRICE_CODE,
                'backend_type' => 'decimal',
                'frontend_input' => 'text',
                'frontend_label' => 'Tier Price',
                'is_global' => 2,
                'is_required' => 0,
                'apply_to' => $this->applyTo,
            ]
        );

        $this->processor = new TierPriceProcessor(
            $this->tierPrice,
            $this->customerGroupMap,
            $this->storeWebsiteMap,
            $this->attributeCache,
            $this->logger
        );
    }

    // ---------------------------------------------------------------- shape

    public function testWritesAllGroupsAndSpecificGroupRows(): void
    {
        $context = $this->contextFor('P1', [
            $this->entry('all groups', 1.0, price: 90.0),
            $this->entry('Wholesale', 10.0, price: 85.0),
        ]);
        $this->tierPrice->method('getPrices')->willReturn([]);

        $this->tierPrice->expects(self::once())->method('deletePrices')->with([]);
        $this->tierPrice->expects(self::once())->method('savePrices')->with([
            $this->row(allGroups: 1, groupId: 0, qty: '1.0000', value: '90.000000'),
            $this->row(allGroups: 0, groupId: 2, qty: '10.0000', value: '85.000000'),
        ]);

        $this->processor->process($context);
        self::assertSame([], $context->getMessages('P1'));
    }

    /**
     * "all groups" is Magento's own spelling; "all" is the module's shorthand.
     * Neither may reach the customer group lookup.
     */
    public function testBothAllGroupsSpellingsAreAccepted(): void
    {
        $context = $this->contextFor('P1', [
            $this->entry('ALL GROUPS', 1.0, price: 90.0),
            $this->entry('all', 5.0, price: 80.0),
        ]);
        $this->tierPrice->method('getPrices')->willReturn([]);

        $this->tierPrice->expects(self::once())->method('savePrices')->with([
            $this->row(allGroups: 1, groupId: 0, qty: '1.0000', value: '90.000000'),
            $this->row(allGroups: 1, groupId: 0, qty: '5.0000', value: '80.000000'),
        ]);

        $this->processor->process($context);
        self::assertSame([], $context->getMessages('P1'));
    }

    public function testResolvesCustomerGroupCodeCaseInsensitively(): void
    {
        $context = $this->contextFor('P1', [$this->entry('wHoLeSaLe', 1.0, price: 5.0)]);
        $this->tierPrice->method('getPrices')->willReturn([]);

        $this->tierPrice->expects(self::once())->method('savePrices')->with([
            $this->row(allGroups: 0, groupId: 2, qty: '1.0000', value: '5.000000'),
        ]);

        $this->processor->process($context);
    }

    public function testResolvesCustomerGroupByNumericId(): void
    {
        $context = $this->contextFor('P1', [$this->entry('3', 1.0, price: 5.0)]);
        $this->tierPrice->method('getPrices')->willReturn([]);

        $this->tierPrice->expects(self::once())->method('savePrices')->with([
            $this->row(allGroups: 0, groupId: 3, qty: '1.0000', value: '5.000000'),
        ]);

        $this->processor->process($context);
    }

    /**
     * A percentage row stores value = 0 (the column is NOT NULL) and the
     * discount in percentage_value; a fixed row leaves percentage_value NULL.
     */
    public function testPercentageAndFixedRowsUseCoresStoredShape(): void
    {
        $context = $this->contextFor('P1', [
            $this->entry('Wholesale', 10.0, percentage: 15.0),
            $this->entry('General', 10.0, price: 12.5),
        ]);
        $this->tierPrice->method('getPrices')->willReturn([]);

        $this->tierPrice->expects(self::once())->method('savePrices')->with([
            $this->row(allGroups: 0, groupId: 2, qty: '10.0000', value: '0.000000', percentage: '15.00'),
            $this->row(allGroups: 0, groupId: 1, qty: '10.0000', value: '12.500000'),
        ]);

        $this->processor->process($context);
    }

    /**
     * The all_groups trap: an "all groups" row and a "NOT LOGGED IN" row both
     * store customer_group_id = 0 and must not be conflated.
     */
    public function testAllGroupsRowAndNotLoggedInRowCoexist(): void
    {
        $context = $this->contextFor('P1', [
            $this->entry('all groups', 1.0, price: 90.0),
            $this->entry('NOT LOGGED IN', 1.0, price: 95.0),
        ]);
        $this->tierPrice->method('getPrices')->willReturn([]);

        $this->tierPrice->expects(self::once())->method('savePrices')->with([
            $this->row(allGroups: 1, groupId: 0, qty: '1.0000', value: '90.000000'),
            $this->row(allGroups: 0, groupId: 0, qty: '1.0000', value: '95.000000'),
        ]);

        $this->processor->process($context);
        self::assertSame([], $context->getMessages('P1'));
    }

    public function testOmittedWebsiteAndTheAllWebsitesSynonymBothMeanAllWebsites(): void
    {
        $context = $this->contextFor('P1', [
            $this->entry('Wholesale', 1.0, price: 5.0),
            $this->entry('General', 1.0, price: 6.0, website: 'all websites'),
        ]);
        $this->tierPrice->method('getPrices')->willReturn([]);

        $this->tierPrice->expects(self::once())->method('savePrices')->with([
            $this->row(allGroups: 0, groupId: 2, qty: '1.0000', value: '5.000000', websiteId: 0),
            $this->row(allGroups: 0, groupId: 1, qty: '1.0000', value: '6.000000', websiteId: 0),
        ]);

        $this->processor->process($context);
        self::assertSame([], $context->getMessages('P1'));
    }

    public function testResolvesWebsiteCodeUnderWebsitePriceScope(): void
    {
        $this->priceScopeGlobal = false;
        $context = $this->contextFor('P1', [$this->entry('Wholesale', 1.0, price: 5.0, website: 'second')]);
        $this->tierPrice->method('getPrices')->willReturn([]);

        $this->tierPrice->expects(self::once())->method('savePrices')->with([
            $this->row(allGroups: 0, groupId: 2, qty: '1.0000', value: '5.000000', websiteId: 2),
        ]);

        $this->processor->process($context);
        self::assertSame([], $context->getMessages('P1'));
    }

    public function testRowsCarryTheLinkFieldNotTheEntityId(): void
    {
        $context = $this->contextFor('P1', [$this->entry('Wholesale', 1.0, price: 5.0)], entityId: 42, linkId: 777);
        $this->tierPrice->method('getPrices')->willReturn([]);

        $this->tierPrice->expects(self::once())->method('getPrices')->with([777]);
        $this->tierPrice->expects(self::once())->method('savePrices')->with([
            $this->row(allGroups: 0, groupId: 2, qty: '1.0000', value: '5.000000', linkId: 777),
        ]);

        $this->processor->process($context);
    }

    // ------------------------------------------------ replace & idempotence

    public function testReplaceRemovesRowsNotInPayload(): void
    {
        $context = $this->contextFor('P1', [$this->entry('Wholesale', 1.0, price: 5.0)]);
        $this->tierPrice->method('getPrices')->willReturn([
            10 => [
                TierPrice::buildKey(0, 0, 2, '1.0000') => $this->currentRow(101, '5.000000'),
                TierPrice::buildKey(0, 0, 1, '1.0000') => $this->currentRow(102, '6.000000'),
            ],
        ]);

        $this->tierPrice->expects(self::once())->method('deletePrices')->with([102]);
        // The kept row is unchanged, so nothing is written for it either.
        $this->tierPrice->expects(self::once())->method('savePrices')->with([]);

        $this->processor->process($context);
    }

    public function testEmptyArrayRemovesEveryRow(): void
    {
        $context = $this->contextFor('P1', []);
        $this->tierPrice->method('getPrices')->willReturn([
            10 => [
                TierPrice::buildKey(0, 0, 2, '1.0000') => $this->currentRow(101, '5.000000'),
                TierPrice::buildKey(0, 1, 0, '5.0000') => $this->currentRow(102, '6.000000'),
            ],
        ]);

        $this->tierPrice->expects(self::once())->method('deletePrices')->with([101, 102]);
        $this->tierPrice->expects(self::once())->method('savePrices')->with([]);

        $this->processor->process($context);
    }

    public function testNullTierPricesTouchesNothing(): void
    {
        $product = (new Product())->setSku('P1');
        $context = new BatchContext([$product], 0);
        $context->setEntityId('P1', 10);
        $context->set(EntityProcessor::CONTEXT_LINK_IDS, ['P1' => 10]);

        $this->expectNoTierPriceWork();

        $this->processor->process($context);
    }

    /**
     * The point of scaling both sides of the diff: a re-import of an unchanged
     * payload must issue no SQL at all.
     */
    public function testUnchangedSetPerformsNoWrites(): void
    {
        $context = $this->contextFor('P1', [
            $this->entry('Wholesale', 10.0, price: 19.99),
            $this->entry('all groups', 1.0, percentage: 10.0),
        ]);
        $this->tierPrice->method('getPrices')->willReturn([
            10 => [
                TierPrice::buildKey(0, 0, 2, '10.0000') => $this->currentRow(101, '19.990000'),
                TierPrice::buildKey(0, 1, 0, '1.0000') => $this->currentRow(102, '0.000000', '10.00'),
            ],
        ]);

        $this->tierPrice->expects(self::once())->method('deletePrices')->with([]);
        $this->tierPrice->expects(self::once())->method('savePrices')->with([]);

        $this->processor->process($context);
        self::assertSame([], $context->getMessages('P1'));
    }

    /**
     * A price change upserts the same tuple, so the row keeps its value_id —
     * the unique key does the matching and nothing is deleted.
     */
    public function testChangedValueUpsertsTheSameTupleWithoutDeleting(): void
    {
        $context = $this->contextFor('P1', [$this->entry('Wholesale', 10.0, price: 17.5)]);
        $this->tierPrice->method('getPrices')->willReturn([
            10 => [TierPrice::buildKey(0, 0, 2, '10.0000') => $this->currentRow(101, '19.990000')],
        ]);

        $this->tierPrice->expects(self::once())->method('deletePrices')->with([]);
        $this->tierPrice->expects(self::once())->method('savePrices')->with([
            $this->row(allGroups: 0, groupId: 2, qty: '10.0000', value: '17.500000'),
        ]);

        $this->processor->process($context);
    }

    /** Switching a row from a fixed price to a percentage is an update, not a churn. */
    public function testSwitchingBetweenFixedAndPercentageUpdatesInPlace(): void
    {
        $context = $this->contextFor('P1', [$this->entry('Wholesale', 10.0, percentage: 20.0)]);
        $this->tierPrice->method('getPrices')->willReturn([
            10 => [TierPrice::buildKey(0, 0, 2, '10.0000') => $this->currentRow(101, '19.990000')],
        ]);

        $this->tierPrice->expects(self::once())->method('deletePrices')->with([]);
        $this->tierPrice->expects(self::once())->method('savePrices')->with([
            $this->row(allGroups: 0, groupId: 2, qty: '10.0000', value: '0.000000', percentage: '20.00'),
        ]);

        $this->processor->process($context);
    }

    // ------------------------------------------------------- guards & valve

    public function testUnknownCustomerGroupWarnsAndWithholdsRemovals(): void
    {
        $context = $this->contextFor('P1', [
            $this->entry('Nope', 1.0, price: 5.0),
            $this->entry('Wholesale', 5.0, price: 4.0),
        ]);
        $this->tierPrice->method('getPrices')->willReturn([
            10 => [TierPrice::buildKey(0, 0, 1, '1.0000') => $this->currentRow(101, '6.000000')],
        ]);

        // Additive: the good row is still inserted, the obsolete one survives.
        $this->tierPrice->expects(self::once())->method('deletePrices')->with([]);
        $this->tierPrice->expects(self::once())->method('savePrices')->with([
            $this->row(allGroups: 0, groupId: 2, qty: '5.0000', value: '4.000000'),
        ]);

        $this->processor->process($context);

        self::assertSame(
            [
                'Unknown customer group "Nope"; tier price skipped.',
                'Tier prices applied additively: some entries could not be applied,'
                . ' so no existing tier prices were removed.',
            ],
            $context->getMessages('P1')
        );
    }

    public function testEmptyCustomerGroupWarnsAndWithholdsRemovals(): void
    {
        $this->assertValveTripped(
            [$this->entry('  ', 1.0, price: 5.0)],
            'Tier price entry without a customer group skipped.'
        );
    }

    public function testUnknownWebsiteCodeWarnsAndWithholdsRemovals(): void
    {
        $this->priceScopeGlobal = false;
        $this->assertValveTripped(
            [$this->entry('Wholesale', 1.0, price: 5.0, website: 'nope')],
            'Unknown website code "nope"; tier price skipped.'
        );
    }

    /**
     * Never silently widened to All Websites: quietly applying one website's
     * price everywhere is a pricing error, not a normalisation.
     */
    public function testWebsiteUnderGlobalPriceScopeIsSkippedAndWithholdsRemovals(): void
    {
        $this->assertValveTripped(
            [$this->entry('Wholesale', 1.0, price: 5.0, website: 'base')],
            'Catalog Price Scope is global, so the tier price for website "base" was skipped;'
            . ' omit "website" to price for all websites.'
        );
    }

    /**
     * @dataProvider nonPositiveQtyProvider
     */
    public function testNonPositiveQtyIsSkipped(float $qty, string $rendered): void
    {
        $this->assertValveTripped(
            [$this->entry('Wholesale', $qty, price: 5.0)],
            sprintf('Tier price quantity "%s" must be greater than zero; entry skipped.', $rendered)
        );
    }

    /**
     * @return array<string, array{float, string}>
     */
    public static function nonPositiveQtyProvider(): array
    {
        return ['zero' => [0.0, '0'], 'negative' => [-1.0, '-1']];
    }

    public function testNegativePriceIsSkipped(): void
    {
        $this->assertValveTripped(
            [$this->entry('Wholesale', 1.0, price: -1.0)],
            'Tier price "-1" cannot be negative; entry skipped.'
        );
    }

    /**
     * Core's validator accepts price >= 0 and the live price indexer branches on
     * percentage_value, not on value, so a zero fixed price indexes as 0.
     */
    public function testZeroPriceIsAccepted(): void
    {
        $context = $this->contextFor('P1', [$this->entry('Wholesale', 1.0, price: 0.0)]);
        $this->tierPrice->method('getPrices')->willReturn([]);

        $this->tierPrice->expects(self::once())->method('savePrices')->with([
            $this->row(allGroups: 0, groupId: 2, qty: '1.0000', value: '0.000000'),
        ]);

        $this->processor->process($context);
        self::assertSame([], $context->getMessages('P1'));
    }

    /**
     * @dataProvider outOfRangePercentageProvider
     */
    public function testPercentageOutOfRangeIsSkipped(float $percentage, string $rendered): void
    {
        $this->assertValveTripped(
            [$this->entry('Wholesale', 1.0, percentage: $percentage)],
            sprintf(
                'Tier price percentage_discount "%s" must be between 0 and 100; entry skipped.',
                $rendered
            )
        );
    }

    /**
     * @return array<string, array{float, string}>
     */
    public static function outOfRangePercentageProvider(): array
    {
        return ['below zero' => [-1.0, '-1'], 'above hundred' => [100.01, '100.01']];
    }

    public function testPercentageOfOneHundredIsAccepted(): void
    {
        $context = $this->contextFor('P1', [$this->entry('Wholesale', 1.0, percentage: 100.0)]);
        $this->tierPrice->method('getPrices')->willReturn([]);

        $this->tierPrice->expects(self::once())->method('savePrices')->with([
            $this->row(allGroups: 0, groupId: 2, qty: '1.0000', value: '0.000000', percentage: '100.00'),
        ]);

        $this->processor->process($context);
        self::assertSame([], $context->getMessages('P1'));
    }

    public function testBothPriceAndPercentageIsSkipped(): void
    {
        $this->assertValveTripped(
            [$this->entry('Wholesale', 1.0, price: 5.0, percentage: 10.0)],
            'Tier price entry carries both "price" and "percentage_discount"; entry skipped.'
        );
    }

    public function testNeitherPriceNorPercentageIsSkipped(): void
    {
        $this->assertValveTripped(
            [$this->entry('Wholesale', 1.0)],
            'Tier price entry carries neither "price" nor "percentage_discount"; entry skipped.'
        );
    }

    public function testAbsolutePriceOnBundleIsSkipped(): void
    {
        $this->assertValveTripped(
            [$this->entry('Wholesale', 1.0, price: 5.0)],
            '"bundle" products accept "percentage_discount" tier prices only;'
            . ' the absolute price was skipped.',
            typeId: 'bundle'
        );
    }

    public function testPercentageOnBundleIsAccepted(): void
    {
        $context = $this->contextFor(
            'P1',
            [$this->entry('Wholesale', 1.0, percentage: 20.0)],
            typeId: 'bundle'
        );
        $this->tierPrice->method('getPrices')->willReturn([]);

        $this->tierPrice->expects(self::once())->method('savePrices')->with([
            $this->row(allGroups: 0, groupId: 2, qty: '1.0000', value: '0.000000', percentage: '20.00'),
        ]);

        $this->processor->process($context);
        self::assertSame([], $context->getMessages('P1'));
    }

    /**
     * The exception to the valve rule: one of the two conflicting rows IS
     * written, so the set stays complete and removals still apply.
     */
    public function testDuplicateTupleKeepsFirstAndDoesNotWithholdRemovals(): void
    {
        $context = $this->contextFor('P1', [
            $this->entry('Wholesale', 1.0, price: 5.0),
            $this->entry('wholesale', 1.0, price: 9.0),
        ]);
        $this->tierPrice->method('getPrices')->willReturn([
            10 => [TierPrice::buildKey(0, 1, 0, '1.0000') => $this->currentRow(101, '6.000000')],
        ]);

        // The obsolete all-groups row IS removed despite the duplicate warning.
        $this->tierPrice->expects(self::once())->method('deletePrices')->with([101]);
        $this->tierPrice->expects(self::once())->method('savePrices')->with([
            $this->row(allGroups: 0, groupId: 2, qty: '1.0000', value: '5.000000'),
        ]);

        $this->processor->process($context);

        self::assertSame(
            [
                'Duplicate tier price for customer group "wholesale", quantity 1.0000'
                . ' and website All Websites; the first entry was kept.',
            ],
            $context->getMessages('P1')
        );
    }

    /**
     * Core discards tier_price for a non-applicable type and the admin never
     * shows the field, so existing rows are left inert rather than destroyed.
     */
    public function testInapplicableProductTypeSkipsBlockEntirely(): void
    {
        $context = $this->contextFor(
            'P1',
            [$this->entry('Wholesale', 1.0, price: 5.0)],
            typeId: 'configurable'
        );

        $this->expectNoTierPriceWork();

        $this->processor->process($context);

        self::assertSame(
            [
                'Tier prices do not apply to "configurable" products and were skipped;'
                . ' existing tier prices were left unchanged.',
            ],
            $context->getMessages('P1')
        );
    }

    public function testEmptyApplyToAllowsEveryType(): void
    {
        $this->applyTo = '';
        $context = $this->contextFor(
            'P1',
            [$this->entry('Wholesale', 1.0, price: 5.0)],
            typeId: 'configurable'
        );
        $this->tierPrice->method('getPrices')->willReturn([]);

        $this->tierPrice->expects(self::once())->method('savePrices')->with([
            $this->row(allGroups: 0, groupId: 2, qty: '1.0000', value: '5.000000'),
        ]);

        $this->processor->process($context);
        self::assertSame([], $context->getMessages('P1'));
    }

    public function testMissingTierPriceAttributeWarnsPerProductAndSkips(): void
    {
        $this->applyTo = null;
        $context = $this->contextFor('P1', [$this->entry('Wholesale', 1.0, price: 5.0)]);

        $this->logger->expects(self::once())->method('error');
        $this->expectNoTierPriceWork();

        $this->processor->process($context);

        self::assertSame(
            ['The tier_price attribute is missing; tier prices were not imported.'],
            $context->getMessages('P1')
        );
    }

    // ---------------------------------------------------- pipeline hygiene

    public function testProductWithoutResolvedIdsIsSkipped(): void
    {
        $product = (new Product())->setSku('P1');
        $product->setTierPrices([$this->entry('Wholesale', 1.0, price: 5.0)]);
        // No entity ID and no link ID: EntityProcessor never resolved it.
        $context = new BatchContext([$product], 0);

        $this->expectNoTierPriceWork();

        $this->processor->process($context);
    }

    public function testFailedProductIsIgnored(): void
    {
        $context = $this->contextFor('P1', [$this->entry('Wholesale', 1.0, price: 5.0)]);
        $context->fail('P1', 'earlier failure');

        $this->expectNoTierPriceWork();

        $this->processor->process($context);
    }

    public function testNumericSkusSurviveArrayKeyCoercion(): void
    {
        $context = $this->contextFor('123', [$this->entry('Nope', 1.0, price: 5.0)]);
        $this->tierPrice->method('getPrices')->willReturn([]);

        $this->processor->process($context);

        self::assertSame(
            ['Unknown customer group "Nope"; tier price skipped.'],
            $context->getMessages('123')
        );
    }

    public function testRunsAfterConfigurablesAndBeforeUrlRewrites(): void
    {
        self::assertSame(740, $this->processor->getSortOrder());
        self::assertTrue($this->processor->isEnabled());
    }

    // ------------------------------------------------------------- helpers

    /**
     * @param TierPriceInterface[] $entries
     */
    private function assertValveTripped(array $entries, string $message, string $typeId = 'simple'): void
    {
        $context = $this->contextFor('P1', $entries, typeId: $typeId);
        $this->tierPrice->method('getPrices')->willReturn([
            10 => [TierPrice::buildKey(0, 0, 1, '1.0000') => $this->currentRow(101, '6.000000')],
        ]);

        $this->tierPrice->expects(self::once())->method('deletePrices')->with([]);

        $this->processor->process($context);

        self::assertSame(
            [
                $message,
                'Tier prices applied additively: some entries could not be applied,'
                . ' so no existing tier prices were removed.',
            ],
            $context->getMessages('P1')
        );
    }

    private function expectNoTierPriceWork(): void
    {
        $this->tierPrice->expects(self::never())->method('getPrices');
        $this->tierPrice->expects(self::never())->method('savePrices');
        $this->tierPrice->expects(self::never())->method('deletePrices');
    }

    /**
     * @param TierPriceInterface[] $entries
     */
    private function contextFor(
        string $sku,
        array $entries,
        int $entityId = 10,
        ?int $linkId = null,
        string $typeId = 'simple'
    ): BatchContext {
        $product = (new Product())->setSku($sku);
        $product->setTierPrices($entries);

        $context = new BatchContext([$product], 0);
        $context->setEntityId($sku, $entityId);
        $context->set(EntityProcessor::CONTEXT_LINK_IDS, [$sku => $linkId ?? $entityId]);
        $context->set(EntityProcessor::CONTEXT_TYPE_IDS, [$sku => $typeId]);

        return $context;
    }

    private function entry(
        string $customerGroup,
        float $qty,
        ?float $price = null,
        ?float $percentage = null,
        ?string $website = null
    ): TierPriceInterface {
        $entry = (new TierPriceData())->setCustomerGroup($customerGroup);
        $entry->setQty($qty);
        if ($price !== null) {
            $entry->setPrice($price);
        }
        if ($percentage !== null) {
            $entry->setPercentageDiscount($percentage);
        }
        if ($website !== null) {
            $entry->setWebsite($website);
        }

        return $entry;
    }

    /**
     * @return array{link_id: int, all_groups: int, customer_group_id: int, qty: string,
     *      value: string, website_id: int, percentage_value: string|null}
     */
    private function row(
        int $allGroups,
        int $groupId,
        string $qty,
        string $value,
        ?string $percentage = null,
        int $websiteId = 0,
        int $linkId = 10
    ): array {
        return [
            'link_id' => $linkId,
            'all_groups' => $allGroups,
            'customer_group_id' => $groupId,
            'qty' => $qty,
            'value' => $value,
            'website_id' => $websiteId,
            'percentage_value' => $percentage,
        ];
    }

    /**
     * @return array{value_id: int, value: string, percentage_value: string|null}
     */
    private function currentRow(int $valueId, string $value, ?string $percentage = null): array
    {
        return ['value_id' => $valueId, 'value' => $value, 'percentage_value' => $percentage];
    }
}
