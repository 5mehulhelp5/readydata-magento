<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Cache;

use Magento\Framework\App\ResourceConnection;

/**
 * Request-scoped cache of the customer_group table, read straight from the DB
 * to avoid group model / repository overhead.
 *
 * Note that group 0 is a real group ("NOT LOGGED IN", guests) and NOT a
 * "no group" sentinel — "every group" is expressed by
 * catalog_product_entity_tier_price.all_groups = 1, never by a group ID.
 * Magento's in-memory CUST_GROUP_ALL (32000) has no customer_group row, so it
 * must never reach a foreign key.
 */
class CustomerGroupMap
{
    /**
     * @var array<string, int>|null lower-cased code => ID
     */
    private ?array $groupIdByCode = null;

    /**
     * @var array<int, bool>|null known IDs
     */
    private ?array $knownIds = null;

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Resolve a payload reference — a group code or a numeric group ID — to an
     * existing customer_group_id. Codes are matched case-insensitively and
     * trimmed, mirroring Magento's own tier-price API
     * (Magento\Catalog\Model\Product\Price\TierPriceFactory lower-cases the
     * code before looking it up).
     *
     * A digits-only reference is always read as an ID, so a group whose code is
     * digits-only can only be referenced by its ID.
     *
     * @return int|null null when the group does not exist
     */
    public function getGroupId(string $reference): ?int
    {
        $reference = trim($reference);
        if ($reference === '') {
            return null;
        }

        if (ctype_digit($reference)) {
            $id = (int)$reference;

            return isset($this->getKnownIds()[$id]) ? $id : null;
        }

        return $this->getGroupMap()[mb_strtolower($reference)] ?? null;
    }

    /**
     * @return array<string, int> lower-cased customer_group_code => ID
     */
    public function getGroupMap(): array
    {
        if ($this->groupIdByCode === null) {
            $connection = $this->resourceConnection->getConnection();
            $select = $connection->select()
                ->from(
                    $this->resourceConnection->getTableName('customer_group'),
                    ['customer_group_id', 'customer_group_code']
                )
                // customer_group_code carries only a non-unique index, so
                // duplicates are physically possible. Lowest ID wins,
                // deterministically (the same posture as duplicate sibling
                // category names).
                ->order('customer_group_id ASC');

            $this->groupIdByCode = [];
            $this->knownIds = [];
            foreach ($connection->fetchAll($select) as $row) {
                $id = (int)$row['customer_group_id'];
                $this->knownIds[$id] = true;
                $this->groupIdByCode[mb_strtolower(trim((string)$row['customer_group_code']))] ??= $id;
            }
        }

        return $this->groupIdByCode;
    }

    /**
     * @return array<int, bool>
     */
    private function getKnownIds(): array
    {
        if ($this->knownIds === null) {
            $this->getGroupMap();
        }

        return $this->knownIds ?? [];
    }
}
