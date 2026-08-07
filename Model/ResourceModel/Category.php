<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\ResourceModel;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Model\Category as CategoryModel;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;

/**
 * Read-only category tree lookups for path resolution. Names are always
 * matched against the store-0 (admin) values — the same names the admin
 * category tree shows.
 */
class Category
{
    private const ENTITY_TABLE = 'catalog_category_entity';

    private ?string $linkField = null;

    /**
     * @var array<string, int> attribute_code => attribute_id
     */
    private array $attributeIdByCode = [];

    private ?bool $isAnchorDefault = null;

    /**
     * @var array<int, true>|null
     */
    private ?array $storeGroupRootIds = null;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly MetadataPool $metadataPool
    ) {
    }

    /**
     * Level-1 roots (children of the tree root): store-0 name => entity_id.
     * On duplicate names the lowest entity_id wins, deterministically.
     *
     * @return array<string, int>
     */
    public function getRootCategories(): array
    {
        return array_map(
            static fn (array $ids): int => $ids[0],
            $this->getRootCategoryIds()
        );
    }

    /**
     * Like {@see getRootCategories()} but keeps EVERY id per name, for the same
     * reason {@see getChildIdsByParentIds()} does: a read can take the lowest
     * entity_id, a write cannot — two roots sharing a name are two distinct
     * catalogs, and core enforces no uniqueness on root names.
     *
     * @return array<string, int[]> store-0 name => entity_id[], ascending
     */
    public function getRootCategoryIds(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $this->joinName(
            $connection->select()
                ->from(
                    ['e' => $this->resourceConnection->getTableName(self::ENTITY_TABLE)],
                    ['entity_id']
                )
                // Both predicates describe the same set. level carries an index
                // and parent_id does not, so the level condition is what keeps
                // this off a table scan; parent_id makes the intent exact.
                ->where('e.level = ?', 1)
                ->where('e.parent_id = ?', CategoryModel::TREE_ROOT_ID)
                ->order('e.entity_id ' . \Magento\Framework\DB\Select::SQL_ASC)
        );

        $roots = [];
        foreach ($connection->fetchAll($select) as $row) {
            $roots[(string)$row['name']][] = (int)$row['entity_id'];
        }

        return $roots;
    }

    /**
     * Direct children of the given parents with their store-0 names.
     * On duplicate sibling names the lowest entity_id wins.
     *
     * @param int[] $parentIds
     * @return array<int, array<string, int>> parent_id => [name => entity_id]
     */
    public function getChildrenByParentIds(array $parentIds): array
    {
        if (!$parentIds) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $this->joinName(
            $connection->select()
                ->from(
                    ['e' => $this->resourceConnection->getTableName(self::ENTITY_TABLE)],
                    ['entity_id', 'parent_id']
                )
                ->where('e.parent_id IN (?)', $parentIds)
                ->order('e.entity_id ' . \Magento\Framework\DB\Select::SQL_ASC)
        );

        $children = [];
        foreach ($connection->fetchAll($select) as $row) {
            $children[(int)$row['parent_id']][(string)$row['name']] ??= (int)$row['entity_id'];
        }

        return $children;
    }

    /**
     * Like {@see getChildrenByParentIds()} but keeps EVERY id per name instead
     * of collapsing duplicates.
     *
     * Resolving a path for a read (product links) can pick the lowest entity_id
     * and move on. A write cannot: two siblings sharing a name are two distinct
     * business objects, and silently updating whichever sorts first is worse
     * than refusing. Callers that write use this and treat a name with more
     * than one id as ambiguous.
     *
     * @param int[] $parentIds
     * @return array<int, array<string, int[]>> parent_id => [name => entity_id[]]
     */
    public function getChildIdsByParentIds(array $parentIds): array
    {
        if (!$parentIds) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $this->joinName(
            $connection->select()
                ->from(
                    ['e' => $this->resourceConnection->getTableName(self::ENTITY_TABLE)],
                    ['entity_id', 'parent_id']
                )
                ->where('e.parent_id IN (?)', $parentIds)
                ->order('e.entity_id ' . \Magento\Framework\DB\Select::SQL_ASC)
        );

        $children = [];
        foreach ($connection->fetchAll($select) as $row) {
            $children[(int)$row['parent_id']][(string)$row['name']][] = (int)$row['entity_id'];
        }

        return $children;
    }

    /**
     * Direct children of the given parents keyed by their store-0 `url_key`.
     *
     * The companion to {@see getChildIdsByParentIds()} for the other half of a
     * sibling collision: two siblings may differ in name and still generate the
     * same `url_path`, which `url_rewrite` refuses on its
     * (request_path, store_id) unique key.
     *
     * Store-0 only, which is the scope a structural write uses. A sibling whose
     * `url_key` exists solely as a store-view override is therefore not seen
     * here, and such a collision still surfaces the way it always did — as the
     * repository's own exception.
     *
     * @param int[] $parentIds
     * @return array<int, array<string, int[]>> parent_id => [url_key => entity_id[]]
     */
    public function getChildUrlKeysByParentIds(array $parentIds): array
    {
        if (!$parentIds) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $this->joinVarchar(
            $connection->select()
                ->from(
                    ['e' => $this->resourceConnection->getTableName(self::ENTITY_TABLE)],
                    ['entity_id', 'parent_id']
                )
                ->where('e.parent_id IN (?)', $parentIds)
                ->order('e.entity_id ' . \Magento\Framework\DB\Select::SQL_ASC),
            'url_key',
            'url_key'
        );

        $children = [];
        foreach ($connection->fetchAll($select) as $row) {
            $children[(int)$row['parent_id']][(string)$row['url_key']][] = (int)$row['entity_id'];
        }

        return $children;
    }

    /**
     * All descendants of the given categories, derived from the stored id-path.
     * Used for cache invalidation: a url_path or name change cascades down the
     * subtree, so the descendants' cached pages are stale too.
     *
     * @param int[] $categoryIds
     * @return int[] descendant IDs, excluding the given categories themselves
     */
    public function getDescendantIds(array $categoryIds): array
    {
        $rows = $this->getExistingByIds($categoryIds);
        if (!$rows) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $conditions = [];
        foreach ($this->topmostPaths($rows) as $path) {
            $conditions[] = $connection->quoteInto('path LIKE ?', $path . '/%');
        }

        $select = $connection->select()
            ->from($this->resourceConnection->getTableName(self::ENTITY_TABLE), ['entity_id'])
            ->where(implode(' OR ', $conditions));

        return array_map('intval', $connection->fetchCol($select));
    }

    /**
     * Drop paths already covered by a shallower one in the same set.
     *
     * `path` carries no index, so each LIKE is a scan of the category table;
     * syncing a subtree would otherwise contribute one redundant condition per
     * node, and the descendants of a descendant are matched by the ancestor's
     * pattern anyway.
     *
     * @param array<int, array{path: string, ...}> $rows
     * @return string[]
     */
    private function topmostPaths(array $rows): array
    {
        $paths = array_column($rows, 'path');
        // Shortest first, so a candidate is only ever tested against paths
        // that could actually contain it.
        usort($paths, static fn (string $a, string $b): int => strlen($a) <=> strlen($b));

        $topmost = [];
        foreach ($paths as $path) {
            foreach ($topmost as $ancestor) {
                if (str_starts_with($path, $ancestor . '/')) {
                    continue 2;
                }
            }
            $topmost[] = $path;
        }

        return $topmost;
    }

    /**
     * Existence and tree-position check, used to validate numeric payload
     * references and to re-verify categories created earlier in the request.
     *
     * @param int[] $categoryIds
     * @return array<int, array{entity_id: int, parent_id: int, level: int, path: string}>
     */
    public function getExistingByIds(array $categoryIds): array
    {
        if (!$categoryIds) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                $this->resourceConnection->getTableName(self::ENTITY_TABLE),
                ['entity_id', 'parent_id', 'level', 'path']
            )
            ->where('entity_id IN (?)', $categoryIds);

        $result = [];
        foreach ($connection->fetchAll($select) as $row) {
            $result[(int)$row['entity_id']] = [
                'entity_id' => (int)$row['entity_id'],
                'parent_id' => (int)$row['parent_id'],
                'level' => (int)$row['level'],
                'path' => (string)$row['path'],
            ];
        }

        return $result;
    }

    /**
     * Categories some store group has adopted as its root, as a lookup set.
     *
     * The tree-structural guard for moves and deletes: demoting or removing one
     * of these leaves a storefront pointing at a category that is no longer a
     * root, or at nothing at all. Core catches the delete case in
     * `Category::beforeDelete()` ("Can't delete root category.") but not the move
     * case, and a thrown exception mid-batch is a worse answer than a per-entry
     * refusal either way.
     *
     * Reads `store_group` directly rather than reusing
     * {@see StoreWebsiteMap::getRootCategoryId()}: that map is keyed by store view
     * and filtered to `store_id > 0`, so a store group with no store views — which
     * still pins its root — would be invisible here.
     *
     * @return array<int, true> entity_id => true
     */
    public function getStoreGroupRootCategoryIds(): array
    {
        if ($this->storeGroupRootIds !== null) {
            return $this->storeGroupRootIds;
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName('store_group'), ['root_category_id'])
            // 0 is the "not configured" sentinel (the column is NOT NULL
            // DEFAULT 0), not a category id.
            ->where('root_category_id > ?', 0);

        $ids = [];
        foreach ($connection->fetchCol($select) as $id) {
            $ids[(int)$id] = true;
        }

        return $this->storeGroupRootIds = $ids;
    }

    /**
     * Required category attributes with int backend and no default value —
     * typically third-party "required select" (yes/no) attributes that would
     * otherwise block programmatic category creation with an "attribute
     * value is empty" validation error.
     *
     * @return string[] attribute codes
     */
    public function getRequiredIntAttributesWithoutDefault(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                ['a' => $this->resourceConnection->getTableName('eav_attribute')],
                ['attribute_code']
            )
            ->join(
                ['t' => $this->resourceConnection->getTableName('eav_entity_type')],
                't.entity_type_id = a.entity_type_id',
                []
            )
            ->where('t.entity_type_code = ?', CategoryModel::ENTITY)
            ->where('a.is_required = 1')
            ->where('a.backend_type = ?', 'int')
            ->where("a.default_value IS NULL OR a.default_value = ''");

        return array_map('strval', $connection->fetchCol($select));
    }

    /**
     * Join the store-0 name value onto a catalog_category_entity select
     * aliased "e".
     */
    private function joinName(\Magento\Framework\DB\Select $select): \Magento\Framework\DB\Select
    {
        return $this->joinVarchar($select, 'name', 'name');
    }

    /**
     * Join a store-0 varchar attribute value onto a catalog_category_entity
     * select aliased "e", exposing it under $resultAlias.
     *
     * Inner join on purpose: a category with no store-0 row for the attribute has
     * no default-scope value, and every caller here is asking "what is the
     * default-scope value" rather than "does a value exist somewhere".
     */
    private function joinVarchar(
        \Magento\Framework\DB\Select $select,
        string $attributeCode,
        string $resultAlias
    ): \Magento\Framework\DB\Select {
        $linkField = $this->getLinkField();

        return $select->join(
            ['v' => $this->resourceConnection->getTableName(self::ENTITY_TABLE . '_varchar')],
            sprintf('v.%1$s = e.%1$s', $linkField)
            . ' AND v.attribute_id = ' . $this->getAttributeId($attributeCode)
            . ' AND v.store_id = 0',
            [$resultAlias => 'value']
        );
    }

    private function getLinkField(): string
    {
        if ($this->linkField === null) {
            $this->linkField = $this->metadataPool
                ->getMetadata(CategoryInterface::class)
                ->getLinkField();
        }

        return $this->linkField;
    }

    /**
     * Resolve a category attribute's ID by code (cached per request).
     */
    private function getAttributeId(string $code): int
    {
        if (!isset($this->attributeIdByCode[$code])) {
            $connection = $this->resourceConnection->getConnection();
            $select = $connection->select()
                ->from(
                    ['a' => $this->resourceConnection->getTableName('eav_attribute')],
                    ['attribute_id']
                )
                ->join(
                    ['t' => $this->resourceConnection->getTableName('eav_entity_type')],
                    't.entity_type_id = a.entity_type_id',
                    []
                )
                ->where('t.entity_type_code = ?', CategoryModel::ENTITY)
                ->where('a.attribute_code = ?', $code);

            $this->attributeIdByCode[$code] = (int)$connection->fetchOne($select);
        }

        return $this->attributeIdByCode[$code];
    }

    /**
     * Store-scoped category url_path values, with store-0 fallback. Joins
     * through the entity table so entity IDs map to the link field on EE.
     *
     * @param int[] $categoryIds
     * @return array<int, string> entity_id => url_path
     */
    public function getUrlPaths(array $categoryIds, int $storeId): array
    {
        if (!$categoryIds) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $linkField = $this->getLinkField();
        $select = $connection->select()
            ->from(
                ['e' => $this->resourceConnection->getTableName(self::ENTITY_TABLE)],
                ['entity_id']
            )
            ->join(
                ['v' => $this->resourceConnection->getTableName(self::ENTITY_TABLE . '_varchar')],
                sprintf('v.%1$s = e.%1$s', $linkField)
                . ' AND v.attribute_id = ' . $this->getAttributeId('url_path')
                . ' AND v.store_id IN (0, ' . (int)$storeId . ')',
                ['store_id', 'value']
            )
            ->where('e.entity_id IN (?)', $categoryIds);

        $store0 = [];
        $storeSpecific = [];
        foreach ($connection->fetchAll($select) as $row) {
            $entityId = (int)$row['entity_id'];
            if ((int)$row['store_id'] === 0) {
                $store0[$entityId] = (string)$row['value'];
            } else {
                $storeSpecific[$entityId] = (string)$row['value'];
            }
        }

        // Store-specific values override the store-0 defaults.
        return $storeSpecific + $store0;
    }

    /**
     * is_anchor flag per category (store-0 scope). Categories with no stored
     * row fall back to the attribute's default value, matching how a loaded
     * category model resolves the flag.
     *
     * @param int[] $categoryIds
     * @return array<int, bool> entity_id => is_anchor
     */
    public function getIsAnchor(array $categoryIds): array
    {
        if (!$categoryIds) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $linkField = $this->getLinkField();
        // LEFT join so categories relying on the attribute default (no stored
        // row) are still returned; COALESCE to the default value.
        $select = $connection->select()
            ->from(
                ['e' => $this->resourceConnection->getTableName(self::ENTITY_TABLE)],
                ['entity_id']
            )
            ->joinLeft(
                ['v' => $this->resourceConnection->getTableName(self::ENTITY_TABLE . '_int')],
                sprintf('v.%1$s = e.%1$s', $linkField)
                . ' AND v.attribute_id = ' . $this->getAttributeId('is_anchor')
                . ' AND v.store_id = 0',
                ['value']
            )
            ->where('e.entity_id IN (?)', $categoryIds);

        $default = $this->getIsAnchorDefault();
        $result = [];
        foreach ($connection->fetchAll($select) as $row) {
            $result[(int)$row['entity_id']] = $row['value'] !== null
                ? (bool)(int)$row['value']
                : $default;
        }

        return $result;
    }

    /**
     * Default value of the category is_anchor attribute (false when unset).
     */
    private function getIsAnchorDefault(): bool
    {
        if ($this->isAnchorDefault !== null) {
            return $this->isAnchorDefault;
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                ['a' => $this->resourceConnection->getTableName('eav_attribute')],
                ['default_value']
            )
            ->join(
                ['t' => $this->resourceConnection->getTableName('eav_entity_type')],
                't.entity_type_id = a.entity_type_id',
                []
            )
            ->where('t.entity_type_code = ?', CategoryModel::ENTITY)
            ->where('a.attribute_code = ?', 'is_anchor');

        return $this->isAnchorDefault = (bool)(int)$connection->fetchOne($select);
    }

    /**
     * Ancestor chain of each category, derived from its stored id-path
     * ("1/2/5/12"). Excludes the tree root (id 1) and the category itself.
     *
     * @param int[] $categoryIds
     * @return array<int, array{level: int, ancestors: int[]}>
     */
    public function getAncestry(array $categoryIds): array
    {
        $rows = $this->getExistingByIds($categoryIds);
        $result = [];
        foreach ($rows as $id => $row) {
            $ids = array_map('intval', explode('/', $row['path']));
            // Drop the tree root and the category itself.
            $ancestors = array_values(array_filter(
                $ids,
                static fn (int $ancestorId): bool => $ancestorId !== CategoryModel::TREE_ROOT_ID
                    && $ancestorId !== $id
            ));
            $result[$id] = ['level' => $row['level'], 'ancestors' => $ancestors];
        }

        return $result;
    }
}
