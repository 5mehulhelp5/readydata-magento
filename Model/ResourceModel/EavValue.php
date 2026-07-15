<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use ReadyData\Import\Model\ResourceModel\ProductEntity;

/**
 * Bulk reads/upserts on the catalog_product_entity_* value tables.
 */
class EavValue
{
    private const INSERT_CHUNK = 1000;

    public const BACKEND_TYPES = ['varchar', 'int', 'decimal', 'text', 'datetime'];

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly ProductEntity $productEntity
    ) {
    }

    /**
     * Bulk-read stored values of one attribute (e.g. current url_keys).
     *
     * @param int[] $linkIds
     * @return array<int, string> link_id => value
     */
    public function getValues(string $backendType, int $attributeId, array $linkIds, int $storeId = 0): array
    {
        if (!$linkIds) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $linkField = $this->productEntity->getLinkField();
        $select = $connection->select()
            ->from(
                $this->resourceConnection->getTableName('catalog_product_entity_' . $backendType),
                [$linkField, 'value']
            )
            ->where('attribute_id = ?', $attributeId)
            ->where('store_id = ?', $storeId)
            ->where($linkField . ' IN (?)', $linkIds);

        return $connection->fetchPairs($select);
    }

    /**
     * Delete specific value rows, e.g. for explicit attribute clearing.
     * Deleting a store-scoped row makes that store fall back to the
     * default-scope value.
     *
     * @param string $backendType one of BACKEND_TYPES
     * @param array<int, array{link_id: int, attribute_id: int, store_id: int}> $keys
     */
    public function delete(string $backendType, array $keys): void
    {
        if (!$keys) {
            return;
        }
        if (!in_array($backendType, self::BACKEND_TYPES, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported EAV backend type "%s".', $backendType));
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('catalog_product_entity_' . $backendType);
        $linkField = $connection->quoteIdentifier($this->productEntity->getLinkField());
        foreach (array_chunk($keys, self::INSERT_CHUNK) as $chunk) {
            $tuples = array_map(
                static fn (array $key): string => sprintf(
                    '(%d,%d,%d)',
                    $key['link_id'],
                    $key['attribute_id'],
                    $key['store_id']
                ),
                $chunk
            );
            $connection->delete(
                $table,
                sprintf('(%s, attribute_id, store_id) IN (%s)', $linkField, implode(',', $tuples))
            );
        }
    }

    /**
     * @param string $backendType one of BACKEND_TYPES
     * @param array<int, array{attribute_id: int, store_id: int, value: mixed}> $rows
     *        each row must also contain the link field column (entity_id/row_id)
     */
    public function upsert(string $backendType, array $rows): void
    {
        if (!$rows) {
            return;
        }
        if (!in_array($backendType, self::BACKEND_TYPES, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported EAV backend type "%s".', $backendType));
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('catalog_product_entity_' . $backendType);
        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            $connection->insertOnDuplicate($table, $chunk, ['value']);
        }
    }
}
