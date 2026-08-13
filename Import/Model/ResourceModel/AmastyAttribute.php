<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

/**
 * Low-level, version-tolerant writes to Amasty's layered-navigation tables.
 *
 * The module has no hard dependency on Amasty (soft dep): every write is guarded
 * by table existence and every column by live introspection, so an incoming
 * value that the installed Amasty version does not store is simply dropped —
 * never fatal to the base attribute sync. Table names differ between Amasty
 * releases, so each concern resolves the first existing candidate.
 *
 * Upserts use an explicit select-then-update/insert rather than
 * INSERT ... ON DUPLICATE KEY so we do not depend on a particular unique index
 * being present in a given Amasty version.
 */
class AmastyAttribute
{
    /** ILN per-attribute settings; natural key is attribute_code (unique). */
    private const FILTER_TABLE_CANDIDATES = [
        'amasty_amshopby_filter_setting',
        'amasty_amshopby_attribute',
    ];

    /** Per-option brand/landing data. */
    private const OPTION_SETTING_TABLE_CANDIDATES = [
        'amasty_amshopby_option_setting',
        'amasty_shopby_option_setting',
    ];

    /** @var array<string, string|null> candidate-group key => resolved table name (or null). */
    private array $resolvedTables = [];

    /** @var array<string, array<string, array<string, mixed>>> table => column name => describeTable meta. */
    private array $columnsByTable = [];

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * True when the ILN per-attribute settings table is present.
     */
    public function hasFilterTable(): bool
    {
        return $this->filterTable() !== null;
    }

    /**
     * True when the per-option brand-data table is present.
     */
    public function hasOptionSettingTable(): bool
    {
        return $this->optionSettingTable() !== null;
    }

    /**
     * Upsert the ILN per-attribute settings row. The natural key is
     * attribute_code; attribute_id is written too when the column exists. Only
     * columns present in the live table are written.
     *
     * @param array<string, string|int> $values friendly-mapped, real Amasty column names
     * @param string[] $dropped out-param collecting values dropped as unknown columns
     * @return bool whether a row was inserted or updated
     */
    public function upsertFilter(
        string $attributeCode,
        int $attributeId,
        array $values,
        array &$dropped = []
    ): bool {
        $table = $this->filterTable();
        if ($table === null) {
            return false;
        }

        $columns = $this->columns($table);
        if (!isset($columns['attribute_code'])) {
            return false;
        }
        if (isset($columns['attribute_id'])) {
            $values['attribute_id'] = $attributeId;
        }

        $known = $this->filterKnown($table, $values, $dropped);
        if ($known === []) {
            return false;
        }

        return $this->upsert($table, ['attribute_code' => $attributeCode], $known);
    }

    /**
     * Upsert one per-option brand-data row.
     *
     * @param array<string, string|int> $values friendly-mapped, real Amasty column names
     * @param string[] $dropped out-param collecting values dropped as unknown columns
     * @return bool whether a row was inserted or updated
     */
    public function upsertOptionSetting(
        string $attributeCode,
        int $optionId,
        int $storeId,
        array $values,
        array &$dropped = []
    ): bool {
        $table = $this->optionSettingTable();
        if ($table === null) {
            return false;
        }

        $columns = $this->columns($table);
        // The option key column changed names across Amasty versions.
        $optionKey = isset($columns['option_id']) ? 'option_id'
            : (isset($columns['value']) ? 'value' : null);
        if ($optionKey === null || !isset($columns['attribute_code'])) {
            return false;
        }

        $key = [
            'attribute_code' => $attributeCode,
            $optionKey => $optionId,
        ];
        if (isset($columns['store_id'])) {
            $key['store_id'] = $storeId;
        }

        $known = $this->filterKnown($table, $values, $dropped);
        if ($known === []) {
            return false;
        }

        return $this->upsert($table, $key, $known);
    }

    /**
     * Keep only $values whose keys are real columns of $table; record the rest
     * in $dropped. Key columns are excluded here (handled by the caller).
     *
     * @param array<string, string|int> $values
     * @param string[] $dropped
     * @return array<string, string|int>
     */
    private function filterKnown(string $table, array $values, array &$dropped): array
    {
        $columns = $this->columns($table);
        $known = [];
        foreach ($values as $column => $value) {
            if ($value === null) {
                continue;
            }
            if (isset($columns[$column])) {
                $known[$column] = $value;
            } else {
                $dropped[] = $column;
            }
        }

        return $known;
    }

    /**
     * Select-then-update/insert against $key. Returns true when a change was made.
     * A fresh insert also seeds NOT-NULL/no-default columns Amasty declares
     * (e.g. filter_code, tooltip) so a partial write does not violate the schema.
     *
     * @param array<string, string|int> $key
     * @param array<string, string|int> $data
     */
    private function upsert(string $table, array $key, array $data): bool
    {
        $connection = $this->resourceConnection->getConnection();

        $select = $connection->select()->from($table, [])->columns(new \Zend_Db_Expr('1'))->limit(1);
        foreach ($key as $column => $value) {
            $select->where($connection->quoteIdentifier($column) . ' = ?', $value);
        }
        $exists = (bool)$connection->fetchOne($select);

        if ($exists) {
            $where = [];
            foreach ($key as $column => $value) {
                $where[$connection->quoteIdentifier($column) . ' = ?'] = $value;
            }
            $connection->update($table, $data, $where);

            return true;
        }

        $row = $key + $data;
        $connection->insert($table, $row + $this->requiredInsertDefaults($table, array_keys($row)));

        return true;
    }

    /**
     * Type-appropriate empty values for every NOT-NULL, no-default, non-identity
     * column of $table that the caller did not provide — so a sparse insert into
     * an Amasty table with mandatory columns still succeeds.
     *
     * @param string[] $provided already-set column names
     * @return array<string, string|int>
     */
    private function requiredInsertDefaults(string $table, array $provided): array
    {
        $providedSet = array_flip($provided);
        $defaults = [];
        foreach ($this->columns($table) as $column => $meta) {
            if (isset($providedSet[$column])) {
                continue;
            }
            if (!empty($meta['IDENTITY']) || !empty($meta['PRIMARY'])) {
                continue;
            }
            if (($meta['NULLABLE'] ?? true) || $meta['DEFAULT'] !== null) {
                continue;
            }
            $defaults[$column] = $this->isNumericType((string)($meta['DATA_TYPE'] ?? '')) ? 0 : '';
        }

        return $defaults;
    }

    private function isNumericType(string $dataType): bool
    {
        foreach (['int', 'decimal', 'float', 'double', 'numeric', 'bit', 'bool'] as $needle) {
            if (str_contains($dataType, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string|null the first existing ILN attribute table, or null
     */
    private function filterTable(): ?string
    {
        return $this->resolveTable('filter', self::FILTER_TABLE_CANDIDATES);
    }

    /**
     * @return string|null the first existing option-setting table, or null
     */
    private function optionSettingTable(): ?string
    {
        return $this->resolveTable('option_setting', self::OPTION_SETTING_TABLE_CANDIDATES);
    }

    /**
     * @param string[] $candidates
     */
    private function resolveTable(string $group, array $candidates): ?string
    {
        if (array_key_exists($group, $this->resolvedTables)) {
            return $this->resolvedTables[$group];
        }

        $connection = $this->resourceConnection->getConnection();
        foreach ($candidates as $candidate) {
            $table = $this->resourceConnection->getTableName($candidate);
            if ($connection->isTableExists($table)) {
                return $this->resolvedTables[$group] = $table;
            }
        }

        return $this->resolvedTables[$group] = null;
    }

    /**
     * @return array<string, array<string, mixed>> column name => describeTable meta
     */
    private function columns(string $table): array
    {
        if (isset($this->columnsByTable[$table])) {
            return $this->columnsByTable[$table];
        }

        $connection = $this->resourceConnection->getConnection();

        return $this->columnsByTable[$table] = $connection->describeTable($table);
    }
}
