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
use ReadyData\Import\Model\ResourceModel\Category as CategoryResource;
use ReadyData\Import\Model\ResourceModel\UrlRewrite as UrlRewriteResource;

/**
 * Writes one category through the category repository. The single place in the
 * module that creates or updates a category — CategoryPathResolver's on-demand
 * subtree creation comes through {@see createBare()} rather than building its
 * own model, so a category the product import created and one the category
 * endpoint created cannot drift apart.
 *
 * This is the module's deliberate exception to the direct-SQL rule: a category
 * write has to maintain path/level/children_count, derive url_key/url_path, and
 * cascade URL rewrites across the whole descendant subtree. Reimplementing that
 * in SQL would be the riskiest code in the module, and category cardinality is
 * low enough that the bulk-write argument does not apply.
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
        return $this->createAtDefaultScope($parentId, $name, $this->collectDesired($definition, $name, $messages));
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
        CategoryDefinitionInterface $definition,
        int $storeId,
        array &$messages
    ): bool {
        $desired = $this->collectDesired($definition, $name, $messages);
        $clears = $this->collectClears($definition);

        return $this->withStore($storeId, function () use ($entityId, $storeId, $desired, $clears): bool {
            $loaded = $this->categoryRepository->get($entityId, $storeId);

            // A rename with no explicit url_key would keep the old slug
            // forever: Magento only derives a url_key when the stored one is
            // empty. Deriving it here is what makes the rewrite cascade fire.
            if (isset($desired['name'])
                && !isset($desired['url_key'])
                && (string)$loaded->getName() !== (string)$desired['name']
            ) {
                $desired['url_key'] = $loaded->formatUrlKey((string)$desired['name']);
            }

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
        CategoryDefinitionInterface $definition,
        ?string $name,
        array &$messages
    ): array {
        $desired = [];

        if ($name !== null && $name !== '') {
            $desired['name'] = $name;
        }
        $urlKey = $definition->getUrlKey();
        if ($urlKey !== null && trim($urlKey) !== '') {
            $desired['url_key'] = trim($urlKey);
        }
        foreach (
            [
                'is_active' => $definition->getIsActive(),
                'include_in_menu' => $definition->getIncludeInMenu(),
                'is_anchor' => $definition->getIsAnchor(),
                'position' => $definition->getPosition(),
            ] as $code => $value
        ) {
            if ($value !== null) {
                $desired[$code] = $value;
            }
        }

        foreach ($definition->getCustomAttributes() ?? [] as $attribute) {
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
    private function collectClears(CategoryDefinitionInterface $definition): array
    {
        $clears = [];
        foreach ($definition->getClearAttributes() ?? [] as $code) {
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
