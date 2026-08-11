<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

/**
 * Select/multiselect option lookup and bulk creation, with a
 * request-scoped label => option_id cache per attribute.
 */
class AttributeOption
{
    /**
     * @var array<int, array<string, int>> attribute_id => [lowercased label => option_id]
     */
    private array $optionsByAttribute = [];

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Pre-load all admin-scope options of the given attributes.
     *
     * @param int[] $attributeIds
     */
    public function warm(array $attributeIds): void
    {
        $missing = array_diff($attributeIds, array_keys($this->optionsByAttribute));
        if (!$missing) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                ['o' => $this->resourceConnection->getTableName('eav_attribute_option')],
                ['attribute_id', 'option_id']
            )
            ->join(
                ['ov' => $this->resourceConnection->getTableName('eav_attribute_option_value')],
                'ov.option_id = o.option_id AND ov.store_id = 0',
                ['value']
            )
            ->where('o.attribute_id IN (?)', array_values($missing));

        foreach ($missing as $attributeId) {
            $this->optionsByAttribute[$attributeId] = [];
        }
        foreach ($connection->fetchAll($select) as $row) {
            $this->optionsByAttribute[(int)$row['attribute_id']][mb_strtolower((string)$row['value'])]
                = (int)$row['option_id'];
        }
    }

    /**
     * Drop the memo, so the next lookup reads the table again.
     *
     * Called when the caller has just (re-)acquired
     * {@see \ReadyData\Import\Model\ImportLocks::ATTRIBUTE_OPTIONS} and the memo
     * predates it: an option another request created in the meantime is not in
     * here, and {@see createOptions()} trusts this map to decide what is missing,
     * so a stale entry is how a duplicate label gets written under a held lock.
     */
    public function forget(): void
    {
        $this->optionsByAttribute = [];
    }

    public function getOptionId(int $attributeId, string $label): ?int
    {
        $this->warm([$attributeId]);

        return $this->optionsByAttribute[$attributeId][mb_strtolower($label)] ?? null;
    }

    /**
     * Create missing admin-scope options and return their IDs.
     *
     * @param string[] $labels
     * @return array<string, int> lowercased label => option_id
     */
    public function createOptions(int $attributeId, array $labels): array
    {
        $connection = $this->resourceConnection->getConnection();
        $optionTable = $this->resourceConnection->getTableName('eav_attribute_option');
        $valueTable = $this->resourceConnection->getTableName('eav_attribute_option_value');

        $created = [];
        foreach (array_unique($labels) as $label) {
            if ($this->getOptionId($attributeId, $label) !== null) {
                continue;
            }
            // Options need their generated ID for the value row, so these
            // inserts are per-option; new-option volume is expected to be low.
            $connection->insert($optionTable, ['attribute_id' => $attributeId, 'sort_order' => 0]);
            $optionId = (int)$connection->lastInsertId($optionTable);
            $connection->insert($valueTable, ['option_id' => $optionId, 'store_id' => 0, 'value' => $label]);
            $this->optionsByAttribute[$attributeId][mb_strtolower($label)] = $optionId;
            $created[mb_strtolower($label)] = $optionId;
        }

        return $created;
    }
}
