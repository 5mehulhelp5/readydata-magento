<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Category;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Category as CategoryModel;
use Magento\Catalog\Model\CategoryFactory;
use Magento\Store\Model\StoreManagerInterface;
use ReadyData\Import\Api\Data\CategoryDefinitionInterface;
use ReadyData\Import\Api\Data\CategoryValuesInterface;
use ReadyData\Import\Model\ResourceModel\Category as CategoryResource;
use ReadyData\Import\Model\ResourceModel\UrlRewrite as UrlRewriteResource;

/**
 * Writes one category through the category repository. The single place in the
 * module that creates, updates, moves or deletes a category —
 * CategoryPathResolver's on-demand subtree creation comes through
 * {@see createBare()} rather than building its own model, so a category the
 * product import created and one the category endpoint created cannot drift
 * apart.
 *
 * This is the module's deliberate exception to the direct-SQL rule: a category
 * write has to maintain path/level/children_count, derive url_key/url_path, and
 * cascade URL rewrites across the whole descendant subtree. Reimplementing that
 * in SQL would be the riskiest code in the module, and category cardinality is
 * low enough that the bulk-write argument does not apply. The same reasoning
 * applies doubly to {@see move()} and {@see delete()}, which re-path or remove an
 * entire subtree and its rewrites.
 *
 * Three facts about Magento's category repository drive the shape of everything
 * below, and none of them are obvious:
 *
 * 1. CategoryRepository::save() reads the target store from StoreManagerInterface
 *    and overwrites whatever setStoreId() said. Every write therefore runs
 *    inside explicit store emulation. (Without it, a call to /rest/V1/... rather
 *    than /rest/all/V1/... would write the "default scope" values to store 1.)
 *
 * 2. For an existing ID, save() DISCARDS the object it was handed and re-fetches
 *    via get($id, $storeId). Mutations survive only because get() memoizes its
 *    instances, so the re-fetched object is the same one we mutated. That is
 *    what makes both the "mutate the loaded model" path and the "sparse object"
 *    path below work.
 *
 * 3. save() builds its data from the object's INTERFACE projection plus its
 *    custom attributes, and drops nulls on the way. So a value to set can ride
 *    a sparse object, but a value to CLEAR has to be nulled on the memoized
 *    loaded instance instead — a null never survives the projection.
 *
 * At store scope the object handed to save() must stay sparse. A fully loaded
 * one projects every attribute into the save payload, which materializes a
 * store-view override row for every scoped attribute rather than only the ones
 * the caller asked for.
 */
class CategoryWriter
{
    /**
     * @var string[]|null
     */
    private ?array $requiredIntAttributes = null;

    public function __construct(
        private readonly CategoryFactory $categoryFactory,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly CategoryResource $categoryResource,
        private readonly UrlRewriteResource $urlRewriteResource
    ) {
    }

    /**
     * Create a category under an existing parent, at default scope.
     *
     * @param string[] $messages collected warnings, by reference
     */
    public function create(
        int $parentId,
        string $name,
        CategoryDefinitionInterface $definition,
        array &$messages
    ): int {
        return $this->createAtDefaultScope(
            $parentId,
            $name,
            $this->collectDesired($definition, $name, $definition->getPosition(), $messages)
        );
    }

    /**
     * Create a category carrying nothing but its name and the module's
     * defaults. Used by CategoryPathResolver when a product import references a
     * path that does not exist yet and the caller supplied no properties for it.
     *
     * Shares {@see createAtDefaultScope()} with {@see create()} on purpose: an
     * auto-created category and an endpoint-created one must be
     * indistinguishable, and two copies of the defaults would drift.
     */
    public function createBare(int $parentId, string $name): int
    {
        return $this->createAtDefaultScope($parentId, $name, []);
    }

    /**
     * @param array<string, mixed> $values attribute values to set on top of the defaults
     */
    private function createAtDefaultScope(int $parentId, string $name, array $values): int
    {
        return $this->withStore(0, function () use ($parentId, $name, $values): int {
            $category = $this->categoryFactory->create();
            $category->setStoreId(0);
            $category->setParentId($parentId);
            $category->setName($name);
            $category->setIsActive(true);
            $category->setData('include_in_menu', 1);
            $category->addData($values);
            if (!isset($values['url_key'])) {
                $category->setData('url_key', $category->formatUrlKey($name));
            }
            // Required yes/no attributes with no default would fail validation
            // on save; fill them with "No".
            foreach ($this->getRequiredIntAttributes() as $code) {
                if ($category->getData($code) === null) {
                    $category->setData($code, 0);
                }
            }

            return (int)$this->categoryRepository->save($category)->getId();
        });
    }

    /**
     * Bring an existing category in line with the definition. Returns false —
     * without saving at all — when nothing differs, which is what keeps a
     * replayed payload from re-running observers, URL rewrite regeneration and
     * reindexing for every category in the feed.
     *
     * @param string|null $name desired name, or null to leave it alone
     * @param string[] $messages collected warnings, by reference
     */
    public function update(
        int $entityId,
        ?string $name,
        CategoryValuesInterface $values,
        ?int $position,
        int $storeId,
        array &$messages
    ): bool {
        $desired = $this->collectDesired($values, $name, $position, $messages);
        $clears = $this->collectClears($values);

        return $this->withStore($storeId, function () use ($entityId, $storeId, $desired, $clears): bool {
            $loaded = $this->categoryRepository->get($entityId, $storeId);
            $desired = $this->deriveUrlKeyOnRename($loaded, $desired);

            $diff = [];
            foreach ($desired as $code => $value) {
                if (!$this->isSameValue($loaded->getData($code), $value)) {
                    $diff[$code] = $value;
                }
            }
            foreach ($clears as $code) {
                if ($this->hasValueToClear($loaded, $code, $storeId)) {
                    $diff[$code] = null;
                }
            }

            if (!$diff) {
                return false;
            }

            $this->save($loaded, $entityId, $storeId, $diff);

            return true;
        });
    }

    /**
     * A rename with no explicit url_key would keep the old slug forever: Magento
     * only derives a url_key when the stored one is empty. Deriving it here is
     * what makes the rewrite cascade fire.
     *
     * (Not special-cased for a level-1 root, where core skips rewrite generation
     * entirely — a root's url_key is part of no storefront URL. The derivation is
     * still right there: the root keeps a slug that matches its name for the day
     * a store group points at it.)
     *
     * Shared with {@see findSiblingConflict()}, which has to predict exactly the
     * slug this would produce.
     *
     * @param array<string, mixed> $desired
     * @return array<string, mixed>
     */
    private function deriveUrlKeyOnRename(CategoryModel $loaded, array $desired): array
    {
        if (isset($desired['name'])
            && !isset($desired['url_key'])
            && (string)$loaded->getName() !== (string)$desired['name']
        ) {
            $desired['url_key'] = $loaded->formatUrlKey((string)$desired['name']);
        }

        return $desired;
    }

    /**
     * Whether another category under $parentId already carries the name or the
     * url_key this one would end up with.
     *
     * Two different failure modes, neither of which the write itself reports
     * usefully:
     *
     * - **name**: `catalog_category_entity` has no unique key on
     *   (parent_id, name), so the write simply succeeds and leaves the path
     *   permanently ambiguous — refused by every later write to it, and resolved
     *   to the lowest entity_id by the product import.
     * - **url_key**: `url_rewrite` IS unique on (request_path, store_id), but the
     *   violation only surfaces from deep inside the save as
     *   `UrlAlreadyExistsException`, after a nested rollback, with no indication
     *   of which category it collided with.
     *
     * Evaluated at **default scope**, which is the scope every structural write
     * uses. Callers must not rely on it for store-scoped writes: a store-view
     * `url_key` override can still collide, and that case keeps its old
     * behaviour.
     *
     * @param string|null $name the name the entry sets, or null to keep the stored one
     * @param bool $moved whether the category is arriving under $parentId from
     *        somewhere else — a move collides on the name it already has, so this
     *        cannot be inferred from the payload
     * @return array{kind: string, value: string, category_id: int}|null
     */
    public function findSiblingConflict(
        int $entityId,
        int $parentId,
        ?string $name,
        CategoryDefinitionInterface $definition,
        bool $moved
    ): ?array {
        $ignoredMessages = [];
        $desired = $this->collectDesired($definition, $name, null, $ignoredMessages);

        return $this->withStore(0, function () use ($entityId, $parentId, $desired, $moved): ?array {
            $loaded = $this->categoryRepository->get($entityId, 0);
            $desired = $this->deriveUrlKeyOnRename($loaded, $desired);

            $storedName = (string)$loaded->getName();
            $storedUrlKey = (string)$loaded->getData('url_key');
            $finalName = (string)($desired['name'] ?? $storedName);
            $finalUrlKey = (string)($desired['url_key'] ?? $storedUrlKey);

            // Standing still cannot create a new collision: whatever the category
            // already carries, it already carries it where it is. Checked before
            // the queries so a replayed payload stays free.
            if (!$moved && $finalName === $storedName && $finalUrlKey === $storedUrlKey) {
                return null;
            }

            $namesake = self::firstOther(
                $this->categoryResource->getChildIdsByParentIds([$parentId])[$parentId][$finalName] ?? [],
                $entityId
            );
            if ($namesake !== null) {
                return ['kind' => 'name', 'value' => $finalName, 'category_id' => $namesake];
            }

            if ($finalUrlKey === '') {
                return null;
            }
            $slugTwin = self::firstOther(
                $this->categoryResource->getChildUrlKeysByParentIds([$parentId])[$parentId][$finalUrlKey] ?? [],
                $entityId
            );
            if ($slugTwin !== null) {
                return ['kind' => 'url_key', 'value' => $finalUrlKey, 'category_id' => $slugTwin];
            }

            return null;
        });
    }

    /**
     * Whether a category that does not exist yet would collide with one of its
     * prospective siblings.
     *
     * Only `url_key` can collide here. A **name** collision is impossible by
     * construction: the caller reached the create branch precisely because no
     * sibling carries this name at store 0 (one that did would have been updated
     * instead, and several would have been `ambiguous_path`). The slug is a
     * different matter — it is derived from the name, so two differently named
     * siblings can easily want the same one, and that is what `url_rewrite`
     * refuses from deep inside the save with no indication of the other category.
     *
     * @param string $name the name the category will be created with
     * @param CategoryDefinitionInterface|null $definition the definition the
     *        category will be created from, for its optional explicit `url_key`.
     *        Null for {@see createBare()}'s callers — the product import's
     *        on-demand subtree creation — which carry no definition at all and
     *        always get the slug derived from the name.
     * @return array{kind: string, value: string, category_id: int}|null
     */
    public function findNewChildConflict(
        int $parentId,
        string $name,
        ?CategoryDefinitionInterface $definition = null
    ): ?array {
        $ignoredMessages = [];
        $desired = $definition !== null
            ? $this->collectDesired($definition, $name, null, $ignoredMessages)
            : [];

        // Inside the same store emulation the create itself runs in, so the slug
        // predicted here is byte-for-byte the one that would be written.
        return $this->withStore(0, function () use ($parentId, $name, $desired): ?array {
            $urlKey = isset($desired['url_key'])
                ? (string)$desired['url_key']
                : (string)$this->categoryFactory->create()->formatUrlKey($name);

            if ($urlKey === '') {
                return null;
            }

            $twin = self::firstOther(
                $this->categoryResource->getChildUrlKeysByParentIds([$parentId])[$parentId][$urlKey] ?? [],
                // Nothing to exclude: the category has no id yet.
                null
            );

            return $twin !== null
                ? ['kind' => 'url_key', 'value' => $urlKey, 'category_id' => $twin]
                : null;
        });
    }

    /**
     * The first id that is not the category being written. A category always
     * matches its own name and slug, and a rename that only changes case or
     * whitespace must not report itself as its own conflict. A null $entityId
     * excludes nothing, for a category that does not exist yet.
     *
     * @param int[] $ids
     */
    private static function firstOther(array $ids, ?int $entityId): ?int
    {
        foreach ($ids as $id) {
            if ($entityId === null || (int)$id !== $entityId) {
                return (int)$id;
            }
        }

        return null;
    }

    /**
     * Reparent a category, taking its whole descendant subtree with it.
     *
     * `CategoryModel::move()` runs its own transaction around
     * `changeParent()`'s relative `children_count` updates and the
     * `REPLACE(path, …)` subtree re-path. Nested inside the caller's per-category
     * transaction that makes the whole move atomic — the guarantee whose absence
     * was the original reason this endpoint refused moves.
     *
     * Two things here are load-bearing and easy to lose:
     *
     * 1. The category MUST come from the repository, not a bare model with an ID.
     *    `changeParent()` finishes with `addData(['parent_id' => …])`, so only a
     *    model carrying loaded orig data reports `dataHasChangedFor('parent_id')`
     *    — the exact condition `CategoryProcessUrlRewriteMovingObserver` gates on.
     *    Skip it and the move silently regenerates no URL rewrites at all.
     * 2. `$afterCategoryId` must NOT be null. `_processPositions()` reads a null
     *    as position 1, which lands the moved category FIRST under its new parent
     *    and shifts every existing sibling up. Passing the last child appends
     *    instead, which is what the admin does and what a caller who did not ask
     *    to reorder anything expects.
     *
     * We do not call `CategoryManagement::move()`, which computes the same
     * `$afterId` but then collapses every failure into an unusable "Could not
     * move category" with the real cause discarded.
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function move(int $entityId, int $newParentId): void
    {
        $this->withStore(0, function () use ($entityId, $newParentId): void {
            $category = $this->categoryRepository->get($entityId, 0);
            $newParent = $this->categoryRepository->get($newParentId, 0);

            $category->move($newParentId, $this->lastChildIdOf($newParent));
        });
    }

    /**
     * The last of a parent's children in position order, or null when it has
     * none. `getChildren()` is a position-ordered comma-separated list, which is
     * where `CategoryManagement::move()` takes the same value from.
     */
    private function lastChildIdOf(CategoryModel $parent): ?int
    {
        if (!$parent->hasChildren()) {
            return null;
        }

        $children = $parent->getChildren();
        $childIds = $children !== null && $children !== ''
            ? explode(',', (string)$children)
            : [];
        $lastId = array_pop($childIds);

        return $lastId !== null ? (int)$lastId : null;
    }

    /**
     * Remove a category, its whole descendant subtree, and their product
     * assignments. Products themselves survive.
     *
     * Deliberately thin: core's `_beforeDelete()` recurses into children, the
     * `CatalogUrlRewrite` `Remove` plugin drops the URL rewrites of the category
     * and every child, and `catalog_category_product` rows go with the foreign
     * key. Whether the subtree *should* go is the caller's decision, enforced one
     * level up — by the time we are here it has been made.
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\StateException
     */
    public function delete(int $entityId): void
    {
        $this->withStore(0, function () use ($entityId): void {
            $this->categoryRepository->deleteByIdentifier($entityId);
        });
    }

    /**
     * @param array<string, mixed> $diff values to set; a null entry means "clear"
     */
    private function save(CategoryModel $loaded, int $entityId, int $storeId, array $diff): void
    {
        // Nulls never survive the repository's interface projection, so a clear
        // has to be applied to the memoized instance that save() will re-fetch.
        foreach ($diff as $code => $value) {
            if ($value === null) {
                $loaded->setData($code, null);
            }
        }
        $values = array_filter($diff, static fn ($value): bool => $value !== null);

        if ($storeId === 0) {
            $target = $loaded;
            $target->addData($values);
        } else {
            // Sparse on purpose — see the class docblock. save() swaps in the
            // memoized $loaded for the observers, so nothing here is missing
            // the context (path, orig data, children) they need.
            $target = $this->categoryFactory->create();
            $target->setId($entityId);
            $target->addData($values);
        }
        $target->setStoreId($storeId);

        if (isset($values['url_key'])) {
            // Keep the old URL working. Products get 301s from
            // UrlRewriteProcessor; without this categories would just 404,
            // because the flag is otherwise only set by the admin controller.
            $target->setData('save_rewrites_history', $this->urlRewriteResource->isSaveRewritesHistory($storeId));
        }

        $this->categoryRepository->save($target);
    }

    /**
     * Values the definition asks for, keyed by attribute code. Absent fields are
     * omitted rather than nulled: omitted means "leave alone", and only
     * clear_attributes means "remove".
     *
     * @param string[] $messages
     * @return array<string, mixed>
     */
    private function collectDesired(
        CategoryValuesInterface $values,
        ?string $name,
        ?int $position,
        array &$messages
    ): array {
        $desired = [];

        if ($name !== null && $name !== '') {
            $desired['name'] = $name;
        }
        $urlKey = $values->getUrlKey();
        if ($urlKey !== null && trim($urlKey) !== '') {
            $desired['url_key'] = trim($urlKey);
        }
        foreach (
            [
                'is_active' => $values->getIsActive(),
                'include_in_menu' => $values->getIncludeInMenu(),
                'is_anchor' => $values->getIsAnchor(),
                // Not on CategoryValuesInterface: position is a column shared by
                // every store view, so a store-scoped write passes null here.
                'position' => $position,
            ] as $code => $value
        ) {
            if ($value !== null) {
                $desired[$code] = $value;
            }
        }

        foreach ($values->getCustomAttributes() ?? [] as $attribute) {
            $code = trim($attribute->getAttributeCode());
            if ($code === '') {
                continue;
            }
            if (isset($desired[$code])) {
                $messages[] = sprintf(
                    'Custom attribute "%s" duplicates a first-class field; the first-class value wins.',
                    $code
                );
                continue;
            }
            $desired[$code] = $attribute->getValue();
        }

        return $desired;
    }

    /**
     * @return string[] attribute codes to revert to their default value
     */
    private function collectClears(CategoryValuesInterface $values): array
    {
        $clears = [];
        foreach ($values->getClearAttributes() ?? [] as $code) {
            $code = trim((string)$code);
            if ($code !== '') {
                $clears[$code] = true;
            }
        }

        return array_keys($clears);
    }

    /**
     * Whether clearing $code would actually remove something.
     *
     * At store scope this cannot be answered by reading the value: a loaded
     * category falls back to the default-scope value for every attribute
     * without a store override, so `getData()` is non-null for practically
     * everything. Clearing on that basis deletes a row that was never there,
     * yet still saves — re-running observers, URL rewrite regeneration and
     * reindexing, and reporting "updated" on every single replay. The catalog
     * model records which attributes actually came from a store row, which is
     * the only honest signal here.
     */
    private function hasValueToClear(CategoryModel $loaded, string $code, int $storeId): bool
    {
        return $storeId === 0
            ? $loaded->getData($code) !== null
            : $loaded->getExistsStoreValueFlag($code);
    }

    /**
     * Loose comparison against the stored value.
     *
     * EAV round-trips everything through strings, so a stored "1" and a
     * requested int 1 are the same value; comparing strictly would report every
     * flag as changed on every sync and turn an idempotent replay into a full
     * rewrite of the catalog.
     */
    private function isSameValue(mixed $current, mixed $desired): bool
    {
        if ($current === null) {
            return false;
        }

        return (string)$current === (string)$desired;
    }

    /**
     * Run a callback with the store manager pointed at $storeId, restoring the
     * previous store even when the callback throws.
     *
     * @template T
     * @param callable():T $callback
     * @return T
     */
    private function withStore(int $storeId, callable $callback): mixed
    {
        $previousStoreId = (int)$this->storeManager->getStore()->getId();
        if ($previousStoreId === $storeId) {
            return $callback();
        }

        $this->storeManager->setCurrentStore($storeId);
        try {
            return $callback();
        } finally {
            $this->storeManager->setCurrentStore($previousStoreId);
        }
    }

    /**
     * @return string[]
     */
    private function getRequiredIntAttributes(): array
    {
        return $this->requiredIntAttributes
            ??= $this->categoryResource->getRequiredIntAttributesWithoutDefault();
    }
}
