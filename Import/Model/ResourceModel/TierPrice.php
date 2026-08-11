<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\ResourceModel;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;

/**
 * Direct reads/writes on catalog_product_entity_tier_price.
 *
 * The product column is named entity_id but holds the EAV LINK FIELD
 * (entity_id on CE, row_id on EE) — core derives it the same way, from the
 * primary key of catalog_product_entity
 * (Magento\Catalog\Model\ResourceModel\Product\Attribute\Backend\GroupPrice\AbstractGroupPrice::getProductIdFieldName),
 * so resolve it through ProductEntity::getLinkField() and never hard-code it.
 *
 * Unlike the media gallery, this table has a real natural key — the unique
 * (link field, all_groups, customer_group_id, qty, website_id) — so a plain
 * insertOnDuplicate keeps each row's value_id and there is nothing to read back.
 *
 * Row shape, matching core's own writers
 * (Magento\Catalog\Model\Product\Price\TierPriceFactory::createSkeleton):
 *  - absolute price:      value = <price>, percentage_value = NULL
 *  - percentage discount: value = 0,       percentage_value = <percentage>
 * `value` is NOT NULL in the schema, so a percentage row stores 0 rather than
 * NULL — and 0 is also what makes the legacy indexer's IF(value, ...) branch
 * take the percentage path.
 */
class TierPrice
{
    public const ALL_GROUPS = 1;
    public const SPECIFIC_GROUP = 0;
    public const ALL_WEBSITES = 0;

    /**
     * Core's own switch (Magento\Catalog\Helper\Data::XML_PATH_PRICE_SCOPE):
     * 0 = global, 1 = website. Mirrors core.
     */
    private const XML_PATH_PRICE_SCOPE = 'catalog/price/scope';

    private const TABLE = 'catalog_product_entity_tier_price';
    private const CHUNK = 1000;

    /**
     * Column scales from Magento's db_schema.xml. Every comparison and every
     * written value goes through them: qty decimal(12,4), value decimal(20,6),
     * percentage_value decimal(5,2). Diffing on unscaled floats would make
     * "1" look different from the stored "1.0000" and churn every row on
     * every re-import.
     */
    private const QTY_SCALE = 4;
    private const VALUE_SCALE = 6;
    private const PERCENTAGE_SCALE = 2;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly ProductEntity $productEntity,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Whether Catalog Price Scope is Global, in which case website_id = 0 is
     * the only legal value. Read from configuration rather than from the
     * tier_price attribute's is_global: that column is installed as
     * SCOPE_WEBSITE and is never switched by core's price-scope observer, which
     * only touches attributes whose frontend_input is "price" (tier_price's is
     * "text"). Core's own tier-price API validates against this config path.
     */
    public function isPriceScopeGlobal(): bool
    {
        return !$this->scopeConfig->isSetFlag(self::XML_PATH_PRICE_SCOPE);
    }

    /**
     * Existing tier prices of the given products, keyed by the identity tuple
     * so a payload row can be looked up directly. The unique key guarantees at
     * most one row per key, so there are no duplicates to reconcile.
     *
     * @param int[] $linkIds link field values
     * @return array<int, array<string, array{value_id: int, value: string, percentage_value: string|null}>>
     *         link id => tuple key => row
     */
    public function getPrices(array $linkIds): array
    {
        if (!$linkIds) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $linkField = $this->productEntity->getLinkField();
        $select = $connection->select()
            ->from(
                $this->resourceConnection->getTableName(self::TABLE),
                [
                    'link_id' => $linkField,
                    'value_id',
                    'all_groups',
                    'customer_group_id',
                    'qty',
                    'value',
                    'website_id',
                    'percentage_value',
                ]
            )
            ->where($linkField . ' IN (?)', $linkIds);

        $prices = [];
        foreach ($connection->fetchAll($select) as $row) {
            $key = self::buildKey(
                (int)$row['website_id'],
                (int)$row['all_groups'],
                (int)$row['customer_group_id'],
                self::scaleQty((float)$row['qty'])
            );
            $prices[(int)$row['link_id']][$key] = [
                'value_id' => (int)$row['value_id'],
                'value' => self::scaleValue((float)$row['value']),
                'percentage_value' => $row['percentage_value'] === null
                    ? null
                    : self::scalePercentage((float)$row['percentage_value']),
            ];
        }

        return $prices;
    }

    /**
     * Multi-row upsert conflicting on the unique
     * (link field, all_groups, customer_group_id, qty, website_id) key, which
     * means an existing row keeps its value_id and only its price changes.
     * value_id must never be supplied.
     *
     * Callers pass the product as "link_id"; it is mapped onto the real column
     * here so processors stay free of column names. Every row carries the same
     * keys — insertOnDuplicate derives its column list from the first row and
     * throws on any row missing one of them.
     *
     * @param array<int, array{link_id: int, all_groups: int, customer_group_id: int,
     *      qty: string, value: string, website_id: int, percentage_value: string|null}> $rows
     */
    public function savePrices(array $rows): void
    {
        if (!$rows) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);
        $linkField = $this->productEntity->getLinkField();

        $priceRows = array_map(
            static fn (array $row): array => [
                $linkField => $row['link_id'],
                'all_groups' => $row['all_groups'],
                'customer_group_id' => $row['customer_group_id'],
                'qty' => $row['qty'],
                'value' => $row['value'],
                'website_id' => $row['website_id'],
                'percentage_value' => $row['percentage_value'],
            ],
            $rows
        );
        foreach (array_chunk($priceRows, self::CHUNK) as $chunk) {
            $connection->insertOnDuplicate($table, $chunk, ['value', 'percentage_value']);
        }
    }

    /**
     * Delete rows by primary key. The value_ids come from getPrices() on the
     * same connection inside the same batch transaction, so this is exact —
     * and no foreign key points INTO this table, so nothing cascades. Deleting
     * by tuple instead would have to put the DECIMAL qty into a SQL predicate,
     * which is precisely the float-comparison fragility scaleQty() exists to
     * remove.
     *
     * @param int[] $valueIds
     */
    public function deletePrices(array $valueIds): void
    {
        if (!$valueIds) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);
        foreach (array_chunk($valueIds, self::CHUNK) as $chunk) {
            $connection->delete($table, ['value_id IN (?)' => $chunk]);
        }
    }

    /**
     * The row's identity, as the DB's unique key defines it. all_groups is part
     * of it because an "all groups" row and a "NOT LOGGED IN" row both store
     * customer_group_id = 0 and must stay distinguishable.
     *
     * @param string $qty already scaled via scaleQty()
     */
    public static function buildKey(int $websiteId, int $allGroups, int $customerGroupId, string $qty): string
    {
        return $websiteId . '-' . $allGroups . '-' . $customerGroupId . '-' . $qty;
    }

    public static function scaleQty(float $qty): string
    {
        return number_format($qty, self::QTY_SCALE, '.', '');
    }

    public static function scaleValue(float $value): string
    {
        return number_format($value, self::VALUE_SCALE, '.', '');
    }

    public static function scalePercentage(float $percentage): string
    {
        return number_format($percentage, self::PERCENTAGE_SCALE, '.', '');
    }
}
