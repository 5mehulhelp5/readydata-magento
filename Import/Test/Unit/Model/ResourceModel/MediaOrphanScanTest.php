<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Test\Unit\Model\ResourceModel;

use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReadyData\Import\Logger\Logger;
use ReadyData\Import\Model\Cache\AttributeMetadataCache;
use ReadyData\Import\Model\Media\Cleanup\MediaPathNormalizer;
use ReadyData\Import\Model\ResourceModel\MediaOrphanScan;

class MediaOrphanScanTest extends TestCase
{
    private const T_CANDIDATE = 'readydata_media_scan_candidate';
    private const T_REFERENCE = 'readydata_media_scan_reference';
    private const ALL_ROLES = ['image', 'small_image', 'thumbnail', 'swatch_image'];

    private AdapterInterface&MockObject $connection;
    private ResourceConnection&MockObject $resourceConnection;
    private AttributeMetadataCache&MockObject $attributeMetadataCache;

    /** @var array<int, array{0: string, 1: array<int, mixed>}> every Select call made, in order */
    private array $selectCalls = [];

    /** @var array<int, array{0: string, 1: mixed}> adapter DDL calls, in order */
    private array $ddlCalls = [];

    /** @var array<string, array<int, array{0: string, 1: string, 2: int|null, 3: array<string, mixed>}>> */
    private array $columns = [];

    /** @var array<string, bool> */
    private array $tablesPresent = [];

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->resourceConnection->method('getConnection')->willReturn($this->connection);
        $this->resourceConnection->method('getTableName')->willReturnArgument(0);

        $this->connection->method('quoteIdentifier')->willReturnCallback(static fn (string $c): string => "`$c`");
        $this->connection->method('quote')->willReturnCallback(static fn ($v): string => "'" . $v . "'");
        $this->connection->method('select')->willReturnCallback(fn (): Select => $this->passthroughSelect());
        $this->connection->method('isTableExists')
            ->willReturnCallback(fn (string $t): bool => $this->tablesPresent[$t] ?? true);
        $this->connection->method('newTable')->willReturnCallback(fn (string $n): Table => $this->tableMock($n));
        $this->connection->method('dropTemporaryTable')->willReturnCallback(function (string $n): bool {
            $this->ddlCalls[] = ['drop', $n];
            return true;
        });
        $this->connection->method('createTemporaryTable')->willReturnCallback(function (Table $t): bool {
            $this->ddlCalls[] = ['create', $t->getName()];
            return true;
        });

        // Default: all four roles exist and are varchar. Tests that care about
        // the role list call stubRoles() again to replace it.
        $this->stubRoles(array_combine(self::ALL_ROLES, [90, 91, 92, 93]));
    }

    /**
     * A pooled or persistent connection can still carry the previous run's
     * tables, and CREATE TEMPORARY TABLE does not overwrite. Core drops first
     * for the same reason.
     */
    public function testTemporaryTablesAreDroppedBeforeTheyAreCreated(): void
    {
        $this->scan()->createTables();

        self::assertSame(
            [
                ['drop', self::T_CANDIDATE],
                ['drop', self::T_REFERENCE],
                ['create', self::T_CANDIDATE],
                ['create', self::T_REFERENCE],
            ],
            $this->ddlCalls
        );
    }

    /**
     * VARBINARY, not VARCHAR. The adapter injects its default charset and
     * collation into every varchar/char/text column it creates, and below MySQL
     * 8.0.29 that default is utf8mb3 — against utf8mb4 core columns that either
     * coerces the only indexed side of the join or throws "illegal mix of
     * collations". Byte-exact is also right for a case-sensitive filesystem.
     */
    public function testPathColumnsAreVarbinarySoNoCollationIsInjected(): void
    {
        $this->scan()->createTables();

        foreach ([self::T_CANDIDATE, self::T_REFERENCE] as $table) {
            $path = $this->columnDefinition($table, 'path');
            self::assertSame(Table::TYPE_VARBINARY, $path[1], $table . '.path must be VARBINARY');
            // 255 is the adapter's boundary between varbinary(N) and BLOB, and
            // a BLOB cannot carry a primary key without a prefix length.
            self::assertSame(255, $path[2], $table . '.path must stay within the varbinary boundary');
        }
    }

    /**
     * "ON r.path = c.path" is every query in this class. A source-leading
     * primary key cannot serve it and the anti-join degrades to a full scan.
     */
    public function testTheReferenceTablePrimaryKeyLeadsWithPath(): void
    {
        $this->scan()->createTables();

        $primary = array_values(array_filter(
            $this->columns[self::T_REFERENCE],
            static fn (array $column): bool => !empty($column[3]['primary'])
        ));

        self::assertSame('path', $primary[0][0]);
        self::assertSame('source', $primary[1][0]);
    }

    public function testCandidatesAreInsertedInChunksOfOneThousand(): void
    {
        $rows = [];
        for ($i = 0; $i < 2001; $i++) {
            $rows[] = ['path' => sprintf('/a/b/f%d.jpg', $i), 'size' => 1, 'mtime' => 1];
        }

        $sizes = [];
        $this->connection->expects(self::exactly(3))->method('insertOnDuplicate')
            ->willReturnCallback(function (string $table, array $chunk) use (&$sizes): int {
                self::assertSame(self::T_CANDIDATE, $table);
                $sizes[] = count($chunk);
                return count($chunk);
            });

        $this->scan()->addCandidates($rows);

        self::assertSame([1000, 1000, 1], $sizes);
    }

    public function testNoQueryIsIssuedForAnEmptyCandidateBatch(): void
    {
        $this->connection->expects(self::never())->method('insertOnDuplicate');

        $this->scan()->addCandidates([]);
    }

    /**
     * The invariant the whole feature rests on. A product delete cascades
     * _value_to_entity away but leaves the main gallery row, so counting rows
     * by path alone — core's countImageUses() — reports a deleted product's
     * leftovers as live references and the file is never collectable.
     */
    public function testTheGalleryReferenceQueryRequiresTheValueToEntityBinding(): void
    {
        $this->connection->method('query')->willReturn($this->statementMock());

        $this->scan()->loadReferences();

        $joins = $this->recordedCalls('join');
        self::assertContains(
            [['b' => 'catalog_product_entity_media_gallery_value_to_entity'], 'b.value_id = g.value_id', []],
            $joins
        );
        self::assertSame([], $this->recordedCalls('joinLeft'));
    }

    /**
     * media_gallery_asset.path carries the base path; the gallery form does
     * not. Slicing at a hardcoded offset would work on a default store and
     * silently mismatch on any other.
     */
    public function testTheContentQueryStripsThePrefixByTheConfiguredBasePathLength(): void
    {
        $this->connection->method('query')->willReturn($this->statementMock());

        $this->scan('media/products')->loadReferences();

        $expressions = [];
        foreach ($this->recordedCalls('columns') as $args) {
            foreach ((array)$args[0] as $column) {
                $expressions[] = (string)$column;
            }
        }

        // strlen('media/products') === 14, and SUBSTRING is 1-indexed, so the
        // slice must start at 15 to land on the leading slash.
        self::assertContains('SUBSTRING(a.path, 15)', $expressions);
    }

    public function testTheContentSourceIsSkippedWhenTheAssetTablesAreAbsent(): void
    {
        $this->tablesPresent = ['media_gallery_asset' => false, 'media_content_asset' => false];
        $this->connection->method('query')->willReturn($this->statementMock());

        $loaded = $this->scan()->loadReferences();

        self::assertSame(0, $loaded[MediaOrphanScan::SOURCE_CONTENT]);
        foreach ($this->recordedCalls('from') as $args) {
            self::assertNotSame(['a' => 'media_gallery_asset'], $args[0]);
        }
    }

    public function testRoleAttributesAreGroupedByTheirBackendType(): void
    {
        $this->stubRoles(
            ['image' => 90, 'small_image' => 91, 'thumbnail' => 92, 'swatch_image' => 93],
            ['swatch_image' => 'text']
        );
        $this->connection->method('query')->willReturn($this->statementMock());

        $this->scan()->loadReferences();

        $tables = array_column($this->recordedCalls('from'), 0);
        self::assertContains(['v' => 'catalog_product_entity_varchar'], $tables);
        self::assertContains(['v' => 'catalog_product_entity_text'], $tables);
    }

    /**
     * A role list naming an attribute this store never installed is a
     * misconfiguration; treating it as a missing reference would report every
     * file as unreferenced.
     */
    public function testAnUnknownRoleAttributeCodeIsDroppedRatherThanQueried(): void
    {
        $this->stubRoles(['image' => 90, 'small_image' => 91, 'thumbnail' => 92]);
        $this->connection->method('query')->willReturn($this->statementMock());

        $this->scan()->loadReferences();

        $attributeFilters = array_values(array_filter(
            $this->recordedCalls('where'),
            static fn (array $args): bool => $args[0] === 'v.attribute_id IN (?)'
        ));

        self::assertSame([[ 'v.attribute_id IN (?)', [90, 91, 92]]], $attributeFilters);
    }

    /**
     * Per-source figures must be overlap with the candidate set, not "rows this
     * pass eliminated". Sequential elimination would make every count after the
     * first depend on pass order, and since role values are usually a subset of
     * gallery paths the role source would read near zero on a healthy store.
     */
    public function testPerSourceCountsAreOverlapWithTheCandidateSet(): void
    {
        $this->connection->method('fetchAll')->willReturn([
            ['source' => '1', 'total' => '120'],
            ['source' => '2', 'total' => '80'],
        ]);

        $counts = $this->scan()->countReferencedCandidates();

        self::assertSame(
            [
                MediaOrphanScan::SOURCE_GALLERY => 120,
                MediaOrphanScan::SOURCE_ROLE => 80,
                MediaOrphanScan::SOURCE_CONTENT => 0,
            ],
            $counts
        );
        self::assertSame(
            [[['c' => self::T_CANDIDATE], 'c.path = r.path', []]],
            $this->recordedCalls('join')
        );
    }

    /**
     * The trust guard: references pointing at files that are not on disk. If
     * path normalisation is broken this is nearly the whole reference table,
     * and every other figure in the report is confidently wrong.
     */
    public function testMissingReferencesAreCountedPerSourceWithAnAntiJoin(): void
    {
        $this->connection->method('fetchAll')->willReturn([['source' => '1', 'total' => '7']]);

        $counts = $this->scan()->countMissingReferences();

        self::assertSame(7, $counts[MediaOrphanScan::SOURCE_GALLERY]);
        $conditions = array_map(static fn (array $args): string => (string)$args[0], $this->recordedCalls('where'));
        self::assertSame(['NOT EXISTS (?)'], $conditions);
    }

    /**
     * Paging by OFFSET costs more with every page and the whole point here is
     * that the orphan list is never held in memory at once.
     */
    public function testOrphanPagesUseKeysetPaginationNotAnOffset(): void
    {
        $this->connection->method('fetchCol')->willReturn(['/a/b/one.jpg']);

        self::assertSame(['/a/b/one.jpg'], $this->scan()->fetchOrphanPage('/a/b/zero.jpg', 1000));

        self::assertSame([['c.path ASC']], $this->recordedCalls('order'));
        self::assertSame([[1000]], $this->recordedCalls('limit'));
        self::assertContains(['c.path > ?', '/a/b/zero.jpg'], $this->recordedCalls('where'));
    }

    public function testTheFirstOrphanPageHasNoKeysetPredicate(): void
    {
        $this->connection->method('fetchCol')->willReturn([]);

        $this->scan()->fetchOrphanPage('', 1000);

        $conditions = array_map(static fn (array $args): string => (string)$args[0], $this->recordedCalls('where'));
        self::assertNotContains('c.path > ?', $conditions);
    }

    /**
     * mtime 0 means stat() told us nothing. Read as 1970 it would land in the
     * oldest bucket — the one an operator would act on first.
     */
    public function testUnknownModificationTimesGetTheirOwnBucket(): void
    {
        $this->connection->method('fetchAll')->willReturn([
            ['bucket' => '<7d', 'files' => '2', 'bytes' => '20'],
            ['bucket' => 'unknown', 'files' => '1', 'bytes' => '5'],
        ]);

        $buckets = $this->scan()->aggregateOrphansByAge(['<7d' => 100, '7-30d' => 50], '>30d', 'unknown');

        self::assertSame(['<7d', '7-30d', '>30d', 'unknown'], array_keys($buckets));
        self::assertSame(['files' => 2, 'bytes' => 20], $buckets['<7d']);
        self::assertSame(['files' => 1, 'bytes' => 5], $buckets['unknown']);
        self::assertSame(['files' => 0, 'bytes' => 0], $buckets['>30d']);
    }

    public function testUnboundGalleryRowsAreCountedWithALeftJoinLookingForNulls(): void
    {
        $this->connection->method('fetchOne')->willReturn('42');

        self::assertSame(42, $this->scan()->countUnboundGalleryRows());

        self::assertSame(
            [[['b' => 'catalog_product_entity_media_gallery_value_to_entity'], 'b.value_id = g.value_id', []]],
            $this->recordedCalls('joinLeft')
        );
        self::assertContains(['b.value_id IS NULL'], $this->recordedCalls('where'));
    }

    private function scan(string $basePath = 'catalog/product'): MediaOrphanScan
    {
        $mediaConfig = $this->createMock(MediaConfig::class);
        $mediaConfig->method('getBaseMediaPath')->willReturn($basePath);

        return new MediaOrphanScan(
            $this->resourceConnection,
            $this->attributeMetadataCache,
            new MediaPathNormalizer($mediaConfig),
            $this->createMock(Logger::class),
            self::ALL_ROLES
        );
    }

    /**
     * @param array<string, int> $idsByCode codes absent from this map do not exist
     * @param array<string, string> $backendTypesByCode defaults to varchar
     */
    private function stubRoles(array $idsByCode, array $backendTypesByCode = []): void
    {
        $this->attributeMetadataCache = $this->createMock(AttributeMetadataCache::class);
        $this->attributeMetadataCache->method('get')->willReturnCallback(
            static function (string $code) use ($idsByCode, $backendTypesByCode): ?array {
                if (!isset($idsByCode[$code])) {
                    return null;
                }

                return [
                    'attribute_id' => $idsByCode[$code],
                    'attribute_code' => $code,
                    'backend_type' => $backendTypesByCode[$code] ?? 'varchar',
                    'frontend_input' => 'media_image',
                    'frontend_label' => $code,
                    'is_global' => 0,
                    'is_required' => 0,
                    'apply_to' => '',
                ];
            }
        );
    }

    private function tableMock(string $name): Table&MockObject
    {
        $this->columns[$name] = [];
        $table = $this->createMock(Table::class);
        $table->method('getName')->willReturn($name);
        $table->method('addColumn')->willReturnCallback(
            function (string $column, string $type, $size = null, array $options = []) use ($table, $name): Table {
                $this->columns[$name][] = [$column, $type, $size, $options];

                return $table;
            }
        );

        return $table;
    }

    /**
     * @return array{0: string, 1: string, 2: int|null, 3: array<string, mixed>}
     */
    private function columnDefinition(string $table, string $column): array
    {
        foreach ($this->columns[$table] ?? [] as $definition) {
            if ($definition[0] === $column) {
                return $definition;
            }
        }

        throw new \PHPUnit\Framework\AssertionFailedError(
            sprintf('Column "%s" was never added to "%s".', $column, $table)
        );
    }

    private function statementMock(): \Zend_Db_Statement_Interface&MockObject
    {
        $statement = $this->createMock(\Zend_Db_Statement_Interface::class);
        $statement->method('rowCount')->willReturn(0);

        return $statement;
    }

    /**
     * Chainable Select stub that records what was built on it. assemble() has
     * to answer too: the reference passes wrap a Select in a raw INSERT IGNORE.
     */
    private function passthroughSelect(): Select&MockObject
    {
        $select = $this->createMock(Select::class);
        foreach (['from', 'join', 'joinLeft', 'columns', 'where', 'order', 'limit', 'group', 'distinct'] as $method) {
            $select->method($method)->willReturnCallback(
                function (...$args) use ($select, $method): Select&MockObject {
                    $this->selectCalls[] = [$method, $args];

                    return $select;
                }
            );
        }
        $select->method('assemble')->willReturn('SELECT 1');

        return $select;
    }

    /**
     * Trailing nulls are dropped: a mocked method hands the callback its FULL
     * signature, so Select::from($name, $cols, $schema) records a third null
     * the caller never passed.
     *
     * @return array<int, array<int, mixed>>
     */
    private function recordedCalls(string $method): array
    {
        $calls = [];
        foreach ($this->selectCalls as [$called, $args]) {
            if ($called !== $method) {
                continue;
            }
            while ($args !== [] && end($args) === null) {
                array_pop($args);
            }
            $calls[] = $args;
        }

        return $calls;
    }
}
