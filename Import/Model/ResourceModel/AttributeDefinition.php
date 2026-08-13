<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

/**
 * Side-effect-free reads for the attribute sync: the current shape of existing
 * attributes (for the create-vs-update branch and the structural-drift check)
 * and attribute-set membership. Writes are performed by EavSetup, not here.
 *
 * Deliberately uncached and always querying the DB: the sync is low-volume and
 * a fresh read is what the duplicate-key re-check relies on.
 */
class AttributeDefinition
{
    /**
     * Columns compared during sync, keyed by real DB column name. The int list
     * is cast to int; everything else is a nullable string.
     */
    private const INT_COLUMNS = [
        'is_global',
        'is_required',
        'is_unique',
        'is_searchable',
        'is_filterable',
        'is_filterable_in_search',
        'is_comparable',
        'is_visible_on_front',
        'is_html_allowed_on_front',
        'is_wysiwyg_enabled',
        'used_in_product_listing',
        'used_for_sort_by',
        'is_visible_in_grid',
        'is_filterable_in_grid',
        'is_used_in_grid',
    ];

    private const STRING_COLUMNS = [
        'backend_type',
        'frontend_input',
        'frontend_label',
        'default_value',
        'note',
    ];

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Load the current definition of each existing attribute code.
     *
     * @param string[] $codes
     * @return array<string, array<string, int|string|null>> code => column map
     *         (includes attribute_id plus INT_COLUMNS and STRING_COLUMNS)
     */
    public function getExistingByCodes(int $entityTypeId, array $codes): array
    {
        $codes = array_values(array_unique(array_filter($codes)));
        if (!$codes) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                ['ea' => $this->resourceConnection->getTableName('eav_attribute')],
                array_merge(
                    ['attribute_id', 'attribute_code'],
                    ['backend_type', 'frontend_input', 'frontend_label', 'default_value', 'note',
                        'is_required', 'is_unique']
                )
            )
            ->joinLeft(
                ['cea' => $this->resourceConnection->getTableName('catalog_eav_attribute')],
                'cea.attribute_id = ea.attribute_id',
                [
                    'is_global', 'is_searchable', 'is_filterable', 'is_filterable_in_search',
                    'is_comparable', 'is_visible_on_front', 'is_html_allowed_on_front',
                    'is_wysiwyg_enabled', 'used_in_product_listing', 'used_for_sort_by',
                    'is_visible_in_grid', 'is_filterable_in_grid', 'is_used_in_grid',
                ]
            )
            ->where('ea.entity_type_id = ?', $entityTypeId)
            ->where('ea.attribute_code IN (?)', $codes);

        $result = [];
        foreach ($connection->fetchAll($select) as $row) {
            $normalized = ['attribute_id' => (int)$row['attribute_id']];
            foreach (self::INT_COLUMNS as $column) {
                $normalized[$column] = $row[$column] === null ? null : (int)$row[$column];
            }
            foreach (self::STRING_COLUMNS as $column) {
                $normalized[$column] = $row[$column] === null ? null : (string)$row[$column];
            }
            $result[$row['attribute_code']] = $normalized;
        }

        return $result;
    }

    /**
     * Whether the attribute already belongs to the given attribute set. Used to
     * keep placement additive: an attribute already in a set is left where the
     * merchant has it (never moved between groups).
     */
    public function isAttributeInSet(int $attributeId, int $attributeSetId): bool
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName('eav_entity_attribute'), 'entity_attribute_id')
            ->where('attribute_id = ?', $attributeId)
            ->where('attribute_set_id = ?', $attributeSetId)
            ->limit(1);

        return (bool)$connection->fetchOne($select);
    }
}
