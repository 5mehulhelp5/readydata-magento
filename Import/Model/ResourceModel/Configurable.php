<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

/**
 * Direct reads/writes on the configurable-product structure tables:
 * catalog_product_super_attribute (+ _label), catalog_product_super_link and
 * catalog_product_relation.
 *
 * Parent-side columns (super_attribute.product_id, super_link.parent_id,
 * relation.parent_id) hold the link field value (entity_id on CE, row_id on
 * EE — resolve via ProductEntity::getLinkField()); the child side
 * (super_link.product_id, relation.child_id) is always the child entity_id.
 */
class Configurable
{
    private const T_SUPER_ATTR = 'catalog_product_super_attribute';
    private const T_SUPER_ATTR_LABEL = 'catalog_product_super_attribute_label';
    private const T_SUPER_LINK = 'catalog_product_super_link';
    private const T_RELATION = 'catalog_product_relation';
    private const CHUNK = 1000;

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Existing super attributes of the given parents.
     *
     * @param int[] $parentLinkIds parent link field values
     * @return array<int, array<int, int>> parent link id => [attribute_id => product_super_attribute_id]
     */
    public function getSuperAttributes(array $parentLinkIds): array
    {
        if (!$parentLinkIds) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                $this->resourceConnection->getTableName(self::T_SUPER_ATTR),
                ['product_id', 'attribute_id', 'product_super_attribute_id']
            )
            ->where('product_id IN (?)', $parentLinkIds);

        $result = [];
        foreach ($connection->fetchAll($select) as $row) {
            $result[(int)$row['product_id']][(int)$row['attribute_id']]
                = (int)$row['product_super_attribute_id'];
        }

        return $result;
    }

    /**
     * Existing child links of the given parents.
     *
     * @param int[] $parentLinkIds parent link field values
     * @return array<int, int[]> parent link id => child entity IDs
     */
    public function getChildLinks(array $parentLinkIds): array
    {
        if (!$parentLinkIds) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                $this->resourceConnection->getTableName(self::T_SUPER_LINK),
                ['parent_id', 'product_id']
            )
            ->where('parent_id IN (?)', $parentLinkIds);

        $links = [];
        foreach ($connection->fetchAll($select) as $row) {
            $links[(int)$row['parent_id']][] = (int)$row['product_id'];
        }

        return $links;
    }

    /**
     * Create one super attribute and its admin-scope label, returning the new
     * product_super_attribute_id. Volume is tiny (a handful of axes per
     * parent), so per-row inserts are fine — mirrors AttributeOption.
     */
    public function addSuperAttribute(int $parentLinkId, int $attributeId, int $position, string $label): int
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->insert(
            $this->resourceConnection->getTableName(self::T_SUPER_ATTR),
            [
                'product_id' => $parentLinkId,
                'attribute_id' => $attributeId,
                'position' => $position,
            ]
        );
        $superAttributeId = (int)$connection->lastInsertId(
            $this->resourceConnection->getTableName(self::T_SUPER_ATTR)
        );
        $connection->insert(
            $this->resourceConnection->getTableName(self::T_SUPER_ATTR_LABEL),
            [
                'product_super_attribute_id' => $superAttributeId,
                'store_id' => 0,
                'use_default' => 1,
                'value' => $label,
            ]
        );

        return $superAttributeId;
    }

    /**
     * Delete super attributes by ID. The _label rows cascade via FK.
     *
     * @param int[] $superAttributeIds
     */
    public function removeSuperAttributes(array $superAttributeIds): void
    {
        if (!$superAttributeIds) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::T_SUPER_ATTR);
        foreach (array_chunk($superAttributeIds, self::CHUNK) as $chunk) {
            $connection->delete($table, ['product_super_attribute_id IN (?)' => $chunk]);
        }
    }

    /**
     * Link children to parents in both catalog_product_super_link and
     * catalog_product_relation. Existing pairs are left untouched.
     *
     * @param array<int, array{parent_id: int, child_id: int}> $rows
     */
    public function linkChildren(array $rows): void
    {
        if (!$rows) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $superLink = $this->resourceConnection->getTableName(self::T_SUPER_LINK);
        $relation = $this->resourceConnection->getTableName(self::T_RELATION);

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $linkRows = [];
            $relationRows = [];
            foreach ($chunk as $row) {
                $linkRows[] = ['product_id' => $row['child_id'], 'parent_id' => $row['parent_id']];
                $relationRows[] = ['parent_id' => $row['parent_id'], 'child_id' => $row['child_id']];
            }
            $connection->insertOnDuplicate($superLink, $linkRows, ['parent_id']);
            $connection->insertOnDuplicate($relation, $relationRows, ['parent_id']);
        }
    }

    /**
     * Remove child links from both tables.
     *
     * @param array<int, array{parent_id: int, child_id: int}> $pairs
     */
    public function unlinkChildren(array $pairs): void
    {
        if (!$pairs) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $superLink = $this->resourceConnection->getTableName(self::T_SUPER_LINK);
        $relation = $this->resourceConnection->getTableName(self::T_RELATION);

        foreach (array_chunk($pairs, self::CHUNK) as $chunk) {
            $linkTuples = [];
            $relationTuples = [];
            foreach ($chunk as $pair) {
                $linkTuples[] = $connection->quoteInto(
                    '(?)',
                    [(int)$pair['child_id'], (int)$pair['parent_id']]
                );
                $relationTuples[] = $connection->quoteInto(
                    '(?)',
                    [(int)$pair['parent_id'], (int)$pair['child_id']]
                );
            }
            $connection->delete(
                $superLink,
                '(product_id, parent_id) IN (' . implode(', ', $linkTuples) . ')'
            );
            $connection->delete(
                $relation,
                '(parent_id, child_id) IN (' . implode(', ', $relationTuples) . ')'
            );
        }
    }
}
