<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

/**
 * Product-to-website assignments (catalog_product_website).
 * Assignments are additive; unassignment is a future expansion.
 */
class Website
{
    private const INSERT_CHUNK = 1000;

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Current website assignments of the given products.
     *
     * @param int[] $productIds
     * @return array<int, int[]> product_id => website IDs
     */
    public function getAssignments(array $productIds): array
    {
        if (!$productIds) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                $this->resourceConnection->getTableName('catalog_product_website'),
                ['product_id', 'website_id']
            )
            ->where('product_id IN (?)', $productIds);

        $assignments = [];
        foreach ($connection->fetchAll($select) as $row) {
            $assignments[(int)$row['product_id']][] = (int)$row['website_id'];
        }

        return $assignments;
    }

    /**
     * @param array<int, array{product_id: int, website_id: int}> $rows
     */
    public function assign(array $rows): void
    {
        if (!$rows) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('catalog_product_website');
        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            $connection->insertOnDuplicate($table, $chunk, ['website_id']);
        }
    }
}
