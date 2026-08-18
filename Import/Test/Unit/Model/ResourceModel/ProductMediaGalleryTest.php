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
use ReadyData\Import\Model\ResourceModel\ProductEntity;
use ReadyData\Import\Model\ResourceModel\ProductMediaGallery;

class ProductMediaGalleryTest extends TestCase
{
    private const T_GALLERY = 'catalog_product_entity_media_gallery';
    private const T_VALUE = 'catalog_product_entity_media_gallery_value';
    private const T_BIND = 'catalog_product_entity_media_gallery_value_to_entity';
    private const T_VIDEO = 'catalog_product_entity_media_gallery_value_video';

    private AdapterInterface&MockObject $connection;
    private ResourceConnection&MockObject $resourceConnection;
    private ProductEntity&MockObject $productEntity;
    private ProductMediaGallery $resource;

    /** @var array<int, array{0: string, 1: array<int, mixed>}> every Select call made, in order */
    private array $selectCalls = [];

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->resourceConnection->method('getConnection')->willReturn($this->connection);
        // getTableName is identity in these tests (no table prefix).
        $this->resourceConnection->method('getTableName')->willReturnArgument(0);
        $this->connection->method('quoteIdentifier')->willReturnCallback(static fn (string $c): string => "`$c`");
        $this->connection->method('select')->willReturnCallback(fn (): Select => $this->passthroughSelect());

        $this->productEntity = $this->createMock(ProductEntity::class);
        $this->productEntity->method('getLinkField')->willReturn('entity_id');

        $this->resource = new ProductMediaGallery($this->resourceConnection, $this->productEntity);
    }

    public function testHasVideoTableIsMemoized(): void
    {
        $this->connection->expects(self::once())->method('isTableExists')
            ->with(self::T_VIDEO)
            ->willReturn(true);

        self::assertTrue($this->resource->hasVideoTable());
        self::assertTrue($this->resource->hasVideoTable());
    }

    public function testInsertGalleryRowsReturnsGeneratedIdsInInsertionOrder(): void
    {
        $this->connection->method('fetchOne')->willReturn('100');
        $this->connection->expects(self::once())->method('insertMultiple')
            ->with(self::T_GALLERY, [$this->row('/a/a/one.jpg'), $this->row('/b/b/two.jpg')]);
        // Never insertOnDuplicate: there is nothing to conflict on, and omitting
        // value_id is what makes AUTO_INCREMENT assign one.
        $this->connection->expects(self::never())->method('insertOnDuplicate');
        $this->connection->method('fetchAll')->willReturn([
            ['value_id' => '101', 'value' => '/a/a/one.jpg'],
            ['value_id' => '102', 'value' => '/b/b/two.jpg'],
        ]);

        $valueIds = $this->resource->insertGalleryRows([
            'k1' => $this->row('/a/a/one.jpg'),
            'k2' => $this->row('/b/b/two.jpg'),
        ]);

        self::assertSame(['k1' => 101, 'k2' => 102], $valueIds);
    }

    /**
     * The rows are already inserted at this point and cannot be identified, so
     * the only safe outcome is a throw that takes the batch's transaction with
     * it. Returning [] would commit them unbound.
     */
    public function testInsertGalleryRowsThrowsWhenAValueDoesNotMatch(): void
    {
        $this->connection->method('fetchOne')->willReturn('100');
        // A concurrent writer slipped its own gallery row into the same window.
        $this->connection->method('fetchAll')->willReturn([
            ['value_id' => '101', 'value' => '/x/x/stranger.jpg'],
            ['value_id' => '102', 'value' => '/b/b/two.jpg'],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('out of order at position 0');

        $this->resource->insertGalleryRows([
            'k1' => $this->row('/a/a/one.jpg'),
            'k2' => $this->row('/b/b/two.jpg'),
        ]);
    }

    public function testInsertGalleryRowsThrowsOnACountMismatch(): void
    {
        $this->connection->method('fetchOne')->willReturn('100');
        $this->connection->method('fetchAll')->willReturn([['value_id' => '101', 'value' => '/a/a/one.jpg']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('returned 1 unbound rows for 2 inserted');

        $this->resource->insertGalleryRows([
            'k1' => $this->row('/a/a/one.jpg'),
            'k2' => $this->row('/b/b/two.jpg'),
        ]);
    }

    public function testInsertGalleryRowsChunksAtOneThousand(): void
    {
        $rows = [];
        $readBack = [];
        for ($i = 0; $i < 2500; $i++) {
            $rows['k' . $i] = $this->row('/f/' . $i . '.jpg');
            $readBack[] = ['value_id' => (string)(1000 + $i), 'value' => '/f/' . $i . '.jpg'];
        }

        $this->connection->method('fetchOne')->willReturn('999');
        $this->connection->method('fetchAll')->willReturn($readBack);
        $this->connection->expects(self::exactly(3))->method('insertMultiple');

        self::assertCount(2500, $this->resource->insertGalleryRows($rows));
    }

    public function testBindToEntitiesUpsertsOnItsPrimaryKey(): void
    {
        $this->connection->expects(self::once())->method('insertOnDuplicate')
            ->with(
                self::T_BIND,
                [['value_id' => 501, 'entity_id' => 10], ['value_id' => 502, 'entity_id' => 10]],
                ['value_id']
            );

        $this->resource->bindToEntities([
            ['value_id' => 501, 'link_id' => 10],
            ['value_id' => 502, 'link_id' => 10],
        ]);
    }

    public function testSaveValuesDeletesTheExactTuplesBeforeInserting(): void
    {
        $where = null;
        $this->connection->expects(self::once())->method('delete')
            ->willReturnCallback(function (string $table, string $condition) use (&$where): int {
                self::assertSame(self::T_VALUE, $table);
                $where = $condition;
                return 1;
            });
        // The table's only unique key is its own AUTO_INCREMENT record_id, so an
        // upsert would append a duplicate instead of updating.
        $this->connection->expects(self::never())->method('insertOnDuplicate');
        $this->connection->expects(self::once())->method('insertMultiple')->with(self::T_VALUE, [
            [
                'value_id' => 501,
                'store_id' => 0,
                'entity_id' => 10,
                'label' => 'Front',
                'position' => 0,
                'disabled' => 0,
            ],
        ]);

        $this->resource->saveValues([[
            'value_id' => 501,
            'link_id' => 10,
            'label' => 'Front',
            'position' => 0,
            'disabled' => 0,
        ]]);

        self::assertSame('(value_id, `entity_id`, store_id) IN ((501,10,0))', $where);
    }

    public function testUpdateGalleryRowsIssuesOneStatementPerDistinctState(): void
    {
        $updates = [];
        $this->connection->expects(self::exactly(2))->method('update')
            ->willReturnCallback(function (string $table, array $data, array $where) use (&$updates): int {
                $updates[] = [$table, $data, $where];
                return 1;
            });

        $this->resource->updateGalleryRows([
            'image|0' => [501, 502],
            'external-video|0' => [503],
            'empty|0' => [],
        ]);

        self::assertSame(
            [
                [self::T_GALLERY, ['media_type' => 'image', 'disabled' => 0], ['value_id IN (?)' => [501, 502]]],
                [
                    self::T_GALLERY,
                    ['media_type' => 'external-video', 'disabled' => 0],
                    ['value_id IN (?)' => [503]],
                ],
            ],
            $updates
        );
    }

    public function testSaveVideosUpsertsAtTheDefaultScope(): void
    {
        $this->connection->method('isTableExists')->willReturn(true);
        $this->connection->expects(self::once())->method('insertOnDuplicate')->with(
            self::T_VIDEO,
            [[
                'value_id' => 501,
                'store_id' => 0,
                'provider' => 'youtube',
                'url' => 'https://youtu.be/abc',
                'title' => null,
                'description' => null,
                'metadata' => null,
            ]],
            ProductMediaGallery::VIDEO_FIELDS
        );

        $this->resource->saveVideos([[
            'value_id' => 501,
            'provider' => 'youtube',
            'url' => 'https://youtu.be/abc',
            'title' => null,
            'description' => null,
            'metadata' => null,
        ]]);
    }

    public function testVideoWritesAreNoOpsWithoutTheVideoTable(): void
    {
        $this->connection->method('isTableExists')->willReturn(false);
        $this->connection->expects(self::never())->method('insertOnDuplicate');
        $this->connection->expects(self::never())->method('delete');

        $this->resource->saveVideos([[
            'value_id' => 501,
            'provider' => 'youtube',
            'url' => 'https://youtu.be/abc',
            'title' => null,
            'description' => null,
            'metadata' => null,
        ]]);
        $this->resource->deleteVideos([501]);
    }

    public function testRemoveEntriesUnbindsThenDropsValuesThenTheUnboundGalleryRows(): void
    {
        // 502 is still bound to another product and must survive.
        $this->connection->method('fetchCol')->willReturn(['502']);

        $calls = [];
        $this->connection->expects(self::exactly(3))->method('delete')
            ->willReturnCallback(function (string $table, $condition) use (&$calls): int {
                $calls[] = [$table, $condition];
                return 1;
            });

        $this->resource->removeEntries([
            ['value_id' => 501, 'link_id' => 10],
            ['value_id' => 502, 'link_id' => 10],
        ]);

        $expectedWhere = '(value_id, `entity_id`) IN ((501,10),(502,10))';
        self::assertSame(
            [
                [self::T_BIND, $expectedWhere],
                [self::T_VALUE, $expectedWhere],
                [self::T_GALLERY, ['value_id IN (?)' => [501]]],
            ],
            $calls
        );
    }

    public function testRemoveEntriesKeepsEveryGalleryRowThatIsStillBound(): void
    {
        $this->connection->method('fetchCol')->willReturn(['501']);
        // Two deletes only: the gallery row is still in use elsewhere.
        $this->connection->expects(self::exactly(2))->method('delete');

        $this->resource->removeEntries([['value_id' => 501, 'link_id' => 10]]);
    }

    public function testFindReferencedFilesReturnsOnlyWhatIsStillBoundToAProduct(): void
    {
        $this->connection->expects(self::once())->method('fetchCol')->willReturn(['/a/a/one.jpg']);

        $referenced = $this->resource->findReferencedFiles(['/a/a/one.jpg', '/b/b/two.jpg']);

        self::assertSame(['/a/a/one.jpg'], $referenced);
        self::assertSame([[['g' => self::T_GALLERY], ['value']]], $this->recordedCalls('from'));
        self::assertSame([['g.value IN (?)', ['/a/a/one.jpg', '/b/b/two.jpg']]], $this->recordedCalls('where'));
    }

    /**
     * The binding join is the entire difference from core's countImageUses(): a
     * gallery row with no _value_to_entity row is what a product delete leaves
     * behind, and treating it as a use makes the file permanently un-collectable.
     * An INNER join is what excludes it — joinLeft here would be the core bug.
     */
    public function testFindReferencedFilesRequiresTheBindingWithAnInnerJoin(): void
    {
        $this->connection->method('fetchCol')->willReturn([]);

        $this->resource->findReferencedFiles(['/a/a/one.jpg']);

        self::assertSame([[['b' => self::T_BIND], 'b.value_id = g.value_id', []]], $this->recordedCalls('join'));
        self::assertSame([], $this->recordedCalls('joinLeft'));
    }

    public function testFindReferencedFilesQueriesNothingForAnEmptySet(): void
    {
        $this->connection->expects(self::never())->method('fetchCol');

        self::assertSame([], $this->resource->findReferencedFiles([]));
    }

    /**
     * The IN list is bounded, so a whole batch's removed_files cannot be sent as
     * one statement.
     */
    public function testFindReferencedFilesChunksLargeInputs(): void
    {
        $files = [];
        for ($i = 0; $i < 2001; $i++) {
            $files[] = sprintf('/a/a/f%d.jpg', $i);
        }

        $this->connection->expects(self::exactly(3))->method('fetchCol')->willReturn([]);

        self::assertSame([], $this->resource->findReferencedFiles($files));
        self::assertSame(
            [1000, 1000, 1],
            array_map(
                static fn (array $args): int => count($args[1]),
                $this->recordedCalls('where')
            )
        );
    }

    public function testGetGalleryUsesTheLinkFieldAndMapsRows(): void
    {
        $productEntity = $this->createMock(ProductEntity::class);
        $productEntity->method('getLinkField')->willReturn('row_id');
        $resource = new ProductMediaGallery($this->resourceConnection, $productEntity);

        $this->connection->method('isTableExists')->willReturn(true);
        $this->connection->method('fetchAll')->willReturn([
            [
                'link_id' => '555',
                'value_id' => '501',
                'value' => '/a/a/one.jpg',
                'media_type' => 'image',
                'gallery_disabled' => '0',
                'label' => 'Front',
                'position' => '0',
                'value_disabled' => '1',
                'record_id' => '7',
                'video_present' => null,
                'video_provider' => null,
                'video_url' => null,
                'video_title' => null,
                'video_description' => null,
                'video_metadata' => null,
            ],
            [
                // A NULL value is legacy junk no payload entry can ever claim.
                'link_id' => '555',
                'value_id' => '502',
                'value' => null,
                'media_type' => 'image',
                'gallery_disabled' => '0',
                'label' => null,
                'position' => null,
                'value_disabled' => null,
                'record_id' => null,
                'video_present' => null,
            ],
        ]);

        $gallery = $resource->getGallery([555], 90);

        self::assertSame([
            [
                'value_id' => 501,
                'file' => '/a/a/one.jpg',
                'media_type' => 'image',
                'gallery_disabled' => 0,
                'label' => 'Front',
                'position' => 0,
                'value_disabled' => 1,
                'has_value_row' => true,
                'video' => null,
            ],
            [
                'value_id' => 502,
                'file' => '',
                'media_type' => 'image',
                'gallery_disabled' => 0,
                'label' => null,
                'position' => null,
                'value_disabled' => 0,
                'has_value_row' => false,
                'video' => null,
            ],
        ], $gallery[555]);
    }

    public function testGetGalleryMapsTheVideoRecord(): void
    {
        $this->connection->method('isTableExists')->willReturn(true);
        $this->connection->method('fetchAll')->willReturn([[
            'link_id' => '10',
            'value_id' => '501',
            'value' => '/a/a/one.jpg',
            'media_type' => 'external-video',
            'gallery_disabled' => '0',
            'label' => null,
            'position' => '0',
            'value_disabled' => '0',
            'record_id' => '7',
            'video_present' => '501',
            'video_provider' => 'youtube',
            'video_url' => 'https://youtu.be/abc',
            'video_title' => 'Title',
            'video_description' => null,
            'video_metadata' => null,
        ]]);

        $gallery = $this->resource->getGallery([10], 90);

        self::assertSame([
            'provider' => 'youtube',
            'url' => 'https://youtu.be/abc',
            'title' => 'Title',
            'description' => null,
            'metadata' => null,
        ], $gallery[10][0]['video']);
    }

    public function testEveryWriterIsANoOpOnEmptyInput(): void
    {
        $this->connection->expects(self::never())->method('insertMultiple');
        $this->connection->expects(self::never())->method('insertOnDuplicate');
        $this->connection->expects(self::never())->method('update');
        $this->connection->expects(self::never())->method('delete');
        $this->connection->expects(self::never())->method('fetchAll');

        self::assertSame([], $this->resource->insertGalleryRows([]));
        self::assertSame([], $this->resource->getGallery([], 90));
        $this->resource->bindToEntities([]);
        $this->resource->saveValues([]);
        $this->resource->updateGalleryRows([]);
        $this->resource->saveVideos([]);
        $this->resource->deleteVideos([]);
        $this->resource->removeEntries([]);
    }

    /**
     * @return array{attribute_id: int, value: string, media_type: string, disabled: int}
     */
    private function row(string $file): array
    {
        return [
            'attribute_id' => 90,
            'value' => $file,
            'media_type' => ProductMediaGallery::MEDIA_TYPE_IMAGE,
            'disabled' => 0,
        ];
    }

    /**
     * Chainable Select stub that also records what was built on it, so a test can
     * assert on the query shape as well as on the mapped result.
     */
    private function passthroughSelect(): Select&MockObject
    {
        $select = $this->createMock(Select::class);
        foreach (['from', 'join', 'joinLeft', 'columns', 'where', 'order', 'limit', 'distinct'] as $method) {
            $select->method($method)->willReturnCallback(
                function (...$args) use ($select, $method): Select&MockObject {
                    $this->selectCalls[] = [$method, $args];

                    return $select;
                }
            );
        }

        return $select;
    }

    /**
     * Trailing nulls are dropped: a mocked method hands the callback its FULL
     * signature, so Select::from($name, $cols, $schema) records a third null the
     * caller never passed. Only the arguments actually supplied are of interest.
     *
     * @param string $method Select method to collect the arguments of
     * @return array<int, array<int, mixed>> argument lists, in call order
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
