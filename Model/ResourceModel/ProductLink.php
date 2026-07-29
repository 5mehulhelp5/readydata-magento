<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

/**
 * Direct reads/writes on the product link tables: catalog_product_link and the
 * "position" values in catalog_product_link_attribute_int.
 *
 * catalog_product_link.product_id holds the LINKING product's link field value
 * (entity_id on CE, row_id on EE — resolve via ProductEntity::getLinkField());
 * linked_product_id is always the target's entity_id. Core relies on the same
 * asymmetry (Magento\Catalog\Model\ResourceModel\Product\Link\Product\Collection
 * joins links.product_id on the entity table's link field and
 * links.linked_product_id on entity_id).
 *
 * catalog_product_relation is deliberately NOT written: it describes
 * grouped/configurable parentage, not merchandising links.
 */
class ProductLink
{
    public const TYPE_RELATED = 1;
    public const TYPE_UP_SELL = 4;
    public const TYPE_CROSS_SELL = 5;

    private const T_LINK = 'catalog_product_link';
    private const T_LINK_ATTR = 'catalog_product_link_attribute';
    private const T_LINK_ATTR_INT = 'catalog_product_link_attribute_int';
    private const POSITION_CODE = 'position';
    private const CHUNK = 1000;

    /**
     * @var array<int, int>|null memoized for the request
     */
    private ?array $positionAttributeIds = null;

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * product_link_attribute_id of the int "position" attribute, per link type.
     * Core seeds one row per link type; a type without one simply gets no
     * positions.
     *
     * @return array<int, int> link_type_id => product_link_attribute_id
     */
    public function getPositionAttributeIds(): array
    {
        if ($this->positionAttributeIds !== null) {
            return $this->positionAttributeIds;
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                $this->resourceConnection->getTableName(self::T_LINK_ATTR),
                ['link_type_id', 'product_link_attribute_id']
            )
            ->where('product_link_attribute_code = ?', self::POSITION_CODE);

        $ids = [];
        foreach ($connection->fetchAll($select) as $row) {
            $ids[(int)$row['link_type_id']] = (int)$row['product_link_attribute_id'];
        }

        return $this->positionAttributeIds = $ids;
    }

    /**
     * Existing links of the given linking products, with their stored position.
     * A link with no position row yields position null (legal — core LEFT-joins
     * link attributes too).
     *
     * @param int[] $sourceLinkIds linking products' link field values
     * @param int[] $linkTypeIds
     * @return array<int, array<int, array<int, array{link_id: int, position: int|null}>>>
     *         source link id => link type id => target entity_id => {link_id, position}
     */
    public function getLinks(array $sourceLinkIds, array $linkTypeIds): array
    {
        if (!$sourceLinkIds || !$linkTypeIds) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                ['l' => $this->resourceConnection->getTableName(self::T_LINK)],
                ['product_id', 'link_type_id', 'linked_product_id', 'link_id']
            )
            ->where('l.product_id IN (?)', $sourceLinkIds)
            ->where('l.link_type_id IN (?)', $linkTypeIds);

        $positionAttributeIds = array_values($this->getPositionAttributeIds());
        if ($positionAttributeIds) {
            $select->joinLeft(
                ['pos' => $this->resourceConnection->getTableName(self::T_LINK_ATTR_INT)],
                $connection->quoteInto(
                    'pos.link_id = l.link_id AND pos.product_link_attribute_id IN (?)',
                    $positionAttributeIds
                ),
                ['position' => 'value']
            );
        }

        $links = [];
        foreach ($connection->fetchAll($select) as $row) {
            $links[(int)$row['product_id']][(int)$row['link_type_id']][(int)$row['linked_product_id']] = [
                'link_id' => (int)$row['link_id'],
                'position' => isset($row['position']) ? (int)$row['position'] : null,
            ];
        }

        return $links;
    }

    /**
     * No-op upsert: new links are inserted, existing
     * (link_type_id, product_id, linked_product_id) tuples keep their link_id
     * and their position row. link_id must never be supplied — rewriting it
     * would orphan the position rows.
     *
     * @param array<int, array{link_type_id: int, product_id: int, linked_product_id: int}> $rows
     */
    public function insertLinks(array $rows): void
    {
        if (!$rows) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::T_LINK);
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $connection->insertOnDuplicate($table, $chunk, ['link_type_id']);
        }
    }

    /**
     * Delete links by their full (link_type_id, product_id, linked_product_id)
     * tuple; the position rows cascade via FK on link_id. The link type MUST be
     * part of the tuple — without it the same pair would also be removed from
     * the other link types and from grouped children.
     *
     * @param array<int, array{link_type_id: int, product_id: int, linked_product_id: int}> $tuples
     */
    public function deleteLinks(array $tuples): void
    {
        if (!$tuples) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::T_LINK);
        foreach (array_chunk($tuples, self::CHUNK) as $chunk) {
            $quoted = [];
            foreach ($chunk as $tuple) {
                $quoted[] = $connection->quoteInto(
                    '(?)',
                    [
                        (int)$tuple['link_type_id'],
                        (int)$tuple['product_id'],
                        (int)$tuple['linked_product_id'],
                    ]
                );
            }
            $connection->delete(
                $table,
                '(link_type_id, product_id, linked_product_id) IN (' . implode(', ', $quoted) . ')'
            );
        }
    }

    /**
     * Upsert link positions, conflicting on the
     * (product_link_attribute_id, link_id) unique key.
     *
     * @param array<int, array{product_link_attribute_id: int, link_id: int, value: int}> $rows
     */
    public function savePositions(array $rows): void
    {
        if (!$rows) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::T_LINK_ATTR_INT);
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $connection->insertOnDuplicate($table, $chunk, ['value']);
        }
    }
}
