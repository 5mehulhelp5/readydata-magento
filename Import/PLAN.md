# ReadyData Magento 2 Bulk Product Import Module — Implementation Plan

## Goal

A Magento 2 module (`ReadyData_Import`) exposing a REST endpoint that accepts batches of
product JSON (default 500 products per request/batch, configurable) and imports them via
**direct database writes**, bypassing `Magento\Catalog\Model\Product` save and the stock
`ImportExport` framework for performance.

Target: thousands of products per minute on commodity hardware. All heavy paths must use
multi-row `INSERT ... ON DUPLICATE KEY UPDATE`, batched lookups, and in-memory metadata
caches. No per-product model instantiation, no per-product events, no per-product queries.

Two deliberate departures from that last rule have since been made, both detailed in §7:
**categories** are written through the category model, because path/level maintenance and
URL rewrite generation have no safe direct-write equivalent; and product lifecycle
**events are re-emitted** per product after each batch, because bypassing the model
otherwise silently breaks every third-party observer on the store. Two further endpoints
— attribute definitions and category sync — were added alongside the product import as
standalone pre-flight services.

---

## 1. High-level architecture

Three independent endpoints. The product import is the one described below; the
attribute and category syncs are standalone pre-flight services that share the
lock layer, the caches and the config, but not the batch pipeline.

```
POST /rest/all/V1/readydata/products
        │
        ▼
Api\ProductImportInterface (Web API service contract)
        │  validates auth (ACL) + payload shape
        ▼
Model\ImportService (orchestrator)
        │  splits payload into batches (config: batch size, default 500)
        │
        ├─ 1. prepare()      PreparableInterface steps, OUTSIDE the transaction and
        │                    outside the locks — today only MediaProcessor, which
        │                    downloads the batch's files concurrently
        │
        ├─ 2. batchLocks()   ask every LockAwareInterface step what this batch will
        │                    actually CREATE; acquire that set in a fixed global
        │                    order, all-or-nothing. Often empty (see §7)
        │
        ├─ 3. prepareUnderLocks()  LockedPreparableInterface steps — under the locks,
        │                    still OUTSIDE the transaction. Today only
        │                    CategoryLinkProcessor, which creates missing categories
        │                    through the repository (its own transaction cannot nest
        │                    inside ours — see §7)
        │
        ├─ 4. transaction    ─────────────────────────────────────────────
        │      Processor pipeline, ordered by getSortOrder(), pool in di.xml
        │      100  AttributeProcessor      resolve/auto-create option values
        │      200  EntityProcessor         catalog_product_entity rows
        │      300  EavValueProcessor       *_varchar/int/decimal/text/datetime
        │      400  WebsiteProcessor        catalog_product_website
        │      500  StockProcessor          stock_item + MSI inventory_source_item
        │      700  CategoryLinkProcessor   catalog_category_product
        │      710  MediaProcessor          the four gallery tables + roles
        │      720  LinkProcessor           related / up-sell / cross-sell
        │      730  ConfigurableProcessor   super attribute + super link
        │      740  TierPriceProcessor      catalog_product_entity_tier_price
        │      750  UrlRewriteProcessor     url_rewrite (+ url_key gen/dedup)
        │      dispatchBeforeCommit()  →  catalog_product_save_after, if enabled
        │      COMMIT / ROLLBACK      ─────────────────────────────────────
        │
        ├─ 5. release locks
        └─ 6. dispatchAfterCommit()  catalog_product_save_commit_after +
                                     the batch-level readydata_import_* events
        │
        ▼
Model\Indexer\InvalidationHandler   (after ALL batches, unlocked)
        │  partial reindex by entity IDs, or mark invalid (configurable)
        │  + FPC/block cache tags for touched products
        ▼
Response: summary counters + per-SKU results
          {sku, entity_id, status: created|updated|error, messages[], store_results[]}
```

`UrlRewriteProcessor` runs **last** (750), not in the middle: category-path
rewrites need the assignments `CategoryLinkProcessor` (700) has just written, and
a store-scoped `url_key` written by `EavValueProcessor` (300) has to be in place
before the slug is resolved.

Design rules:

- Every processor implements `ProcessorInterface` and receives the **whole batch** plus a
  shared `BatchContext` (SKU→entity_id map, attribute metadata, store/website maps,
  the held-lock set, and a free-form data bag processors use to hand each other results).
  Processors never loop-query; they bulk-read and bulk-write.
- Adding functionality later = adding a processor to the `di.xml` pool. No orchestrator
  changes. Three opt-in interfaces extend a step beyond `process()`:
  **`PreparableInterface`** for work that must happen before the locks and the transaction
  (network, filesystem); **`LockAwareInterface`** for a step that performs an unkeyed
  read-then-create and must declare which named lock covers it; and
  **`LockedPreparableInterface`** for a step whose write goes through a repository that
  opens a transaction of its own, which cannot nest inside the batch's.
- Each batch is one DB transaction: a failed batch rolls back and is reported; other
  batches proceed (configurable: fail-fast vs. continue). The transaction spans **every
  scope** a product names, so `continue_on_error` resumes at the next batch, never at the
  next store view.
- Since the pipeline bypasses `Magento\Catalog\Model\Product`, `ImportEventDispatcher`
  re-emits the product lifecycle events, and two plugins suppress core's own URL-rewrite
  and inventory save observers for the duration of an import so they cannot double-write
  what the pipeline already wrote in bulk.

### 1.1 The other two endpoints

```
POST /rest/all/V1/readydata/attributes   →  Api\AttributeSyncInterface
        Model\AttributeSyncService — attribute DEFINITIONS via Magento's EavSetup
        (not direct DB: EAV cache, set/group membership and flat columns must be
        maintained). Per-attribute reconciliation, no wrapping transaction.
        Optional Amasty layered-navigation block, applied last and soft-dependent.
        Holds ATTRIBUTE_SYNC + ATTRIBUTE_OPTIONS for the whole request.

POST /rest/all/V1/readydata/categories   →  Api\CategorySyncInterface
        Model\CategorySyncService — categories via the category repository
        (path/level maintenance, url_key, URL rewrites). Shallowest path first.
        Holds CATEGORY_TREE.
```

Both are **off by default** and gated by their own config flag and ACL resource.
Categories are the module's one deliberate exception to the direct-write rule —
see §7.

## 2. REST API

| Endpoint | Service | ACL | Default |
|---|---|---|---|
| `POST /V1/readydata/products` | `ProductImportInterface::import` | `ReadyData_Import::import` | on |
| `POST /V1/readydata/attributes` | `AttributeSyncInterface::sync` | `ReadyData_Import::attributes` | off |
| `POST /V1/readydata/categories` | `CategorySyncInterface::sync` | `ReadyData_Import::categories` | off |

`ReadyData_Import::config` guards the admin config section. Still unwritten, and
commented out in `etc/webapi.xml` rather than stubbed:
`POST /V1/readydata/products/delete` and `GET /V1/readydata/import/:id/status`
(the latter belongs to the async expansion in §7).

- **Auth:** standard Magento integration tokens (OAuth/bearer). Call
  `/rest/all/V1/...`, not `/rest/V1/...` — the latter resolves against the default
  store view rather than the admin scope, which changes what the default scope means.
- **Payload:** array of product objects. Service contract uses data interfaces
  (`Api/Data/ProductInterface`, `StockDataInterface`, `ImportResultInterface`, ...) so
  the schema is discoverable via `/rest/schema`. Custom attributes ride in a
  `custom_attributes` key-value array to stay flexible; `store_values` carries any
  number of additional store-view scopes for the same product.
- **Response:** summary (received/created/updated/failed counts, elapsed ms, the
  `store_id` the request actually ran in) + per-SKU results, each with optional
  `store_results` for the scopes the product named. Errors are per-product, not
  all-or-nothing. The one exception is a lock refusal on the first batch, which is an
  `ImportLockedException` → **429** with a machine-readable `parameters.reason`.

Example request body — the fields the pipeline understands today:

```json
{
  "products": [
    {
      "sku": "ABC-123",
      "type_id": "simple",
      "attribute_set": "Default",
      "name": "Example",
      "price": 19.99,
      "status": 1,
      "visibility": 4,
      "websites": ["base"],
      "categories": ["Default Category/Men/Shirts"],
      "categories_replace_scope": [2],
      "stock": {"qty": 100, "is_in_stock": true, "source_code": "default"},
      "url_key": "example-product",
      "links": {"related": ["DEF-456"]},
      "configurable": {"super_attributes": ["color"], "children": ["ABC-123-RED"]},
      "tier_prices": [{"customer_group": "all groups", "qty": 1, "price": 17.99}],
      "media": [{"file": "https://cdn.example.com/a.jpg", "roles": ["image"]}],
      "custom_attributes": [{"attribute_code": "color", "value": "Red"}],
      "clear_attributes": ["special_label"],
      "store_values": [{"store_id": 3, "name": "Beispiel"}]
    }
  ],
  "settings": {"store_view_code": "default", "continue_on_error": true,
               "root_category_id": 2, "batch_size": 500}
}
```

Per-field semantics (replace vs. leave-untouched, the per-dimension safety valves,
scope resolution) are the README's job, not this document's — it is the reference
that stays current with the code.

## 3. Direct-DB import strategy (the performance core)

### 3.1 Batch context preparation (once per batch)

1. Bulk `SELECT sku, entity_id, ... FROM catalog_product_entity WHERE sku IN (...)`
   → existing/new split.
2. Load attribute metadata for every attribute code seen in the batch **once**
   (`eav_attribute` + `catalog_eav_attribute`), cached across batches in the request.
3. Resolve attribute sets, store IDs, website IDs from cached maps.
4. Resolve select/multiselect option labels → option IDs; auto-create missing options
   (configurable) via bulk inserts into `eav_attribute_option(_value)`.

### 3.2 Writes

- **`catalog_product_entity`:** multi-row `insertOnDuplicate`. Re-select new entity IDs
  by SKU after insert (avoids per-row lastInsertId; works with EE `row_id` via the
  metadata pool — always resolve the link field through
  `Magento\Framework\EntityManager\MetadataPool`).
- **EAV values:** group values by backend type, one `insertOnDuplicate` per
  `catalog_product_entity_{varchar,int,decimal,text,datetime}` table per batch.
  Store-scope values only written when they differ from default scope (configurable).
- **Stock:** `cataloginventory_stock_item` upsert + MSI `inventory_source_item` upsert;
  trigger `inventory_reservations`-aware salability recalculation only via the partial
  indexer, never per row. Detect MSI availability and degrade gracefully.
- **URL rewrites:** generate `url_key` from name when absent (with `-1`, `-2` dedup via a
  single bulk conflict lookup), upsert `url_rewrite` rows per store, honoring the
  "Create Permanent Redirect" config. Conflict resolution strategy configurable:
  error / append-suffix / skip.
- **Media gallery:** files are acquired *before* the batch transaction opens
  (`PreparableInterface::prepare()`), so no row locks are held across network I/O. Gallery
  rows are bulk-inserted and their AUTO_INCREMENT `value_id`s read back by `MAX(value_id)`
  watermark plus positional verification — the row has no natural key to re-select by.
  `..._media_gallery_value` is written delete-then-insert because its
  `(entity_id, value_id, store_id)` index is **not** unique; `_value_to_entity` and
  `_value_video` are upserted on their real primary keys. Removals delete the bindings and
  then the gallery rows left unbound, which cascades the rest.
- **Category links:** `catalog_category_product` diffed against the desired set and
  upserted; removals are bounded by the replace scope (per-product
  `categories_replace_scope` or the instance default). Missing path segments are created
  on demand — the one write that goes through a Magento model, see §7.
- **Product links:** `catalog_product_link` upsert plus positions in
  `catalog_product_link_attribute_int`, replaced per link type so an omitted type keeps
  its rows.
- **Configurables:** `catalog_product_super_attribute` and
  `catalog_product_super_link`, replaced per sub-field, both keyed off the parent's
  link field.
- **Tier prices:** diffed against the stored rows on their real unique key
  (customer group, qty, website) at the column decimal scales, so an unchanged set
  issues **no SQL at all**.
- All raw SQL isolated in `Model/ResourceModel/*` classes; processors contain the logic,
  resource models contain the SQL. Chunk very large multi-row inserts (~1k rows/statement)
  to stay under `max_allowed_packet`.

### 3.3 Indexing & cache

- Config switch: `none` (leave to cron) / `invalidate` / `partial` (default —
  `reindexList($entityIds)` on price, stock, EAV, fulltext, category-product indexers).
- Clean `FPC`/block cache tags for touched products only (`catalog_product_{id}`),
  configurable.
- Recommend indexers in "Update by Schedule" mode; document that in README.

## 4. Configuration (Stores → Configuration → ReadyData → Import)

Defaults below are what `etc/config.xml` ships.

| Path | Default | Purpose |
|---|---|---|
| `readydata_import/general/enabled` | 1 | kill switch |
| `readydata_import/general/batch_size` | 500 | products per internal batch/transaction |
| `readydata_import/general/continue_on_error` | 1 | per-batch fail-fast vs. continue |
| `readydata_import/behavior/create_missing_options` | 1 | auto-create select options |
| `readydata_import/behavior/url_rewrite_conflict` | append | error/append/skip |
| `readydata_import/attributes/auto_create` | 1 | attribute-sync endpoint |
| `readydata_import/categories/enabled` | 0 | category-sync endpoint |
| `readydata_import/categories/allow_move` | 0 | permit reparenting an existing category |
| `readydata_import/categories/allow_delete` | 0 | permit `delete: 1` |
| `readydata_import/categories/allow_cross_root_move` | 0 | permit a move between root trees |
| `readydata_import/categories/replace_scope` | all_roots | how far a product `categories` replace reaches |
| `readydata_import/media/enabled` | 1 | media gallery step (downloads included) |
| `readydata_import/media/download_timeout` | 15 | seconds per image |
| `readydata_import/media/download_concurrency` | 4 | images fetched at once (1 = sequential, max 32) |
| `readydata_import/media/max_file_size_kb` | 10240 | largest accepted image |
| `readydata_import/media/allowed_extensions` | jpg,jpeg,png,gif,webp | downloads and pre-uploaded paths |
| `readydata_import/media/allowed_hosts` | *(empty)* | download host allow-list; empty = any host |
| `readydata_import/media/redownload_existing` | 0 | re-fetch a URL whose target file exists |
| `readydata_import/media/auto_assign_roles` | 1 | base roles → first enabled entry |
| `readydata_import/indexing/mode` | partial | none/invalidate/partial |
| `readydata_import/indexing/clean_cache` | 1 | FPC/block tags for touched products |
| `readydata_import/events/dispatch_product_events` | 1 | re-emit `*_save_commit_after` after each batch |
| `readydata_import/events/dispatch_save_after` | 1 | also `*_save_after`, IN-transaction |
| `readydata_import/events/hydrate_media` | 1 | gallery on dispatched products |
| `readydata_import/logging/enabled` | 1 | dedicated log file |

The three category permission flags and the two dependent event flags are
`depends`-gated in `system.xml`, so the admin hides them until their parent is on.

## 5. File tree

The module ships inside the `readydata/magento-modules` package alongside a
sibling `ReadyData_Events` module, so `composer.json` sits one level **above** the
module root rather than in it.

```
app/code/ReadyData/                        # package + git root
├── composer.json                          # readydata/magento-modules, covers both
├── README.md                              # package-level, points at the modules
├── Events/                                # sibling module, not ours
└── Import/                                # <- ReadyData_Import
    ├── registration.php
    ├── README.md                          # the living reference; PLAN.md is the map
    ├── PLAN.md
    ├── Api/
    │   ├── ProductImportInterface.php
    │   ├── AttributeSyncInterface.php
    │   ├── CategorySyncInterface.php
    │   └── Data/                          # 27 interfaces: product, attribute,
    │                                      #   category, scoped-result and Amasty DTOs
    ├── Model/
    │   ├── ProductImport.php              # Web API entry, thin
    │   ├── AttributeSync.php              #   "
    │   ├── CategorySync.php               #   "
    │   ├── ImportService.php              # batching, locks, transactions
    │   ├── AttributeSyncService.php       # per-attribute reconciliation via EavSetup
    │   ├── CategorySyncService.php        # shallowest-path-first reconciliation
    │   ├── AttributeValidator.php         # shape + structural-change guards
    │   ├── CategoryValidator.php          #   "  (Magento-owned attributes)
    │   ├── BatchContext.php               # shared per-batch state + held locks
    │   ├── ImportLocks.php                # the six lock names + acquisition order
    │   ├── ImportState.php                # import-active flag, shared instance
    │   ├── Config.php                     # typed accessor for system config
    │   ├── UrlKeyGenerator.php
    │   ├── Data/                          # DTO implementations of Api/Data
    │   ├── Exception/                     # ImportLocked, MediaReference,
    │   │                                  #   AttributeValidation, CategoryValidation
    │   ├── Processor/
    │   │   ├── ProcessorInterface.php
    │   │   ├── PreparableInterface.php    # opt-in pre-lock, pre-transaction phase
    │   │   ├── LockAwareInterface.php     # opt-in "which lock will I need"
    │   │   ├── LockedPreparableInterface.php  # opt-in locked, pre-transaction phase
    │   │   ├── AbstractPlaceholderProcessor.php   # base for inert steps; unused
    │   │   ├── AttributeProcessor.php     100
    │   │   ├── EntityProcessor.php        200
    │   │   ├── EavValueProcessor.php      300
    │   │   ├── WebsiteProcessor.php       400
    │   │   ├── StockProcessor.php         500
    │   │   ├── CategoryLinkProcessor.php  700  (LockAware + LockedPreparable)
    │   │   ├── MediaProcessor.php         710  (Preparable + LockAware)
    │   │   ├── LinkProcessor.php          720
    │   │   ├── ConfigurableProcessor.php  730
    │   │   ├── TierPriceProcessor.php     740
    │   │   ├── UrlRewriteProcessor.php    750  (LockAware)
    │   │   └── UrlRewrite/CategoryPathRewriteBuilder.php
    │   ├── ResourceModel/                 # all raw SQL — 14 classes
    │   │   ├── ProductEntity.php          # entity upserts, SKU→ID, link field, staging probe
    │   │   ├── EavValue.php               # per-backend-type value upserts + clears
    │   │   ├── AttributeOption.php        # option lookup/bulk create (+ memo)
    │   │   ├── AttributeDefinition.php    # definition reads for the sync endpoint
    │   │   ├── AmastyAttribute.php        # soft-dependent ILN/brand tables
    │   │   ├── Stock.php                  # stock_item + MSI source items
    │   │   ├── Category.php               # tree reads/writes
    │   │   ├── CategoryLink.php           # catalog_category_product
    │   │   ├── ProductLink.php            # related/up-sell/cross-sell + positions
    │   │   ├── Configurable.php           # super attribute + super link
    │   │   ├── ProductMediaGallery.php    # the four media gallery tables
    │   │   ├── TierPrice.php              # diff/upsert + decimal scaling
    │   │   ├── UrlRewrite.php
    │   │   └── Website.php
    │   ├── Media/
    │   │   ├── FileResolver.php           # validates + signature-verifies, writes
    │   │   ├── HostAllowList.php          # per-redirect-hop enforcement
    │   │   ├── DownloaderInterface.php
    │   │   └── PooledDownloader.php       # bounded concurrent fetch (Guzzle Pool)
    │   ├── Category/
    │   │   ├── CategoryWriter.php         # the ONE creation path, store-0 emulated
    │   │   └── PathParser.php             # escaped-separator grammar
    │   ├── Amasty/AmastyAttributeWriter.php
    │   ├── Event/
    │   │   ├── ImportEventDispatcher.php  # re-emitted product + batch events
    │   │   └── ProductMediaHydrator.php   # optional gallery on dispatched products
    │   ├── Cache/                         # request-scoped, all shared instances
    │   │   ├── AttributeMetadataCache.php
    │   │   ├── StoreWebsiteMap.php
    │   │   ├── CustomerGroupMap.php
    │   │   ├── CategoryPathResolver.php   # path→ID + on-demand subtree creation
    │   │   └── RootCategoryRegistry.php   # invalidated by the category endpoint
    │   ├── Config/Source/                 # IndexingMode, UrlRewriteConflict,
    │   │                                  #   CategoryReplaceScope
    │   └── Indexer/
    │       ├── InvalidationHandler.php            # products
    │       ├── CategoryInvalidationHandler.php
    │       └── AttributeInvalidationHandler.php
    ├── Plugin/                            # suppress core's URL-rewrite and inventory
    │                                      #   save observers while an import is active
    ├── Logger/                            # Handler + Logger (var/log/readydata_import.log)
    ├── etc/
    │   ├── module.xml, di.xml, acl.xml, webapi.xml, config.xml
    │   └── adminhtml/system.xml
    └── Test/Unit/                         # 37 test classes, mirroring Model/
```

No placeholder steps remain — every dimension in the pipeline is implemented.
`AbstractPlaceholderProcessor` survives as the base class for a step that should
be registered but inert until it is written; nothing extends it today.

**There is no integration test suite.** `Test/Integration/` was planned and never
created, so all 37 tests are unit tests against mocks — nothing in this repository
executes a statement against a database. The caller-side suite in the ReadyData
app (`readydata/tests`) does cover the request shapes this endpoint receives, but
its HTTP client is mocked too, so it never reaches the PHP either. Every claim in
§3 about what the SQL actually does is therefore unverified by an automated test.
The two-connection harness §7 wants for the concurrency work would be this
directory's first inhabitant.

## 6. Implementation status

Everything in the original build order shipped: skeleton and DTOs, the
`ImportService`/`BatchContext`/`EntityProcessor`/`EavValueProcessor` core,
attribute options, websites, stock (legacy + MSI), URL rewrites, invalidation and
cache cleaning, logging and per-SKU reporting. So did the four steps the plan had
marked as placeholders — category links, product links, configurables and tier
prices — plus media.

Built since, and never described by this plan until now:

1. **Store-scoped values** — `store_values` blocks, per-scope results, scope-aware
   clears, per-store URL keys.
2. **Media** — the four gallery tables, roles, external video, concurrent
   downloads with signature verification and host allow-listing.
3. **Attribute definition sync** — a second endpoint, via `EavSetup`, with an
   optional Amasty layered-navigation block.
4. **Category sync** — a third endpoint owning the tree itself: create, rename,
   move, delete, per-store values.
5. **The event layer** — `ImportEventDispatcher`, optional media hydration, and
   the two observer-suppression plugins.
6. **The lock layer** — `ImportLocks`, `LockAwareInterface`, per-batch
   probe-decided acquisition, and the typed 429 refusal.
7. **The locked-preparation phase** — `LockedPreparableInterface`, which is what
   decoupled category creation from the batch transaction; plus URL rewrite row
   ownership and the `URL_REWRITE` lock. See §8.

Still unbuilt: the delete endpoint, the async/queue mode and its status endpoint,
and integration tests.

## 7. Known risks / decisions to revisit

- **EE (`row_id`) vs CE (`entity_id`):** always go through `MetadataPool` for the link
  field — `ProductEntity::getLinkField()` and `Category::getLinkField()` are the only
  two places that ask, and every other table write resolves through them. Staging-aware
  writes remain out of scope, and the posture is now explicit rather than merely
  documented: `EntityProcessor` refuses to **create** a product when
  `ProductEntity::isStagingEnvironment()` is true (a per-product error, not a batch
  failure), because a new row on a staged catalog needs `sequence_product` plus
  `created_in`/`updated_in` handling. Updates work, since the row already exists. Media
  and every satellite table are written against the product's *current* row with no
  staging-update awareness.
- **Direct DB writes skip plugins/observers** other modules attach to product save. Still
  true at the write layer and still a loud README caveat, but no longer the whole story:
  `ImportEventDispatcher` re-emits `catalog_product_save_commit_after` (default on) and
  `catalog_product_save_after` (also default on) per product, plus four batch-level
  `readydata_import_*` events. Two decisions that come with it are worth revisiting:
  - the dispatched product is a **notification carrier** — no `origData`, no attribute
    set, so `dataHasChangedFor()` reports everything changed and saving it corrupts.
    Read-only by contract, enforced by nothing;
  - `dispatch_save_after` runs **inside** the batch transaction, which hands a third
    party the ability to roll back an import — accepted deliberately, see the defaults
    bullet below.
  - **Categories are the exception to the whole architecture:** they are saved through
    the category model/repository, so category-save plugins and observers DO run — both
    for on-demand creation during a product import and for the category endpoint.
- **Category creation is decoupled from the batch transaction** (was: transaction-coupled,
  fixed in §8.2). `CategoryRepository::save()` opens its own transaction, and Magento's
  adapter counts nesting rather than emitting savepoints — a nested `rollBack()` emits no
  SQL at all, it flags the connection and decrements, so a failed save inside the batch's
  transaction left its partial rows live and the COMMIT died with an unrelated "Partial
  rollback is not supported". Creation therefore runs in its own phase
  (`LockedPreparableInterface`): after the batch's locks, before its transaction. A
  failure is now a per-product warning with the batch intact, and `createBare()` finally
  carries the sibling-slug pre-check the category endpoint always had. What this bought is
  paid for in two ways, both accepted:
  - a rolled-back batch **leaves created categories behind**, and a partially created
    chain persists — the same trade already made for downloaded media files, and nothing
    garbage-collects either;
  - the phase runs before entity IDs exist, so a path is resolved for every *valid*
    product rather than only for those the batch writes. A product `EntityProcessor` later
    rejects can still have caused its categories to be created. Reconstructing the old
    filter was rejected: it covers one of six rejection reasons and puts the predicate
    away from the code it guards.
- **Concurrency:** two simultaneous imports of the same SKUs can corrupt each other
  wherever there is an unkeyed read-then-create. Guarded by named locks (via
  `Magento\Framework\Lock\LockManagerInterface`, see `ImportLocks`): six names, five of
  which the product pipeline can reach — options, product rows, the category tree, the
  gallery, the URL rewrites — plus `ATTRIBUTE_SYNC`, held by the attribute endpoint alone.
  A set is acquired all-or-nothing in one fixed order, per BATCH, held only for that
  batch's transaction and the locked-preparation phase immediately before it — never
  across image downloads, indexing or after-commit events.

  What a batch takes is decided by `LockAwareInterface::requiredLocks()`, which probes
  what is actually **missing** rather than what the payload carries, so a steady-state
  push whose categories and options all exist takes nothing at all and runs fully
  concurrently. `MediaProcessor` is the one step still on payload presence — being exact
  there needs a desired-vs-existing diff against link IDs that do not exist yet at lock
  time. Because the probes read before the lock, a create the batch never reserved for is
  reported rather than performed; the retry's probe sees the gap.

  Two things remain open. **Per-KEY exclusion** instead of named locks would let
  overlapping imports of disjoint SKUs stop contending altogether; it needs an
  isolation-level audit (gap locks do not exist under READ COMMITTED) and a
  two-connection test harness this module does not have. And **`MediaProcessor`'s
  conservatism** is a measured 251 ms held on every batch carrying a `media` field,
  including `[]`.
- **`url_rewrite` rows are now written whole** (was: a lost race produced a cross-wired
  row, fixed in §8.1). The upsert overwrites `entity_id` and `entity_type` as well as the
  target, so a row that changes hands can never claim one product while resolving to
  another — which also removed a *deterministic* single-request bug, where a product
  adopting a not-visible batch sibling's slug had its brand-new URL deleted by the
  not-visible cleanup that follows. `URL_REWRITE` serializes the concurrent case on a
  probe (new SKU, or a declared `url_key` differing from the stored one for that scope),
  and `append` re-checks its generated variant against the taken and claimed sets.
  Residual, all bounded to "the last writer owns the path and the loser simply has no
  rewrite there", self-healing on that product's next import: non-ReadyData writers
  (admin save, CMS page, core's own generation) take no lock; two products with identical
  effective slugs concurrently gaining the same category can still collide on the shared
  category-path rewrite; a deploy-overlap request takes no lock; and an `append` variant
  can still hit a row the batch never queried — closing that last one needs the second
  conflict lookup, which was deliberately deferred.
- **Async mode:** for very large feeds, accept-and-queue (bulk API pattern with
  `operation` status endpoint) is still the planned expansion. Nothing of it exists yet —
  the `products/delete` and `import/:id/status` routes sit commented out in
  `etc/webapi.xml` with no interfaces behind them.
- **Media `value_id` read-back:** a gallery row has no natural key, so the watermark
  re-select is not provably unambiguous — a concurrent **admin** product save can still
  interleave its own rows (the `MEDIA_GALLERY` lock only serializes this module against
  itself; core does not take it). Guarded by three stacked predicates —
  `value_id > watermark`, matching `attribute_id`, no `_value_to_entity` binding — plus a
  positional `value` comparison that THROWS rather than guessing, which rolls the batch
  back: the rows are already inserted and cannot be identified, so degrading would commit
  unbound orphan gallery rows that every retry would duplicate. If it ever fires in the
  field, the escalation is per-row `insert()` + `lastInsertId()` for new files only.
- **Orphan media files:** a rolled-back batch leaves whatever `prepare()` downloaded in
  `pub/media`. Deterministic target paths (sanitised name + a digest of the URL) make
  retries converge on the same file instead of accumulating copies, but nothing
  garbage-collects the unreferenced ones. Likewise `removed_files` on the media event is
  "detached from the products in this batch", not "safe to delete" — a disk-level GC
  needs its own reference check.
- **Media downloads are per-request**, bounded per batch and run concurrently up to
  *Download Concurrency* (default 4). The async path above is still the answer for large
  first-time image imports; until then `batch_size` and `max_execution_time` are what
  keep a request inside its timeout.
- **Image URLs are fetched by the store.** With *Allowed Download Hosts* empty, a
  compromised feed can make the store request any URL it can reach. Extension filtering,
  signature verification, size/timeout/redirect caps and per-hop allow-list enforcement
  are all in place; **DNS rebinding and IP-literal hosts are not solved**. The default
  being an empty allow-list is the decision to revisit.

- **A third-party observer can fail an import, by default.** `dispatch_save_after`
  ships on, so `catalog_product_save_after` fires per product **inside** the batch
  transaction and a throwing observer rolls that batch back. This is the deliberate
  choice — an observer written against core's save timing should fire where it expects
  to, and an importer that silently skips the in-transaction event breaks that contract
  in a way nobody discovers until data is wrong. The exposure is the price, and the
  switch is how a store declines to pay it.

  The same decision is why `hydrate_media` (two queries per batch, so observers see the
  gallery a normal save would carry) and `attributes/auto_create` (the pre-flight
  attribute endpoint live) both ship on. Note the deliberate asymmetry with
  `categories/enabled`, which stays **off**: creating attributes is additive, whereas
  renaming and deleting categories reshapes storefront navigation and URLs
  irreversibly.

  *Resolved 2026-08-17: the defaults are correct and the documentation was wrong. README,
  `system.xml` comments and `Config.php` docblocks were corrected to match, having all
  described these three as off / opt-in.*
- **No integration tests exist** (see §5). The module's entire value is what its SQL does
  to a live schema, and no automated test executes any of it. Two risks in this section —
  the media `value_id` read-back and the isolation-level audit per-key locking needs —
  are explicitly blocked on a harness that does not exist.

## 8. Shipped fixes

§8 was a plan; this is what landed. Both risks it addressed turned out to be worse than
§7 had recorded, and each fix is described where its behaviour now lives — §7 for the
residuals, the README for what a caller sees. Kept here as the record of what was decided
and what was deliberately left.

### 8.1 `url_rewrite` ownership

**Shipped.** `UrlRewrite::REPLACE_UPDATE_COLUMNS` now overwrites `entity_id` and
`entity_type` alongside the target columns, so a row that changes hands under the
`(request_path, store_id)` unique key is rewritten whole and can never claim one product
while resolving to another.

The investigation's finding was that this is not only a race. `findConflicts()` excludes
every valid batch entity, while the pre-insert delete only clears `$touchedEntityIds` — and
a product that went **not visible** in a store is in neither. So a product adopting such a
sibling's slug landed on its still-live row, kept the sibling's `entity_id`, and the
not-visible cleanup that runs immediately afterwards — which deletes by `entity_id` —
removed the URL the batch had just created. Deterministic, single-request, no concurrency.

`ImportLocks::URL_REWRITE` (appended **last** in `ORDER`, matching the pipeline convention
and leaving every existing name's relative order untouched) serializes the concurrent case.
`UrlRewriteProcessor::requiredLocks()` probes on two signals — any new SKU short-circuits,
otherwise a declared `url_key` is compared against the stored value **for its own scope**,
every scope arriving in one indexed read. That exactness is what keeps a localized feed
lock-free. `CategoryLinkProcessor` declares the lock too, on the branch where it creates a
category: core's *category* rewrite observer is not suppressed during an import and claims
paths in the same namespace with the same default `.html` suffix.

`resolveConflict()`'s `append` strategy now checks its generated `<slug>-<id>` variant
against the taken and claimed sets and appends a bounded discriminator, rather than
returning the one path this step wrote having asked nothing about it. Ownership made that
urgent: a collision there used to cross-wire a row and would now take it over outright,
including a CMS page's.

Deliberately **no `holdsLock()` degradation**, unlike every other lock-aware step, and the
docblock says why: refusing the write would leave the product with no URL at all, because
the step deletes its own rows before inserting.

**Declined:** the transitional double-lock for deploy overlap, and the second
`findConflicts()` over appended paths. Both are residuals in §7.

### 8.2 Category creation, decoupled

**Shipped**, and not the way §8.2 proposed. The plan put creation in `prepare()`; it went
into a new phase instead — `LockedPreparableInterface::prepareUnderLocks()`, run by
`ImportService` after the locks are acquired and before `beginTransaction()`.

That placement is what makes the change a pure correctness fix: **zero** edits to
`ImportLocks::ORDER`, `batchLocks()`, `requiredLocks()`, the deadlock argument or the 429
contract, and a lock hold identical to before. The `prepare()` variant would have released
`CATEGORY_TREE` before `MediaProcessor`'s downloads, opening a window in which a concurrent
category sync — which holds that same lock name for its whole request — could delete a
category the batch was about to link, an FK violation that rolls the batch back. That is
the failure class being fixed, reappearing by another route.

One correction to the plan's premise, which strengthened the case: Magento's adapter checks
`_isRolledBack` only in `beginTransaction()` and `commit()`, so it is not true that every
later statement fails. The nested `rollBack()` emits no SQL at all — it flags and
decrements — which means a failed repository save left its partial rows **live** in the
outer transaction. The old re-throw was mandatory, not merely prudent, and
`CategoryPathResolver::resolvePaths()` now refuses outright to run at
`getTransactionLevel() > 0` so dropping it cannot silently regress.

With creation outside the transaction, `createChain()` reports per path instead of throwing,
which reaches the existing additive safety valve; `CategoryWriter::findNewChildConflict()`
(now nullable-definition, gated on the parent being pre-existing) pre-checks the common
slug collision; and `CategoryPathResolver::findVanished()` catches a category deleted
between the two phases, which would otherwise be an FK violation costing the batch.

Accepted with the user: a rolled-back batch leaves created categories, and the phase runs
before entity IDs exist, so a path is resolved for every valid product rather than only for
those the batch writes. Both are in §7 and the README.

### 8.3 Categories through the model

**Unchanged, deliberately.** `CategoryWriter`'s reasoning holds: path/level/`children_count`
maintenance, `url_key`/`url_path` derivation and subtree rewrite cascades would be the
riskiest SQL in the module, and category cardinality never justified the bulk-write
argument. 8.2 removed the harm that leaked out of it — a category-save observer now fires
outside the batch transaction, so it fails products rather than rolling the batch back.

Still open, low priority: `readydata_import/categories/auto_create`, to let an operator with
heavy category observers turn on-demand creation off and require the category endpoint as a
pre-flight step.

### 8.4 What is not proven

The unit suite pins the column list, the probe's scope arithmetic, the phase ordering and
the per-path reporting. It cannot prove the ON DUPLICATE semantics themselves, last-row-wins
within a chunk, the FK cascade, or that the probe and the write agree about what "unchanged"
means — and none of the races. §7's missing two-connection integration harness is unchanged,
and is the reason the ownership fix shipped unconditionally rather than contingent on the
lock.

> **Keeping this document honest.** §1–§6 were rewritten against the code on
> 2026-08-17, after the `[P]` markers and the single-endpoint framing had drifted
> several releases behind. This is a *map*: the architecture, the invariants and the
> open decisions. Per-field payload semantics, config comments and caveats belong in
> the README, which is the reference that ships with the module. When the two disagree,
> the README is the one that gets read.
