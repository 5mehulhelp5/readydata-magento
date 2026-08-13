<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Test\Unit\Model\Category;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Category as CategoryModel;
use Magento\Catalog\Model\CategoryFactory;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReadyData\Import\Model\Category\CategoryWriter;
use ReadyData\Import\Model\Data\CategoryDefinition;
use ReadyData\Import\Model\Data\CustomAttribute;
use ReadyData\Import\Model\ResourceModel\Category as CategoryResource;
use ReadyData\Import\Model\ResourceModel\UrlRewrite as UrlRewriteResource;

class CategoryWriterTest extends TestCase
{
    private const CATEGORY_ID = 11;

    private CategoryFactory&MockObject $categoryFactory;
    private CategoryRepositoryInterface&MockObject $categoryRepository;
    private StoreManagerInterface&MockObject $storeManager;
    private CategoryResource&MockObject $categoryResource;
    private UrlRewriteResource&MockObject $urlRewriteResource;
    private CategoryWriter $writer;

    /**
     * @var int[] every store the writer switched to, in order
     */
    private array $storeSwitches = [];

    private int $currentStoreId = 0;

    protected function setUp(): void
    {
        $this->categoryFactory = $this->createMock(CategoryFactory::class);
        $this->categoryRepository = $this->createMock(CategoryRepositoryInterface::class);
        $this->categoryResource = $this->createMock(CategoryResource::class);
        $this->categoryResource->method('getRequiredIntAttributesWithoutDefault')->willReturn([]);
        $this->urlRewriteResource = $this->createMock(UrlRewriteResource::class);

        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        // Configured once: a second method('getStore') call would be ignored,
        // so the "current" store lives in a property the callback reads.
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturnCallback(function () {
            return $this->currentStoreId;
        });
        $this->storeManager->method('getStore')->willReturn($store);
        $this->storeManager->method('setCurrentStore')
            ->willReturnCallback(function ($storeId): void {
                $this->storeSwitches[] = (int)$storeId;
                $this->currentStoreId = (int)$storeId;
            });

        $this->writer = new CategoryWriter(
            $this->categoryFactory,
            $this->categoryRepository,
            $this->storeManager,
            $this->categoryResource,
            $this->urlRewriteResource
        );
    }

    private function setCurrentStore(int $storeId): void
    {
        $this->currentStoreId = $storeId;
        $this->storeSwitches = [];
    }

    /**
     * A Category mock backed by a plain array, so getData/setData/addData
     * behave like the real model for diffing purposes.
     *
     * @param array<string, mixed> $data
     * @param string[] $storeValueCodes attributes that came from a store row
     *        rather than from the default-scope fallback, as the catalog model
     *        records them when it loads at store scope
     */
    private function categoryMock(array $data = [], array $storeValueCodes = []): CategoryModel&MockObject
    {
        $category = $this->createMock(CategoryModel::class);
        $backing = $data;

        $storeValueFlags = array_fill_keys($storeValueCodes, true);
        $category->method('getExistsStoreValueFlag')->willReturnCallback(
            static fn (string $code): bool => isset($storeValueFlags[$code])
        );

        $category->method('getData')->willReturnCallback(
            static function (string $key = '', $index = null) use (&$backing) {
                return $key === '' ? $backing : ($backing[$key] ?? null);
            }
        );
        $category->method('setData')->willReturnCallback(
            static function (string $key, $value = null) use (&$backing, $category) {
                $backing[$key] = $value;
                return $category;
            }
        );
        $category->method('addData')->willReturnCallback(
            static function (array $values) use (&$backing, $category) {
                $backing = array_merge($backing, $values);
                return $category;
            }
        );
        // Regular closures throughout, never arrow functions: `fn()` captures
        // by value, which would freeze the backing array at its initial state.
        $category->method('getName')->willReturnCallback(
            static function () use (&$backing) {
                return $backing['name'] ?? null;
            }
        );
        $category->method('setName')->willReturnCallback(
            static function ($value) use (&$backing, $category) {
                $backing['name'] = $value;
                return $category;
            }
        );
        $category->method('setParentId')->willReturnCallback(
            static function ($value) use (&$backing, $category) {
                $backing['parent_id'] = $value;
                return $category;
            }
        );
        $category->method('setIsActive')->willReturnCallback(
            static function ($value) use (&$backing, $category) {
                $backing['is_active'] = $value;
                return $category;
            }
        );
        $category->method('setId')->willReturnCallback(
            static function ($value) use (&$backing, $category) {
                $backing['entity_id'] = $value;
                return $category;
            }
        );
        $category->method('setStoreId')->willReturnCallback(
            static function ($value) use (&$backing, $category) {
                $backing['store_id'] = $value;
                return $category;
            }
        );
        $category->method('getId')->willReturnCallback(
            static function () use (&$backing) {
                return $backing['entity_id'] ?? null;
            }
        );
        $category->method('formatUrlKey')->willReturnCallback(
            static fn (string $value): string => strtolower(str_replace(' ', '-', $value))
        );
        // Exposed for assertions.
        $category->method('getStoreId')->willReturnCallback(
            static function () use (&$backing) {
                return $backing['store_id'] ?? null;
            }
        );
        $category->method('toArray')->willReturnCallback(
            static function () use (&$backing) {
                return $backing;
            }
        );

        return $category;
    }

    public function testUnchangedCategoryIsNeverSaved(): void
    {
        $loaded = $this->categoryMock([
            'name' => 'Shirts',
            'is_active' => '1',
            'include_in_menu' => '1',
        ]);
        $this->categoryRepository->method('get')->willReturn($loaded);
        // The whole point: a replayed payload must not re-run observers, URL
        // rewrite regeneration or reindexing.
        $this->categoryRepository->expects(self::never())->method('save');

        $definition = (new CategoryDefinition())->setIsActive(1)->setIncludeInMenu(1);
        $messages = [];

        self::assertFalse($this->writer->update(self::CATEGORY_ID, 'Shirts', $definition, null, 0, $messages));
    }

    public function testIntAndStringValuesCompareLooselyAgainstStoredEavValues(): void
    {
        // EAV round-trips everything as a string; comparing strictly would
        // report every flag as changed on every single sync.
        $loaded = $this->categoryMock(['name' => 'Shirts', 'is_anchor' => '1', 'position' => '5']);
        $this->categoryRepository->method('get')->willReturn($loaded);
        $this->categoryRepository->expects(self::never())->method('save');

        $definition = (new CategoryDefinition())->setIsAnchor(1)->setPosition(5);
        $messages = [];

        self::assertFalse($this->writer->update(self::CATEGORY_ID, 'Shirts', $definition, null, 0, $messages));
    }

    public function testRenameDerivesUrlKeyAndKeepsRedirectHistory(): void
    {
        $loaded = $this->categoryMock(['name' => 'Shirts', 'url_key' => 'shirts']);
        $this->categoryRepository->method('get')->willReturn($loaded);
        $this->urlRewriteResource->method('isSaveRewritesHistory')->willReturn(true);

        $saved = null;
        $this->categoryRepository->expects(self::once())->method('save')
            ->willReturnCallback(function (CategoryModel $category) use (&$saved) {
                $saved = $category->toArray();
                return $category;
            });

        $definition = new CategoryDefinition();
        $messages = [];

        self::assertTrue($this->writer->update(self::CATEGORY_ID, 'Tops', $definition, null, 0, $messages));
        self::assertSame('Tops', $saved['name']);
        // Without this the category would keep the "shirts" URL forever.
        self::assertSame('tops', $saved['url_key']);
        self::assertTrue($saved['save_rewrites_history']);
    }

    public function testExplicitUrlKeyWinsOverTheDerivedOne(): void
    {
        $loaded = $this->categoryMock(['name' => 'Shirts', 'url_key' => 'shirts']);
        $this->categoryRepository->method('get')->willReturn($loaded);

        $saved = null;
        $this->categoryRepository->method('save')
            ->willReturnCallback(function (CategoryModel $category) use (&$saved) {
                $saved = $category->toArray();
                return $category;
            });

        $definition = (new CategoryDefinition())->setUrlKey('mens-tops');
        $messages = [];

        $this->writer->update(self::CATEGORY_ID, 'Tops', $definition, null, 0, $messages);

        self::assertSame('mens-tops', $saved['url_key']);
    }

    public function testRenameWithoutHistoryConfiguredDoesNotSetTheFlag(): void
    {
        $loaded = $this->categoryMock(['name' => 'Shirts', 'url_key' => 'shirts']);
        $this->categoryRepository->method('get')->willReturn($loaded);
        $this->urlRewriteResource->method('isSaveRewritesHistory')->willReturn(false);

        $saved = null;
        $this->categoryRepository->method('save')
            ->willReturnCallback(function (CategoryModel $category) use (&$saved) {
                $saved = $category->toArray();
                return $category;
            });

        $messages = [];
        $this->writer->update(self::CATEGORY_ID, 'Tops', new CategoryDefinition(), null, 0, $messages);

        self::assertFalse($saved['save_rewrites_history']);
    }

    public function testStoreScopedUpdateSavesASparseObjectNotTheLoadedOne(): void
    {
        $loaded = $this->categoryMock([
            'name' => 'Shirts',
            'url_key' => 'shirts',
            'description' => 'default description',
            'meta_title' => 'default meta',
            'is_anchor' => '1',
        ]);
        $sparse = $this->categoryMock();
        $this->categoryRepository->method('get')->willReturn($loaded);
        $this->categoryFactory->method('create')->willReturn($sparse);

        $saved = null;
        $this->categoryRepository->expects(self::once())->method('save')
            ->willReturnCallback(function (CategoryModel $category) use (&$saved) {
                $saved = $category;
                return $category;
            });

        $definition = (new CategoryDefinition())->setCustomAttributes([
            (new CustomAttribute())->setAttributeCode('description')->setValue('store description'),
        ]);
        $messages = [];

        self::assertTrue($this->writer->update(self::CATEGORY_ID, 'Shirts', $definition, null, 1, $messages));
        self::assertSame($sparse, $saved, 'A fully loaded object would materialize a store override row'
            . ' for every scoped attribute, not just the one the caller sent.');

        $data = $saved->toArray();
        self::assertSame('store description', $data['description']);
        self::assertSame(self::CATEGORY_ID, $data['entity_id']);
        self::assertSame(1, $data['store_id']);
        self::assertArrayNotHasKey('meta_title', $data);
        self::assertArrayNotHasKey('is_anchor', $data);
    }

    public function testClearIsAppliedToTheLoadedInstanceBecauseNullsNeverSurviveTheProjection(): void
    {
        $loaded = $this->categoryMock(['name' => 'Shirts', 'meta_title' => 'store meta'], ['meta_title']);
        $sparse = $this->categoryMock();
        $this->categoryRepository->method('get')->willReturn($loaded);
        $this->categoryFactory->method('create')->willReturn($sparse);
        $this->categoryRepository->method('save')->willReturnArgument(0);

        $definition = (new CategoryDefinition())->setClearAttributes(['meta_title']);
        $messages = [];

        self::assertTrue($this->writer->update(self::CATEGORY_ID, 'Shirts', $definition, null, 1, $messages));
        // save() re-fetches the memoized loaded instance, so the null has to be
        // there; the sparse object would drop it.
        self::assertNull($loaded->getData('meta_title'));
        self::assertArrayNotHasKey('meta_title', $sparse->toArray());
    }

    public function testClearingAnAlreadyEmptyAttributeIsNotAChange(): void
    {
        $loaded = $this->categoryMock(['name' => 'Shirts']);
        $this->categoryRepository->method('get')->willReturn($loaded);
        $this->categoryRepository->expects(self::never())->method('save');

        $definition = (new CategoryDefinition())->setClearAttributes(['meta_title']);
        $messages = [];

        self::assertFalse($this->writer->update(self::CATEGORY_ID, 'Shirts', $definition, null, 0, $messages));
    }

    public function testClearingAtStoreScopeWithNoStoreOverrideIsNotAChange(): void
    {
        // A category loaded at store scope resolves every attribute without an
        // override to its default-scope value, so "meta_title is not null" says
        // nothing about whether a store row exists to delete. Treating the
        // fallback as clearable saved on every replay — re-running observers,
        // rewrite regeneration and reindexing — and reported "updated" forever.
        $loaded = $this->categoryMock(['name' => 'Shirts', 'meta_title' => 'default meta'], []);
        $this->categoryRepository->method('get')->willReturn($loaded);
        $this->categoryRepository->expects(self::never())->method('save');

        $definition = (new CategoryDefinition())->setClearAttributes(['meta_title']);
        $messages = [];

        self::assertFalse($this->writer->update(self::CATEGORY_ID, 'Shirts', $definition, null, 1, $messages));
        self::assertSame('default meta', $loaded->getData('meta_title'));
    }

    public function testCreateDerivesUrlKeyAndZeroFillsRequiredIntAttributes(): void
    {
        $this->categoryResource = $this->createMock(CategoryResource::class);
        $this->categoryResource->method('getRequiredIntAttributesWithoutDefault')
            ->willReturn(['custom_required_flag']);
        $this->writer = new CategoryWriter(
            $this->categoryFactory,
            $this->categoryRepository,
            $this->storeManager,
            $this->categoryResource,
            $this->urlRewriteResource
        );

        $created = $this->categoryMock();
        $this->categoryFactory->method('create')->willReturn($created);
        $this->categoryRepository->method('save')->willReturnCallback(
            static function (CategoryModel $category) {
                $category->setId(self::CATEGORY_ID);
                return $category;
            }
        );

        $messages = [];
        $id = $this->writer->create(2, 'Mens Shirts', new CategoryDefinition(), $messages);

        self::assertSame(self::CATEGORY_ID, $id);
        $data = $created->toArray();
        self::assertSame('Mens Shirts', $data['name']);
        self::assertSame('mens-shirts', $data['url_key']);
        self::assertSame(2, $data['parent_id']);
        self::assertSame(1, $data['include_in_menu']);
        // Would otherwise fail the model's own validation on save.
        self::assertSame(0, $data['custom_required_flag']);
    }

    public function testCreatingARootSetsTheTreeRootAsTheExplicitParent(): void
    {
        // CategoryRepository::save() falls back to the CURRENT STORE's root
        // category for a falsy parent_id, so a root has to be created with the
        // tree root passed explicitly or it lands one level too deep, inside
        // whichever catalog the emulated store happens to use.
        $created = $this->categoryMock();
        $this->categoryFactory->method('create')->willReturn($created);
        $this->categoryRepository->method('save')->willReturnCallback(
            static function (CategoryModel $category) {
                $category->setId(self::CATEGORY_ID);
                return $category;
            }
        );

        $messages = [];
        $this->writer->create(CategoryModel::TREE_ROOT_ID, 'Outdoor Catalog', new CategoryDefinition(), $messages);

        $data = $created->toArray();
        self::assertSame(CategoryModel::TREE_ROOT_ID, $data['parent_id']);
        self::assertSame('outdoor-catalog', $data['url_key']);
    }

    public function testCreateBareProducesTheSameCategoryAsAnEmptyDefinition(): void
    {
        // CategoryPathResolver auto-creates through createBare(), and a
        // category the product import created must be indistinguishable from
        // one the endpoint created with no properties set.
        $viaDefinition = $this->categoryMock();
        $viaBare = $this->categoryMock();
        $this->categoryFactory->method('create')
            ->willReturnOnConsecutiveCalls($viaDefinition, $viaBare);
        $this->categoryRepository->method('save')->willReturnCallback(
            static function (CategoryModel $category) {
                $category->setId(self::CATEGORY_ID);
                return $category;
            }
        );

        $messages = [];
        $this->writer->create(2, 'Shirts', new CategoryDefinition(), $messages);
        $id = $this->writer->createBare(2, 'Shirts');

        self::assertSame(self::CATEGORY_ID, $id);
        self::assertSame($viaDefinition->toArray(), $viaBare->toArray());
    }

    public function testCreateBareRunsAtDefaultScopeAndRestoresThePreviousStore(): void
    {
        $this->setCurrentStore(3);
        $this->categoryFactory->method('create')->willReturn($this->categoryMock());
        $this->categoryRepository->method('save')->willReturnCallback(
            static function (CategoryModel $category) {
                $category->setId(self::CATEGORY_ID);
                return $category;
            }
        );

        $this->writer->createBare(2, 'Shirts');

        // Without this the repository writes the name at store 3 and leaves no
        // store-0 row, so every store-0 name lookup misses the category and the
        // next import creates a duplicate sibling.
        self::assertSame([0, 3], $this->storeSwitches);
    }

    public function testCreateRunsAtDefaultScopeAndRestoresThePreviousStore(): void
    {
        $this->setCurrentStore(3);
        $created = $this->categoryMock();
        $this->categoryFactory->method('create')->willReturn($created);
        $this->categoryRepository->method('save')->willReturnCallback(
            static function (CategoryModel $category) {
                $category->setId(self::CATEGORY_ID);
                return $category;
            }
        );

        $messages = [];
        $this->writer->create(2, 'Shirts', new CategoryDefinition(), $messages);

        // The repository takes its store from the store manager and ignores
        // setStoreId(), so this switch is what puts the values at store 0.
        self::assertSame([0, 3], $this->storeSwitches);
    }

    public function testStoreIsRestoredEvenWhenTheSaveThrows(): void
    {
        $this->setCurrentStore(3);
        $this->categoryFactory->method('create')->willReturn($this->categoryMock());
        $this->categoryRepository->method('save')->willThrowException(new \RuntimeException('url key conflict'));

        $messages = [];
        try {
            $this->writer->create(2, 'Shirts', new CategoryDefinition(), $messages);
            self::fail('Expected the save failure to propagate.');
        } catch (\RuntimeException) {
            // expected
        }

        self::assertSame([0, 3], $this->storeSwitches);
    }

    public function testMoveGoesThroughTheCategoryModelUnderStoreZeroEmulation(): void
    {
        $this->setCurrentStore(3);

        $category = $this->categoryMock(['name' => 'Shirts']);
        $newParent = $this->categoryMock(['name' => 'Women']);
        $newParent->method('hasChildren')->willReturn(false);

        $this->categoryRepository->method('get')->willReturnCallback(
            static fn (int $id, $storeId = null): CategoryModel => $id === self::CATEGORY_ID
                ? $category
                : $newParent
        );

        $category->expects(self::once())->method('move')->with(20, null);

        $this->writer->move(self::CATEGORY_ID, 20);

        // Store 0 for the write, then back — CategoryRepository::save() and
        // move() both read the target store from the store manager.
        self::assertSame([0, 3], $this->storeSwitches);
    }

    public function testMoveLoadsThroughTheRepositorySoOrigDataIsPresent(): void
    {
        $category = $this->categoryMock();
        $newParent = $this->categoryMock();
        $newParent->method('hasChildren')->willReturn(false);

        $gets = [];
        $this->categoryRepository->method('get')->willReturnCallback(
            function (int $id, $storeId = null) use (&$gets, $category, $newParent): CategoryModel {
                $gets[] = [$id, $storeId];
                return $id === self::CATEGORY_ID ? $category : $newParent;
            }
        );

        $this->writer->move(self::CATEGORY_ID, 20);

        // Both at store 0. The category especially: only a repository-loaded
        // model carries the orig data that makes dataHasChangedFor('parent_id')
        // true after the move, which is what
        // CategoryProcessUrlRewriteMovingObserver gates URL rewrite
        // regeneration on.
        self::assertSame([[self::CATEGORY_ID, 0], [20, 0]], $gets);
    }

    public function testMoveAppendsAfterTheDestinationsLastChild(): void
    {
        $category = $this->categoryMock();
        $newParent = $this->categoryMock();
        $newParent->method('hasChildren')->willReturn(true);
        $newParent->method('getChildren')->willReturn('31,32,33');

        $this->categoryRepository->method('get')->willReturnCallback(
            static fn (int $id, $storeId = null): CategoryModel => $id === self::CATEGORY_ID
                ? $category
                : $newParent
        );

        // Passing null here would make _processPositions() use position 1, which
        // puts the moved category FIRST and shifts every existing sibling up.
        // Nobody asked for a reorder, so it appends.
        $category->expects(self::once())->method('move')->with(20, 33);

        $this->writer->move(self::CATEGORY_ID, 20);
    }

    public function testMoveFailureIsNotSwallowedAndTheStoreIsRestored(): void
    {
        $this->setCurrentStore(3);

        $category = $this->categoryMock();
        $newParent = $this->categoryMock();
        $newParent->method('hasChildren')->willReturn(false);
        $category->method('move')->willThrowException(new \RuntimeException('cannot move'));

        $this->categoryRepository->method('get')->willReturnCallback(
            static fn (int $id, $storeId = null): CategoryModel => $id === self::CATEGORY_ID
                ? $category
                : $newParent
        );

        try {
            $this->writer->move(self::CATEGORY_ID, 20);
            self::fail('Expected the move failure to propagate.');
        } catch (\RuntimeException) {
            // The caller's transaction has to see this: the repository's own
            // nested rollback leaves the connection partially rolled back.
        }

        self::assertSame([0, 3], $this->storeSwitches);
    }

    public function testDeleteGoesThroughTheRepositoryUnderStoreZeroEmulation(): void
    {
        $this->setCurrentStore(3);

        $this->categoryRepository->expects(self::once())->method('deleteByIdentifier')
            ->with(self::CATEGORY_ID);

        $this->writer->delete(self::CATEGORY_ID);

        self::assertSame([0, 3], $this->storeSwitches);
    }

    public function testDeleteFailureIsNotSwallowedAndTheStoreIsRestored(): void
    {
        $this->setCurrentStore(3);
        $this->categoryRepository->method('deleteByIdentifier')
            ->willThrowException(new \RuntimeException('cannot delete'));

        try {
            $this->writer->delete(self::CATEGORY_ID);
            self::fail('Expected the delete failure to propagate.');
        } catch (\RuntimeException) {
            // expected
        }

        self::assertSame([0, 3], $this->storeSwitches);
    }

    public function testCreateReportsASiblingAlreadyUsingTheDerivedSlug(): void
    {
        $blank = $this->categoryMock();
        $blank->method('formatUrlKey')->willReturnCallback(
            static fn (string $s): string => strtolower(str_replace(' ', '-', $s))
        );
        $this->categoryFactory->method('create')->willReturn($blank);
        // Nothing is named "Clearance", but a sibling already owns "clearance".
        $this->categoryResource->method('getChildUrlKeysByParentIds')
            ->willReturn([10 => ['clearance' => [33]]]);

        self::assertSame(
            ['kind' => 'url_key', 'value' => 'clearance', 'category_id' => 33],
            $this->writer->findNewChildConflict(10, 'Clearance', new CategoryDefinition())
        );
    }

    public function testCreateWithAnExplicitUrlKeySidestepsTheCollision(): void
    {
        $this->categoryResource->method('getChildUrlKeysByParentIds')
            ->willReturn([10 => ['clearance' => [33]]]);
        // The explicit key is what would be written, so the derived one is moot
        // and the factory is never needed.
        $this->categoryFactory->expects(self::never())->method('create');

        self::assertNull($this->writer->findNewChildConflict(
            10,
            'Clearance',
            (new CategoryDefinition())->setUrlKey('mens-clearance-rd')
        ));
    }

    public function testCreateWithAnExplicitUrlKeyThatCollidesIsStillCaught(): void
    {
        $this->categoryResource->method('getChildUrlKeysByParentIds')
            ->willReturn([10 => ['taken' => [33]]]);

        self::assertSame(
            ['kind' => 'url_key', 'value' => 'taken', 'category_id' => 33],
            $this->writer->findNewChildConflict(
                10,
                'Clearance',
                (new CategoryDefinition())->setUrlKey('taken')
            )
        );
    }

    public function testCreateNeverQueriesSiblingNames(): void
    {
        $blank = $this->categoryMock();
        $blank->method('formatUrlKey')->willReturn('clearance');
        $this->categoryFactory->method('create')->willReturn($blank);
        $this->categoryResource->method('getChildUrlKeysByParentIds')->willReturn([]);
        // A name collision cannot reach the create branch, so asking is waste.
        $this->categoryResource->expects(self::never())->method('getChildIdsByParentIds');

        self::assertNull($this->writer->findNewChildConflict(10, 'Clearance', new CategoryDefinition()));
    }

    public function testCreateCollisionCheckRunsAtDefaultScope(): void
    {
        $this->setCurrentStore(3);
        $blank = $this->categoryMock();
        $blank->method('formatUrlKey')->willReturn('clearance');
        $this->categoryFactory->method('create')->willReturn($blank);
        $this->categoryResource->method('getChildUrlKeysByParentIds')->willReturn([]);

        $this->writer->findNewChildConflict(10, 'Clearance', new CategoryDefinition());

        // Same emulation the create itself runs in, so the predicted slug is
        // byte-for-byte the one that would be written.
        self::assertSame([0, 3], $this->storeSwitches);
    }

    public function testMoveOntoATakenNameReportsTheNamesake(): void
    {
        $loaded = $this->categoryMock(['name' => 'Shirts', 'url_key' => 'shirts']);
        $this->categoryRepository->method('get')->willReturn($loaded);
        $this->categoryResource->method('getChildIdsByParentIds')
            ->willReturn([20 => ['Shirts' => [33]]]);

        self::assertSame(
            ['kind' => 'name', 'value' => 'Shirts', 'category_id' => 33],
            $this->writer->findSiblingConflict(self::CATEGORY_ID, 20, null, new CategoryDefinition(), true)
        );
    }

    public function testMoveOntoAFreeNameButTakenSlugReportsTheSlug(): void
    {
        $loaded = $this->categoryMock(['name' => 'Shirts', 'url_key' => 'shirts']);
        $this->categoryRepository->method('get')->willReturn($loaded);
        $this->categoryResource->method('getChildIdsByParentIds')
            ->willReturn([20 => ['Blouses' => [33]]]);
        // Different names, same slug: url_rewrite's (request_path, store_id)
        // unique key is what would blow up, deep inside the save.
        $this->categoryResource->method('getChildUrlKeysByParentIds')
            ->willReturn([20 => ['shirts' => [33]]]);

        self::assertSame(
            ['kind' => 'url_key', 'value' => 'shirts', 'category_id' => 33],
            $this->writer->findSiblingConflict(self::CATEGORY_ID, 20, null, new CategoryDefinition(), true)
        );
    }

    public function testMoveToAClearDestinationReportsNoConflict(): void
    {
        $loaded = $this->categoryMock(['name' => 'Shirts', 'url_key' => 'shirts']);
        $this->categoryRepository->method('get')->willReturn($loaded);
        $this->categoryResource->method('getChildIdsByParentIds')
            ->willReturn([20 => ['Blouses' => [33]]]);
        $this->categoryResource->method('getChildUrlKeysByParentIds')
            ->willReturn([20 => ['blouses' => [33]]]);

        self::assertNull(
            $this->writer->findSiblingConflict(self::CATEGORY_ID, 20, null, new CategoryDefinition(), true)
        );
    }

    public function testACategoryNeverConflictsWithItself(): void
    {
        $loaded = $this->categoryMock(['name' => 'Shirts', 'url_key' => 'shirts']);
        $this->categoryRepository->method('get')->willReturn($loaded);
        // Its own row is what the sibling map returns.
        $this->categoryResource->method('getChildIdsByParentIds')
            ->willReturn([10 => ['Shirts' => [self::CATEGORY_ID]]]);
        $this->categoryResource->method('getChildUrlKeysByParentIds')
            ->willReturn([10 => ['shirts' => [self::CATEGORY_ID]]]);

        self::assertNull(
            $this->writer->findSiblingConflict(self::CATEGORY_ID, 10, null, new CategoryDefinition(), true)
        );
    }

    public function testADuplicateNameListContainingSelfStillFindsTheOther(): void
    {
        $loaded = $this->categoryMock(['name' => 'Shirts', 'url_key' => 'shirts']);
        $this->categoryRepository->method('get')->willReturn($loaded);
        $this->categoryResource->method('getChildIdsByParentIds')
            ->willReturn([20 => ['Shirts' => [self::CATEGORY_ID, 33]]]);

        $conflict = $this->writer->findSiblingConflict(
            self::CATEGORY_ID,
            20,
            null,
            new CategoryDefinition(),
            true
        );

        self::assertSame(33, $conflict['category_id']);
    }

    public function testARenamePredictsTheDerivedSlugAndCatchesItsCollision(): void
    {
        $loaded = $this->categoryMock(['name' => 'Shirts', 'url_key' => 'shirts']);
        $loaded->method('formatUrlKey')->willReturnCallback(
            static fn (string $s): string => strtolower(str_replace(' ', '-', $s))
        );
        $this->categoryRepository->method('get')->willReturn($loaded);
        $this->categoryResource->method('getChildIdsByParentIds')
            ->willReturn([10 => ['Shirts' => [self::CATEGORY_ID]]]);
        // No sibling is called "Winter Coats", but one already owns the slug the
        // rename would derive.
        $this->categoryResource->method('getChildUrlKeysByParentIds')
            ->willReturn([10 => ['winter-coats' => [33]]]);

        self::assertSame(
            ['kind' => 'url_key', 'value' => 'winter-coats', 'category_id' => 33],
            $this->writer->findSiblingConflict(
                self::CATEGORY_ID,
                10,
                'Winter Coats',
                new CategoryDefinition(),
                false
            )
        );
    }

    public function testAnExplicitUrlKeyWinsOverTheDerivedOne(): void
    {
        $loaded = $this->categoryMock(['name' => 'Shirts', 'url_key' => 'shirts']);
        $loaded->method('formatUrlKey')->willReturnCallback(
            static fn (string $s): string => strtolower(str_replace(' ', '-', $s))
        );
        $this->categoryRepository->method('get')->willReturn($loaded);
        $this->categoryResource->method('getChildIdsByParentIds')
            ->willReturn([10 => ['Shirts' => [self::CATEGORY_ID]]]);
        $this->categoryResource->method('getChildUrlKeysByParentIds')
            ->willReturn([10 => ['winter-coats' => [33]]]);

        // The derived slug would collide; the explicit one does not, and it is
        // the explicit one that gets written.
        self::assertNull($this->writer->findSiblingConflict(
            self::CATEGORY_ID,
            10,
            'Winter Coats',
            (new CategoryDefinition())->setUrlKey('coats-winter'),
            false
        ));
    }

    public function testStandingStillQueriesNothingAtAll(): void
    {
        $loaded = $this->categoryMock(['name' => 'Shirts', 'url_key' => 'shirts']);
        $this->categoryRepository->method('get')->willReturn($loaded);
        // Same name, same slug, same parent: a replayed payload must stay free.
        $this->categoryResource->expects(self::never())->method('getChildIdsByParentIds');
        $this->categoryResource->expects(self::never())->method('getChildUrlKeysByParentIds');

        self::assertNull(
            $this->writer->findSiblingConflict(self::CATEGORY_ID, 10, 'Shirts', new CategoryDefinition(), false)
        );
    }

    public function testAMoveAlwaysQueriesEvenWithNothingChanging(): void
    {
        $loaded = $this->categoryMock(['name' => 'Shirts', 'url_key' => 'shirts']);
        $this->categoryRepository->method('get')->willReturn($loaded);
        // The name is unchanged but the parent is not, so the collision is real.
        $this->categoryResource->expects(self::once())->method('getChildIdsByParentIds')->willReturn([]);

        self::assertNull(
            $this->writer->findSiblingConflict(self::CATEGORY_ID, 20, 'Shirts', new CategoryDefinition(), true)
        );
    }

    public function testAnEmptyUrlKeyIsNotTreatedAsACollision(): void
    {
        $loaded = $this->categoryMock(['name' => 'Shirts', 'url_key' => '']);
        $this->categoryRepository->method('get')->willReturn($loaded);
        $this->categoryResource->method('getChildIdsByParentIds')->willReturn([]);
        $this->categoryResource->expects(self::never())->method('getChildUrlKeysByParentIds');

        self::assertNull(
            $this->writer->findSiblingConflict(self::CATEGORY_ID, 20, null, new CategoryDefinition(), true)
        );
    }

    public function testTheNameConflictTakesPrecedenceOverTheSlugConflict(): void
    {
        $loaded = $this->categoryMock(['name' => 'Shirts', 'url_key' => 'shirts']);
        $this->categoryRepository->method('get')->willReturn($loaded);
        $this->categoryResource->method('getChildIdsByParentIds')
            ->willReturn([20 => ['Shirts' => [33]]]);
        $this->categoryResource->method('getChildUrlKeysByParentIds')
            ->willReturn([20 => ['shirts' => [44]]]);

        $conflict = $this->writer->findSiblingConflict(
            self::CATEGORY_ID,
            20,
            null,
            new CategoryDefinition(),
            true
        );

        // The ambiguity is the more fundamental problem, and the one with no
        // database backstop at all.
        self::assertSame('name', $conflict['kind']);
    }

    public function testTheConflictCheckRunsAtDefaultScope(): void
    {
        $this->setCurrentStore(3);
        $loaded = $this->categoryMock(['name' => 'Shirts', 'url_key' => 'shirts']);
        $this->categoryRepository->method('get')->willReturn($loaded);
        $this->categoryResource->method('getChildIdsByParentIds')->willReturn([]);

        $this->writer->findSiblingConflict(self::CATEGORY_ID, 20, null, new CategoryDefinition(), true);

        // Store 0 is the scope a structural write uses, and the scope whose names
        // path resolution matches.
        self::assertSame([0, 3], $this->storeSwitches);
    }

    public function testFirstClassFieldWinsOverADuplicateCustomAttribute(): void
    {
        $loaded = $this->categoryMock(['name' => 'Shirts', 'is_active' => '1']);
        $this->categoryRepository->method('get')->willReturn($loaded);
        $this->categoryRepository->expects(self::never())->method('save');

        $definition = (new CategoryDefinition())
            ->setIsActive(1)
            ->setCustomAttributes([
                (new CustomAttribute())->setAttributeCode('is_active')->setValue('0'),
            ]);
        $messages = [];

        self::assertFalse($this->writer->update(self::CATEGORY_ID, 'Shirts', $definition, null, 0, $messages));
        self::assertStringContainsString('duplicates a first-class field', $messages[0]);
    }
}
