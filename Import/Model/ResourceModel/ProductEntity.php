<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\ResourceModel;

use Magento\Catalog\Api\Data\ProductInterface as CatalogProductInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;

/**
 * Direct reads/writes on catalog_product_entity.
 */
class ProductEntity
{
    private const TABLE = 'catalog_product_entity';
    private const INSERT_CHUNK = 1000;

    private ?string $linkField = null;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly MetadataPool $metadataPool
    ) {
    }

    /**
     * EAV value tables reference this column: entity_id on CE, row_id on EE.
     */
    public function getLinkField(): string
    {
        if ($this->linkField === null) {
            $this->linkField = $this->metadataPool
                ->getMetadata(CatalogProductInterface::class)
                ->getLinkField();
        }

        return $this->linkField;
    }

    public function isStagingEnvironment(): bool
    {
        return $this->getLinkField() !== 'entity_id';
    }

    /**
     * @param string[] $skus
     * @return array<string, array{entity_id: int, link_id: int, attribute_set_id: int, type_id: string}>
     *         keyed by SKU
     */
    public function getExistingBySkus(array $skus): array
    {
        if (!$skus) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $linkField = $this->getLinkField();
        $select = $connection->select()
            ->from(
                $this->resourceConnection->getTableName(self::TABLE),
                ['sku', 'entity_id', 'link_id' => $linkField, 'attribute_set_id', 'type_id']
            )
            ->where('sku IN (?)', $skus);

        $result = [];
        foreach ($connection->fetchAll($select) as $row) {
            $result[$row['sku']] = [
                'entity_id' => (int)$row['entity_id'],
                'link_id' => (int)$row['link_id'],
                'attribute_set_id' => (int)$row['attribute_set_id'],
                'type_id' => $row['type_id'],
            ];
        }

        return $result;
    }

    /**
     * Multi-row upsert. Rows must contain sku, attribute_set_id, type_id,
     * has_options, required_options, created_at, updated_at.
     *
     * The sku index is NOT unique in Magento (uniqueness is enforced at the
     * application level), so rows for existing products MUST include the
     * link field (entity_id/row_id) — the upsert then conflicts on the
     * primary key. Rows without it are inserted as new products.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    public function upsert(array $rows): void
    {
        if (!$rows) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        // insertOnDuplicate derives columns from the first row; new and
        // existing rows have different shapes, so write them separately.
        $linkField = $this->getLinkField();
        $groups = ['new' => [], 'existing' => []];
        foreach ($rows as $row) {
            $groups[isset($row[$linkField]) ? 'existing' : 'new'][] = $row;
        }

        foreach ($groups as $group) {
            foreach (array_chunk($group, self::INSERT_CHUNK) as $chunk) {
                $connection->insertOnDuplicate(
                    $table,
                    $chunk,
                    ['attribute_set_id', 'type_id', 'has_options', 'required_options', 'updated_at']
                );
            }
        }
    }
}
