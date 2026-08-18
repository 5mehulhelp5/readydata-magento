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
use ReadyData\Import\Model\ResourceModel\EavValue;
use ReadyData\Import\Model\ResourceModel\ProductEntity;

/**
 * Covers findValuesInUse(), the reverse-direction read the media reference check
 * needs: "does any product anywhere still hold these values".
 */
class EavValueTest extends TestCase
{
    private const T_VARCHAR = 'catalog_product_entity_varchar';

    private AdapterInterface&MockObject $connection;
    private EavValue $resource;

    /** @var array<int, array{0: string, 1: array<int, mixed>}> every Select call made, in order */
    private array $selectCalls = [];

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($this->connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);
        $this->connection->method('select')->willReturnCallback(fn (): Select => $this->passthroughSelect());

        $productEntity = $this->createMock(ProductEntity::class);
        $productEntity->method('getLinkField')->willReturn('entity_id');

        $this->resource = new EavValue($resourceConnection, $productEntity);
    }

    public function testFindValuesInUseReturnsTheStoredSubset(): void
    {
        $this->connection->expects(self::once())->method('fetchCol')->willReturn(['/a/a/one.jpg']);

        $inUse = $this->resource->findValuesInUse('varchar', [90, 91], ['/a/a/one.jpg', '/b/b/two.jpg']);

        self::assertSame(['/a/a/one.jpg'], $inUse);
        self::assertSame([[self::T_VARCHAR, ['value']]], $this->recordedCalls('from'));
    }

    /**
     * No store_id filter: a role set on one store view only is still a reference,
     * and filtering to the default scope would report the file as unused.
     */
    public function testFindValuesInUseIsNotScopedToAStore(): void
    {
        $this->connection->method('fetchCol')->willReturn([]);

        $this->resource->findValuesInUse('varchar', [90], ['/a/a/one.jpg']);

        self::assertSame(
            [['attribute_id IN (?)', [90]], ['value IN (?)', ['/a/a/one.jpg']]],
            $this->recordedCalls('where')
        );
    }

    /**
     * @dataProvider emptyInputProvider
     * @param int[] $attributeIds
     * @param string[] $values
     */
    public function testFindValuesInUseQueriesNothingWithoutBothSides(
        array $attributeIds,
        array $values
    ): void {
        $this->connection->expects(self::never())->method('fetchCol');

        self::assertSame([], $this->resource->findValuesInUse('varchar', $attributeIds, $values));
    }

    /**
     * @return array<string, array{0: int[], 1: string[]}>
     */
    public static function emptyInputProvider(): array
    {
        return [
            'no attributes' => [[], ['/a/a/one.jpg']],
            'no values' => [[90], []],
            'neither' => [[], []],
        ];
    }

    public function testFindValuesInUseRejectsAnUnknownBackendType(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->resource->findValuesInUse('geometry', [90], ['/a/a/one.jpg']);
    }

    public function testFindValuesInUseChunksLargeInputs(): void
    {
        $values = [];
        for ($i = 0; $i < 1001; $i++) {
            $values[] = sprintf('/a/a/f%d.jpg', $i);
        }

        $this->connection->expects(self::exactly(2))->method('fetchCol')->willReturn([]);

        self::assertSame([], $this->resource->findValuesInUse('varchar', [90], $values));
    }

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
