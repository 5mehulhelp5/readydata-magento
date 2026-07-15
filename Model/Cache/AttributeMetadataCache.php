<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Cache;

use Magento\Catalog\Model\Product;
use Magento\Framework\App\ResourceConnection;

/**
 * Request-scoped cache of product EAV attribute metadata and attribute
 * sets, loaded in bulk. One query per unseen batch of attribute codes.
 */
class AttributeMetadataCache
{
    /**
     * @var array<string, array{attribute_id: int, attribute_code: string, backend_type: string,
     *      frontend_input: string, is_global: int, is_required: int}|null>
     */
    private array $attributesByCode = [];

    private ?int $entityTypeId = null;
    private ?array $attributeSetIdByName = null;
    private ?int $defaultAttributeSetId = null;

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Pre-load metadata for the given attribute codes (one query for all
     * codes not seen yet in this request).
     *
     * @param string[] $codes
     */
    public function warm(array $codes): void
    {
        $missing = array_diff($codes, array_keys($this->attributesByCode));
        if (!$missing) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                ['ea' => $this->resourceConnection->getTableName('eav_attribute')],
                ['attribute_code', 'attribute_id', 'backend_type', 'frontend_input', 'is_required']
            )
            ->joinLeft(
                ['cea' => $this->resourceConnection->getTableName('catalog_eav_attribute')],
                'cea.attribute_id = ea.attribute_id',
                ['is_global']
            )
            ->where('ea.entity_type_id = ?', $this->getEntityTypeId())
            ->where('ea.attribute_code IN (?)', array_values($missing));

        foreach ($missing as $code) {
            $this->attributesByCode[$code] = null;
        }
        foreach ($connection->fetchAll($select) as $row) {
            $this->attributesByCode[$row['attribute_code']] = [
                'attribute_id' => (int)$row['attribute_id'],
                'attribute_code' => $row['attribute_code'],
                'backend_type' => $row['backend_type'],
                'frontend_input' => (string)$row['frontend_input'],
                'is_global' => (int)($row['is_global'] ?? 1),
                'is_required' => (int)$row['is_required'],
            ];
        }
    }

    /**
     * @return array{attribute_id: int, attribute_code: string, backend_type: string,
     *      frontend_input: string, is_global: int, is_required: int}|null null when the attribute does not exist
     */
    public function get(string $code): ?array
    {
        if (!array_key_exists($code, $this->attributesByCode)) {
            $this->warm([$code]);
        }

        return $this->attributesByCode[$code];
    }

    public function getEntityTypeId(): int
    {
        if ($this->entityTypeId === null) {
            $connection = $this->resourceConnection->getConnection();
            $select = $connection->select()
                ->from($this->resourceConnection->getTableName('eav_entity_type'), 'entity_type_id')
                ->where('entity_type_code = ?', Product::ENTITY);
            $this->entityTypeId = (int)$connection->fetchOne($select);
        }

        return $this->entityTypeId;
    }

    /**
     * Resolve an attribute set by name or numeric ID; null when unknown.
     */
    public function resolveAttributeSetId(?string $nameOrId): ?int
    {
        if ($nameOrId === null || $nameOrId === '') {
            return $this->getDefaultAttributeSetId();
        }
        if (ctype_digit($nameOrId)) {
            return in_array((int)$nameOrId, $this->getAttributeSetMap(), true) ? (int)$nameOrId : null;
        }

        return $this->getAttributeSetMap()[$nameOrId] ?? null;
    }

    public function getDefaultAttributeSetId(): int
    {
        if ($this->defaultAttributeSetId === null) {
            $connection = $this->resourceConnection->getConnection();
            $select = $connection->select()
                ->from($this->resourceConnection->getTableName('eav_entity_type'), 'default_attribute_set_id')
                ->where('entity_type_id = ?', $this->getEntityTypeId());
            $this->defaultAttributeSetId = (int)$connection->fetchOne($select);
        }

        return $this->defaultAttributeSetId;
    }

    /**
     * @return array<string, int> attribute set name => ID
     */
    private function getAttributeSetMap(): array
    {
        if ($this->attributeSetIdByName === null) {
            $connection = $this->resourceConnection->getConnection();
            $select = $connection->select()
                ->from(
                    $this->resourceConnection->getTableName('eav_attribute_set'),
                    ['attribute_set_name', 'attribute_set_id']
                )
                ->where('entity_type_id = ?', $this->getEntityTypeId());
            $this->attributeSetIdByName = array_map('intval', $connection->fetchPairs($select));
        }

        return $this->attributeSetIdByName;
    }
}
