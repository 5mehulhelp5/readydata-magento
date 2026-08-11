<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Test\Unit\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReadyData\Import\Model\ResourceModel\AmastyAttribute;

class AmastyAttributeTest extends TestCase
{
    private AdapterInterface&MockObject $connection;
    private ResourceConnection&MockObject $resourceConnection;
    private AmastyAttribute $resource;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->resourceConnection->method('getConnection')->willReturn($this->connection);
        // getTableName is identity in these tests (no table prefix).
        $this->resourceConnection->method('getTableName')->willReturnArgument(0);
        $this->connection->method('quoteIdentifier')->willReturnCallback(static fn (string $c): string => "`$c`");

        $this->resource = new AmastyAttribute($this->resourceConnection);
    }

    /** describeTable metadata shaped like Magento's adapter output. */
    private static function col(string $type, bool $nullable, mixed $default, bool $identity = false, bool $primary = false): array
    {
        return ['DATA_TYPE' => $type, 'NULLABLE' => $nullable, 'DEFAULT' => $default, 'IDENTITY' => $identity, 'PRIMARY' => $primary];
    }

    /** The real amasty_amshopby_filter_setting column shape (subset). */
    private static function filterTableColumns(): array
    {
        return [
            'setting_id' => self::col('smallint', false, null, true, true),
            'attribute_code' => self::col('varchar', false, null),
            'attribute_id' => self::col('smallint', true, null),
            'display_mode' => self::col('smallint', false, 0),
            'slider_step' => self::col('decimal', false, 1),
            'tooltip' => self::col('text', false, null),               // required, no default
            'filter_code' => self::col('varchar', false, null),        // required, no default
            'attribute_url_alias' => self::col('text', false, null),   // required, no default
        ];
    }

    public function testHasFilterTableResolvesFirstExistingCandidate(): void
    {
        $this->connection->method('isTableExists')
            ->willReturnCallback(static fn (string $t): bool => $t === 'amasty_amshopby_filter_setting');

        self::assertTrue($this->resource->hasFilterTable());
    }

    public function testHasFilterTableFalseWhenNoCandidateExists(): void
    {
        $this->connection->method('isTableExists')->willReturn(false);

        self::assertFalse($this->resource->hasFilterTable());
    }

    public function testUpsertFilterInsertsKeyedByCodeAndSeedsRequiredColumns(): void
    {
        $this->connection->method('isTableExists')->willReturn(true);
        $this->connection->method('describeTable')->willReturn(self::filterTableColumns());
        $this->connection->method('select')->willReturn($this->passthroughSelect());
        $this->connection->method('fetchOne')->willReturn(false); // row does not exist -> insert

        $inserted = null;
        $this->connection->expects(self::once())->method('insert')
            ->willReturnCallback(function (string $table, array $row) use (&$inserted): int {
                $inserted = $row;
                return 1;
            });
        $this->connection->expects(self::never())->method('update');

        $dropped = [];
        $changed = $this->resource->upsertFilter('brand', 42, ['display_mode' => 4, 'nope' => 'x'], $dropped);

        self::assertTrue($changed);
        self::assertSame('brand', $inserted['attribute_code']);
        self::assertSame(42, $inserted['attribute_id']);
        self::assertSame(4, $inserted['display_mode']);
        // Required NOT-NULL/no-default columns are auto-seeded so the insert is valid.
        self::assertSame('', $inserted['tooltip']);
        self::assertSame('', $inserted['filter_code']);
        self::assertSame('', $inserted['attribute_url_alias']);
        // Identity/PK column is never seeded.
        self::assertArrayNotHasKey('setting_id', $inserted);
        // Columns with a schema default are left for the DB to fill.
        self::assertArrayNotHasKey('slider_step', $inserted);
        // Unknown columns are reported, not written.
        self::assertSame(['nope'], $dropped);
    }

    public function testUpsertFilterUpdatesExistingRowWithoutSeedingDefaults(): void
    {
        $this->connection->method('isTableExists')->willReturn(true);
        $this->connection->method('describeTable')->willReturn(self::filterTableColumns());
        $this->connection->method('select')->willReturn($this->passthroughSelect());
        $this->connection->method('fetchOne')->willReturn('1'); // row exists -> update

        $updated = null;
        $this->connection->expects(self::once())->method('update')
            ->willReturnCallback(function (string $table, array $data) use (&$updated): int {
                $updated = $data;
                return 1;
            });
        $this->connection->expects(self::never())->method('insert');

        $dropped = [];
        $this->resource->upsertFilter('brand', 42, ['display_mode' => 2], $dropped);

        self::assertSame(2, $updated['display_mode']);
        self::assertArrayNotHasKey('tooltip', $updated); // no default-seeding on update
        self::assertArrayNotHasKey('attribute_code', $updated); // key is in WHERE, not SET
    }

    public function testUpsertFilterSkipsWhenTableLacksAttributeCode(): void
    {
        $this->connection->method('isTableExists')->willReturn(true);
        $this->connection->method('describeTable')->willReturn([
            'setting_id' => self::col('smallint', false, null, true, true),
            'display_mode' => self::col('smallint', false, 0),
        ]);
        $this->connection->expects(self::never())->method('insert');

        $dropped = [];
        self::assertFalse($this->resource->upsertFilter('brand', 42, ['display_mode' => 1], $dropped));
    }

    /** A Select mock whose fluent methods all return the same instance. */
    private function passthroughSelect(): Select&MockObject
    {
        $select = $this->createMock(Select::class);
        foreach (['from', 'columns', 'where', 'limit'] as $method) {
            $select->method($method)->willReturnSelf();
        }
        return $select;
    }
}
