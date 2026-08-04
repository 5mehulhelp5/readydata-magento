<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Test\Unit\Model\ResourceModel;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReadyData\Import\Model\ResourceModel\ProductEntity;
use ReadyData\Import\Model\ResourceModel\TierPrice;

class TierPriceTest extends TestCase
{
    private const TABLE = 'catalog_product_entity_tier_price';

    private AdapterInterface&MockObject $connection;
    private ResourceConnection&MockObject $resourceConnection;
    private ProductEntity&MockObject $productEntity;
    private ScopeConfigInterface&MockObject $scopeConfig;
    private TierPrice $resource;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->resourceConnection->method('getConnection')->willReturn($this->connection);
        // getTableName is identity in these tests (no table prefix).
        $this->resourceConnection->method('getTableName')->willReturnArgument(0);
        $this->connection->method('select')->willReturnCallback(fn (): Select => $this->passthroughSelect());

        $this->productEntity = $this->createMock(ProductEntity::class);
        $this->productEntity->method('getLinkField')->willReturn('entity_id');

        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);

        $this->resource = new TierPrice($this->resourceConnection, $this->productEntity, $this->scopeConfig);
    }

    /**
     * The scales are what make a re-import idempotent: a payload's "1" and the
     * stored "1.0000" must render identically, or every row diffs as changed.
     */
    public function testScalesMatchTheColumnDefinitions(): void
    {
        self::assertSame('1.0000', TierPrice::scaleQty(1));
        self::assertSame('10.5000', TierPrice::scaleQty(10.5));
        self::assertSame('19.990000', TierPrice::scaleValue(19.99));
        self::assertSame('0.000000', TierPrice::scaleValue(0.0));
        self::assertSame('20.00', TierPrice::scalePercentage(20));
        self::assertSame('12.35', TierPrice::scalePercentage(12.345));
    }

    /**
     * An "all groups" row and a "NOT LOGGED IN" row both store
     * customer_group_id = 0; only all_groups tells them apart, which is exactly
     * why the DB's unique key includes it.
     */
    public function testKeyDistinguishesAllGroupsFromGroupZero(): void
    {
        $allGroups = TierPrice::buildKey(0, TierPrice::ALL_GROUPS, 0, '1.0000');
        $notLoggedIn = TierPrice::buildKey(0, TierPrice::SPECIFIC_GROUP, 0, '1.0000');

        self::assertNotSame($allGroups, $notLoggedIn);
    }

    public function testKeyDistinguishesWebsiteAndQuantity(): void
    {
        $base = TierPrice::buildKey(0, 0, 2, '1.0000');

        self::assertNotSame($base, TierPrice::buildKey(1, 0, 2, '1.0000'));
        self::assertNotSame($base, TierPrice::buildKey(0, 0, 2, '10.0000'));
    }

    public function testGetPricesKeysRowsByTupleAndScalesThem(): void
    {
        $this->connection->method('fetchAll')->willReturn([
            [
                'link_id' => '7',
                'value_id' => '11',
                'all_groups' => '0',
                'customer_group_id' => '2',
                'qty' => '10.0000',
                'value' => '8.500000',
                'website_id' => '0',
                'percentage_value' => null,
            ],
            [
                'link_id' => '7',
                'value_id' => '12',
                'all_groups' => '1',
                'customer_group_id' => '0',
                'qty' => '1.0000',
                'value' => '0.000000',
                'website_id' => '1',
                'percentage_value' => '15.00',
            ],
        ]);

        $prices = $this->resource->getPrices([7]);

        self::assertSame(
            [
                7 => [
                    '0-0-2-10.0000' => ['value_id' => 11, 'value' => '8.500000', 'percentage_value' => null],
                    '1-1-0-1.0000' => ['value_id' => 12, 'value' => '0.000000', 'percentage_value' => '15.00'],
                ],
            ],
            $prices
        );
    }

    public function testGetPricesShortCircuitsWithoutLinkIds(): void
    {
        $this->connection->expects(self::never())->method('fetchAll');

        self::assertSame([], $this->resource->getPrices([]));
    }

    /**
     * The unique key is what matches an existing row, so only the two value
     * columns may be updated — and value_id must never be sent, or the row's
     * identity would be rewritten.
     */
    public function testSavePricesUpsertsOnTheValueColumnsOnly(): void
    {
        $this->connection->expects(self::once())->method('insertOnDuplicate')
            ->with(
                self::TABLE,
                [
                    [
                        'entity_id' => 7,
                        'all_groups' => 0,
                        'customer_group_id' => 2,
                        'qty' => '10.0000',
                        'value' => '8.500000',
                        'website_id' => 0,
                        'percentage_value' => null,
                    ],
                ],
                ['value', 'percentage_value']
            );

        $this->resource->savePrices([$this->row()]);
    }

    public function testSavePricesUsesTheLinkFieldAsTheColumnName(): void
    {
        $productEntity = $this->createMock(ProductEntity::class);
        $productEntity->method('getLinkField')->willReturn('row_id');
        $resource = new TierPrice($this->resourceConnection, $productEntity, $this->scopeConfig);

        $this->connection->expects(self::once())->method('insertOnDuplicate')
            ->with(
                self::TABLE,
                self::callback(static fn (array $rows): bool =>
                    array_key_exists('row_id', $rows[0])
                    && !array_key_exists('entity_id', $rows[0])
                    && $rows[0]['row_id'] === 7),
                ['value', 'percentage_value']
            );

        $resource->savePrices([$this->row()]);
    }

    public function testSavePricesAndDeletePricesAreNoOpsWhenEmpty(): void
    {
        $this->connection->expects(self::never())->method('insertOnDuplicate');
        $this->connection->expects(self::never())->method('delete');

        $this->resource->savePrices([]);
        $this->resource->deletePrices([]);
    }

    public function testDeletePricesDeletesByPrimaryKey(): void
    {
        $this->connection->expects(self::once())->method('delete')
            ->with(self::TABLE, ['value_id IN (?)' => [11, 12]]);

        $this->resource->deletePrices([11, 12]);
    }

    /**
     * catalog/price/scope: 0 = global, 1 = website. Unset means global, which is
     * the Magento default.
     */
    public function testPriceScopeIsGlobalUnlessWebsiteScopeIsSet(): void
    {
        $this->scopeConfig->method('isSetFlag')
            ->with('catalog/price/scope')
            ->willReturnOnConsecutiveCalls(false, true);

        self::assertTrue($this->resource->isPriceScopeGlobal());
        self::assertFalse($this->resource->isPriceScopeGlobal());
    }

    /**
     * @return array{link_id: int, all_groups: int, customer_group_id: int,
     *      qty: string, value: string, website_id: int, percentage_value: string|null}
     */
    private function row(): array
    {
        return [
            'link_id' => 7,
            'all_groups' => 0,
            'customer_group_id' => 2,
            'qty' => '10.0000',
            'value' => '8.500000',
            'website_id' => 0,
            'percentage_value' => null,
        ];
    }

    private function passthroughSelect(): Select&MockObject
    {
        $select = $this->createMock(Select::class);
        foreach (['from', 'join', 'joinLeft', 'columns', 'where', 'order', 'limit'] as $method) {
            $select->method($method)->willReturnSelf();
        }

        return $select;
    }
}
