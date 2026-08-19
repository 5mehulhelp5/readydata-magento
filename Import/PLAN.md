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
        ├─ 6. cleanUpAfterCommit() / cleanUpAfterRollback()  BatchCleanupInterface
        │                    steps release what the transaction could not roll
        │                    back. Outside the event layer on purpose (see §9.2)
        └─ 7. dispatchAfterCommit()  catalog_product_save_commit_after +
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
  changes. Four opt-in interfaces extend a step beyond `process()`:
  **`PreparableInterface`** for work that must happen before the locks and the transaction
  (network, filesystem); **`LockAwareInterface`** for a step that performs an unkeyed
  read-then-create and must declare which named lock covers it;
  **`LockedPreparableInterface`** for a step whose write goes through a repository that
  opens a transaction of its own, which cannot nest inside the batch's; and
  **`BatchCleanupInterface`** for a step holding something the transaction cannot roll
  back — today the media files a commit orphaned or a rollback stranded.
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
    │   ├── MediaReferenceCheckerInterface.php  # not a route; for event consumers
    │   └── Data/                          # 27 interfaces: product, attribute,
    │                                      #   category, scoped-result and Amasty DTOs
    ├── Console/
    │   └── ReportOrphanMediaCommand.php   # readydata:media:report-orphans, read-only
    ├── Observer/                          # §9.2, product-delete media cleanup
    │   ├── CaptureProductMediaOnDelete.php    # reads paths before the transaction
    │   └── CleanUpProductMediaAfterDelete.php # deletes after it commits
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
    │   │   ├── BatchCleanupInterface.php  # opt-in post-commit/post-rollback release
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
    │   │   ├── Website.php
    │   │   └── MediaOrphanScan.php        # §9.1's two temporary tables + joins
    │   ├── Media/
    │   │   ├── FileResolver.php           # validates + signature-verifies, writes
    │   │   ├── HostAllowList.php          # per-redirect-hop enforcement
    │   │   ├── DownloaderInterface.php
    │   │   ├── PooledDownloader.php       # bounded concurrent fetch (Guzzle Pool)
    │   │   ├── MediaReferenceChecker.php  # "still referenced?", for removed_files
    │   │   └── Cleanup/                   # the §9.1 report; deletes nothing
    │   │       ├── MediaPathNormalizer.php  # where disk and DB path forms meet
    │   │       ├── FileWalker.php           # per-directory descent, batched
    │   │       ├── OrphanScanner.php        # walk, then references; never reversed
    │   │       ├── OrphanReport.php
    │   │       ├── MediaCleanupService.php  # §9.2: the ONE place a file is deleted
    │   │       └── DeletedProductMedia.php  #   carries paths across a product delete
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
8. **The media reference check** — `MediaReferenceCheckerInterface`, so a consumer
   of `removed_files` can decide safely instead of guessing. Not a route; a PHP
   contract for event observers. See §7's orphan-media entry and §9.
9. **The orphan-media report** — §9.1, the module's first console command. Read-only:
   it answers how much of `pub/media/catalog/product` nothing points at, which is both
   the audit that §9.2's assumption still holds and the number that decides whether the
   existing backlog is worth clearing.
10. **Media cleanup at source** — §9.2, the module's first observers and its fourth
    opt-in processor interface (`BatchCleanupInterface`). Off by default behind
    *ReadyData Owns Product Media*; when on, a file is deleted as soon as it stops
    being referenced — on detach, on rollback, and on product delete — through one
    `MediaCleanupService`. Also registers core's unwired `Gallery\DeleteHandler`.

Still unbuilt: the delete endpoint, the async/queue mode and its status endpoint,
integration tests, and §9.2's backlog mode (`--delete`), which waits on a production
run of §9.1.

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
- **Orphan media files are accepted, and the module deletes nothing from `pub/media`.**
  This is a scoping decision, not a missing feature, and it rests on where the orphans
  actually come from.

  A rolled-back batch leaves whatever `prepare()` downloaded on disk, because downloads
  run before the transaction opens and a file write cannot be rolled back. But
  deterministic target paths (sanitised name + a digest of the URL) make that
  self-healing: the retry resolves to the same path, `skip-if-present` adopts the file
  without a request, and the gallery diff matches the stored row. The orphan only
  persists if the SKU is never pushed again.

  The larger pile is not ours and cannot be. **Core never cleans up after a product
  delete** — `Magento\Catalog\Model\Product\Gallery\DeleteHandler` exists but is not
  wired into the entity manager's `delete` actions (`ExtensionPool` in
  `module-catalog/etc/di.xml` registers gallery handlers under `read`/`create`/`update`
  only) — so deleting a product cascades away its `_value` and `_value_to_entity` rows,
  leaves the **main gallery row** behind (its only FK is on `attribute_id`), and leaves
  the file and its `catalog/product/cache` renditions untouched.

  What *is* implemented is the primitive every cleanup option needs:
  `Api\MediaReferenceCheckerInterface` (see the README), so a consumer of
  `removed_files` can decide safely instead of guessing. Note that core's own
  `Gallery::countImageUses()` cannot serve this purpose — it counts rows by path with no
  regard for binding, so the dead rows a product delete leaves behind report every such
  file as permanently in use. Requiring the `_value_to_entity` binding is the fix, and it
  is also what makes core's unwired handler worth wiring rather than reimplementing.

  **Deleting anything is out of scope for this bullet and specified in §9.** The reasoning
  moved twice while that section was written, and the record is worth keeping because both
  turns were driven by facts rather than taste. It first argued for a store-wide sweep with
  a quarantine table and cron, on the grounds that a cleanup scoped to what this module
  recorded could never collect core's product-delete leftovers. That holds only while the
  catalogue has other writers. Told that this importer owns `pub/media/catalog/product`
  outright, the quarantine, the cron and the schema all fall away: every orphan then comes
  from an event the module itself performed, and can be cleaned up on the post-commit hook
  that already exists. §9.2 is the result; §9.1, the read-only report, shipped first and
  remains the audit that proves the assumption is still true.
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

## 9. Planned: media cleanup

The driver is **disk usage**. §9.1 is built; §9.2 is not. They answer two different
questions and only one of them is gated on the other.

**§9.2 prevents new orphans; §9.1 measures the ones already there.** Prevention is worth
doing whether or not the disk figure is alarming, because the alternative is a pile that
only grows — and on a store where the importer owns the catalogue it is cheap, because the
module already knows the moment a file stops being referenced. Clearing the existing
*backlog* is the part that is gated: if §9.1 reports little, leave it.

**Check two things before building any of it.** `pub/media/catalog/product/cache` is
*derived* — renditions regenerate on request, and `catalog:images:resize` pre-warms them.
It needs no reference check, no quarantine and no cron; it can be deleted wholesale in a
maintenance window. On a store that has changed themes or breakpoints a few times, stale
renditions for dimensions nothing requests any more are routinely the majority of the
directory, and if that is where the gigabytes are then this whole section is unnecessary.
Worth a `du` at the same time: `pub/media/import` (core importer's source-image dump,
which this module never writes, but a store that ever used core import may have filled),
`pub/media/tmp` and `var/`. "Orphaned source images are the problem" is a hypothesis;
phase one confirms or kills it.

**The whole-tree scan is not tied to the import.** A walk of the entire media directory hung
off a request that already fights `max_execution_time` and `batch_size` is the wrong budget,
and it would make an import request responsible for files unrelated to its payload. That is
why §9.1 is a console command. §9.2's per-batch cleanup is the opposite case: it acts only on
the files the batch in front of it just detached, which is bounded by the payload.

### 9.1 Phase one: report only

Answers one question with numbers — **how much of `pub/media/catalog/product` is
unreferenced, and how old is it** — and deletes nothing, adds no schema, schedules no cron
and adds no configuration. (Two MySQL `TEMPORARY` tables exist for the duration of a run;
nothing is installed.) Its output decides whether the existing **backlog** is worth clearing;
§9.2's prevention of new orphans does not wait on it.

Under `Model/Media/Cleanup/`: `OrphanScanner` (orchestrator), `MediaPathNormalizer`,
`FileWalker`, `OrphanReport`, plus `Model/ResourceModel/MediaOrphanScan` for the SQL —
every other class in this module that talks to the database lives there and this is not
the one to make an exception of. Entry point is a console command,
`readydata:media:report-orphans`, registered via `Magento\Framework\Console\CommandList`
— a new `Console/` directory, which §9.2's backlog mode extends with a `--delete` flag
rather than duplicating.

**Walk the disk first, read references second.** This ordering is load-bearing, not
incidental: references then only ever grow relative to the candidate snapshot, so the skew
from a concurrent import pushes files toward "referenced". Reversed, a file written and
committed between the two passes is reported as an orphan, which is the direction that
does harm.

**Two temporary tables, and nothing large held in PHP.** The difference the tool reports
has one side that is not in the database at all, so the disk must be enumerated by a walk
either way. Asking per file is correct and unusable — there is no index on
`catalog_product_entity_media_gallery.value`, so each lookup is a full table scan, and half
a million files is half a million scans. (That is exactly why
`MediaReferenceCheckerInterface` is right for a batch's `removed_files` and wrong here.)
So: one table of disk candidates, one of references, each source inserted with a single
`INSERT IGNORE ... SELECT` that scans its table once, and every reported number an indexed
join in both directions.

```
readydata_media_scan_candidate   path VARBINARY(255) PRIMARY KEY, size BIGINT, mtime INT
readydata_media_scan_reference   path VARBINARY(255), source TINYINT, PRIMARY KEY (path, source)
```

`VARBINARY` rather than `VARCHAR` is deliberate. `Mysql::setDefaultCharsetAndCollation()`
injects the default charset and collation into every column whose type matches its
`COLUMN_TYPE` list (`varchar|char|text|mediumtext|longtext`), and below MySQL 8.0.29 that
default is `utf8mb3` — against `utf8mb4` core columns MySQL then either coerces the temp
side, which is the only side with an index, or throws `1267 Illegal mix of collations`.
`varbinary` is not in the list, so nothing is injected, and byte-exact comparison is also
the correct semantics for a Linux filesystem: the `_ci` default would treat `/a/b/Foo.JPG`
and `/a/b/foo.jpg` as one path. The reference PK must lead with `path`, not `source`, or
the anti-join cannot use it.

Two sources:

| Source | Query | Stored form |
|---|---|---|
| Bound gallery rows | `..._media_gallery g INNER JOIN ..._value_to_entity b ON b.value_id = g.value_id`, no `WHERE` | `/a/b/x.jpg` |
| Role attributes | `catalog_product_entity_varchar WHERE attribute_id IN (roles)` | `/a/b/x.jpg` |

Both store the canonical form already, so normalisation only has to reconcile the disk
against it. That is still the most bug-prone part of this component — it deserves its own
tested class (`MediaPathNormalizer`) and it fails in the direction that hurts, a mismatch
reporting referenced files as orphans.

**There was a third source, and removing it is the point worth recording.** A pass over
`media_gallery_asset INNER JOIN media_content_asset` would find CMS pages and blocks that
reference an image. It was written, then removed, because it cannot do the job on this
configuration: `Magento_MediaGalleryCatalog`'s `etc/directory.xml` excludes
`/^catalog\/product/` from media-gallery synchronisation, enforced in
`FetchMediaStorageFileBatches::isApplicable()`, so `media_gallery_asset` never holds a
product image and `media_content_asset` can only link assets that exist. It would not have
returned zero because there are no CMS references — it would return zero either way, which
is worse than not asking.

The consequence stands whether or not the pass exists, and the command says it
unconditionally rather than only in a docblock: a `{{media url="catalog/product/..."}}`
reference in a CMS page or block leaves no queryable row, so **the unreferenced count is an
upper bound**. §9.2's ownership assumption is what makes that acceptable rather than
alarming.

**`FileWalker`** descends `catalog/product` one directory at a time through the media
directory's driver, never PHP filesystem primitives, so remote storage works (the rule
`FileResolver` already follows). Per-directory `read()` rather than `readRecursively()`,
which materialises the entire tree *and* sorts it — there is no generator walk in the
framework, so the bounded traversal has to be written here. It emits batches of path, size
and mtime.

`cache`, `watermark` and `placeholder` are excluded, matched on the top-level path segment
under `catalog/product`, as a `private const` and not configuration. `cache` is why: no
rendition is referenced by any DB row, so the whole subtree classifies as orphaned.
Harmless in report mode, catastrophic-looking in 9.2, and it would blow every per-run cap
at once. A setting whose one wrong value produces that outcome is not worth the
flexibility. They are still walked for count and bytes — the `cache/` line is the headline
number, and it cannot be produced without visiting the files. `--skip-excluded-sizing`
opts out when that second walk is too slow to be worth it.

Everything excluded is counted, not silently dropped, and the report breaks the excluded
bytes down per directory. The case the list cannot anticipate — a third-party module
writing its own subtree under `catalog/product` — is not excluded at all: its files are
walked like any others and, being referenced by nothing, surface in the orphan count. That
is the right default for a report. If one ever turns up in the output, the answer is to add
it in code alongside the other three, having looked at what it is.

**No depth bound**, beyond a runaway cap. The dispersed shape `/x/y/name.ext` is what
`Uploader::getDispersionPath()` produces, but it is a *classification*, not a filter: a
gallery `value` may be any relative path, and M1 migrations and third parties do put files
at other depths, so refusing to descend would report a referenced file as missing. Note
also that one-level dispersion effectively does not occur — the loop runs over the filename
*including* its extension and maps a leading `.` to `_`, so `a.jpg` yields `/a/_`, not
`/a`; it takes a one-character extensionless name to get a single level.

Two traps in deriving the canonical form. `File::getRelativePath()` silently returns the
path unchanged when it does not start with the base, so a failed prefix test must be
rejected explicitly rather than assumed well-formed; and `Mysql::_connect()` sets
`SQL_MODE=''`, so a path over 255 bytes would be truncated silently into the candidate
primary key and manufacture false orphans — segregate and count those instead. Directory
symlinks need the containment check `FileResolver` already performs, plus a visited set, or
a loop inside `catalog/product` never terminates.

**What the report must show:**

- Total files and bytes under `catalog/product` **excluding `cache/`**, with `cache/` on
  its own line. That comparison is the first thing to read and may end the project.
- Referenced vs unreferenced, as counts and bytes.
- Unreferenced bucketed by mtime age (`<7d`, `7–30d`, `30–180d`, `>180d`). A file three
  days old is plausibly an in-flight import; the recoverable disk is the oldest bucket.
- **Per-source overlap counts** — how many candidates each source accounts for. Overlap,
  not "rows eliminated by this pass", which would depend on the order the passes ran in and
  report near-zero for every source after the first.
- **References whose file is not on disk, per source, AND how many candidates matched.** The
  trust guard, and the single most valuable pair of numbers here. If path normalisation is
  broken, the miss rate is ~100% of the gallery source and every other figure is garbage.

  The rate alone cannot say that, though, and treating it as if it could was the guard's
  first mistake: a staging copy with a production database and a pruned media directory
  produces the same ~100%. Measured on this repo's own checkout — 16,614 gallery references,
  2 files on disk, both matched — a rate-only guard condemned a report that was entirely
  correct.

  **The discriminator is whether any candidate matched.** Some matches mean the two
  conventions agree and the misses are files this environment does not have, so the orphan
  count stands: report it as missing media, exit zero. No matches while files *were* present
  means nothing lines up: loud banner, non-zero exit. Neither claim is available when the
  media directory is empty, and the guard says nothing then rather than citing evidence it
  does not have.
- Count of unbound gallery rows — core's product-delete leftovers, quantified. Once §9.2
  wires core's `Gallery\DeleteHandler` this doubles as the regression check that the
  registration is working: the number should stop growing.
- The upper-bound caveat, unconditionally.

**Guards:** refuse outright when database media storage is enabled
(`StorageDatabase::checkDbUsage()`), matching `FileResolver`. Refuse on remote storage
unless explicitly overridden — not for the HEAD-per-file cost, which is merely slow, but
because `AwsS3::stat()` persists every stat into the Magento cache backend, and half a
million of those evict the live config and block caches on a Redis LRU. A read-only report
has no business risking that, and an operator on S3 has better tools for the disk half of
the question anyway.

**Tests:** the three-way path normalisation and the walker's exclusion and shape rules,
both pure logic and both places where a wrong answer would be quiet. One case should assert
the canonicaliser reproduces `Uploader::getDispersionPath($n) . '/' . $n` exactly — that is
what binds the disk convention to the DB convention. Queries get the same mocked-connection
treatment as `ProductMediaGallery::findReferencedFiles()`.

Roughly two days with tests. Non-destructive throughout, so it can go onto production and
answer the question within a day of merging.

### 9.2 Phase two: prevention at source

Built, except for the backlog mode below. It rests on an assumption the operator asserts
rather than one the module infers: **nothing but this importer writes to `pub/media/catalog/product`.** Where that
holds, an orphan is never a mystery to be discovered by sweeping — it is the direct result
of an event the module itself just performed, and can be cleaned up in the same breath. One
config flag, named after the assumption it encodes, enables the two hooks below; a store
that starts uploading product images in admin turns it off.

That assumption is why this phase has **no quarantine table, no cron, no admin UI and no
schema at all**. Those exist to buy confidence that a file found by a sweep is really dead.
A hook firing immediately after the batch that detached the file does not need to buy that
confidence — it already knows.

One shared `MediaCleanupService` behind every hook — and there are three callers, not two:
the batch's post-commit phase, the batch's rollback path, and the product-delete observer.
That alone rules out putting the logic in any one of them.

It also has to be a service rather than an observer for a reason that outlives this section:
the module writes products by direct SQL, so a future `products/delete` endpoint (§7,
unbuilt) will very likely delete by direct SQL too, and no model event will fire. If the
logic lives in a service the endpoint calls it and inherits the behaviour; if it lives inside
an observer the endpoint silently reintroduces the problem this was written to solve.

#### The reference check stays load-bearing

Tempting to drop — if we own every file and we just detached this one, why ask? Because
target paths are a deterministic function of the source URL, so two SKUs fed the same image
URL **share one file on disk**. Detaching from product A must never delete what product B
still displays. Every deletion below therefore goes through
`MediaReferenceCheckerInterface` first, and that is the justification for having built it as
a primitive of its own.

#### Case 1 — images detached by the importer

Everything needed is already in place: `MediaProcessor` computes the exact per-SKU `removed`
set, the checker answers whether anything else still holds the file, and `ImportService`
already has a post-commit phase. Cleanup runs there, never inside the batch transaction — a
file delete cannot be rolled back.

**Hook `ImportService`'s `if ($committed)` block directly, as a sibling of
`dispatchAfterCommit()` — not the media event, and not inside `ImportEventDispatcher`.**
That method opens with `if (!$this->config->isDispatchProductEvents()) { return; }`, so an
implementation that rides it inherits a flag about *third-party observers*. A store that
turns product events off — a legitimate thing to do — would then silently stop cleaning up
its media, with no symptom but the disk growing again. Two unrelated concerns must not be
joined by an implementation detail, least of all one that fails quietly.

There is a second reason to sit beside the dispatch rather than inside it. The dispatch is
internally guarded so that an observer's failure cannot fail the import, because an observer
is someone else's code doing an unknown amount of work. The cleanup is *ours*: it should not
hide behind a guard written for strangers, and a third party should not be able to reorder
or suppress it.

Feed it `removed_files`, not the per-SKU `removed`. The batch-level union already excludes a
file that one product dropped while another in the same payload kept or gained it; the
per-SKU list is deliberately unfiltered and would delete a sibling's image. The `partial`
safety valve then falls out for free: `MediaProcessor` computes
`$removedFiles = $partial ? [] : array_keys($goneByFile)`, so a product whose desired set was
incomplete contributes nothing to either list and a batch that withheld removals deletes
nothing.

#### Case 1b — batches that roll back

The post-commit hook only fires when the batch committed. A batch that rolled back has
downloaded files and bound nothing, so there is no detach to react to and prevention would
miss it — the one remaining way this module can leak a file.

`FileResolver` already knows exactly which files it created: everything it actually fetched,
as opposed to the ones skip-if-present adopted. Reporting that set back lets `ImportService`
delete it from the `catch` block that rolls the batch back, which closes the gap and makes
prevention complete — detach, product delete and rollback being the only three ways an
orphan can originate here.

This was rejected once, as a compensating delete that might remove a file a *concurrent*
batch had adopted in the meantime. That rejection does not survive alongside the rest of this
section: the detach path accepts exactly the same race and justifies it by the repair
described below. The same justification applies unchanged, so either both are acceptable or
neither is — and the repair argument holds for both.

#### Case 2 — product deleted

Two mechanisms, both verified against core.

**The orphaned rows are a one-line fix using core's own code.** `Gallery\DeleteHandler`
exists but is registered nowhere; `DeleteExtensions::execute()` reads
`extensionPool->getActions($entityType, 'delete')`, and `ResourceModel\Product::delete()`
does route through `EntityManager`. So adding a `delete` key to `ExtensionPool`'s
`extensionActions` for `ProductInterface` in this module's `di.xml` activates it. Nothing
supplies `media_attribute_codes` on delete, so only `deleteGallery()` runs — and nothing is
lost, because `catalog_product_entity_varchar` cascades on `entity_id` anyway.

**The files need a post-commit hook, and there is a clean one.**
`ResourceModel\Product::delete()` dispatches `catalog_product_delete_after_done` *after*
`EntityManager::delete()` has returned, i.e. after its transaction committed. Capture the
product's gallery paths on `catalog_product_delete_before`, then check and delete on
`..._after_done`. Note `catalog_product_delete_after` is **not** the hook: it is dispatched
inside the EntityManager transaction.

#### The one race, and why it needs no grace period

Total ownership makes this *more* likely rather than less, because concurrent imports of one
catalogue are precisely what this module is built for.

Batch A detaches file X; its post-commit cleanup deletes it. Batch B, running concurrently,
resolved X moments earlier, found it on disk, skipped the download, and is about to bind a
gallery row to it. B commits a row pointing at a file that is gone. Re-checking references
immediately before unlinking narrows the window to seconds but cannot close it — B has not
committed its row yet. The rollback path in 1b has the identical shape, with A discarding
what it downloaded instead of what it detached.

Left open deliberately, because it repairs itself. On that SKU's next import
`FileResolver::resolve()` finds `isExist()` false for the same deterministic target path and
downloads it again. The consequence is one product missing one image until its next feed run.
The deterministic-path decision, made to stop two suppliers' `hero.jpg` colliding, is what
turns a permanent inconsistency into a temporary one — and it is why a quarantine would be
buying protection against something that already heals.

#### Also required

Purge each deleted file's renditions with core's
`RemoveDeletedImagesFromCache::removeDeletedImagesFromCache()`, which resolves the frontend
view config itself (`getViewConfig(['area' => AREA_FRONTEND])`) and so does not depend on the
caller's area. It covers only image types **currently configured in `view.xml`**; renditions
for dimensions no longer configured are untouched by it or anything else here, which is why
wholesale deletion of `cache/` remains a separate and better answer for that disk.

A failed deletion must not fail anything — the batch or the product delete has already
committed. Log it and move on, as `FileResolver::discard()` already does for part files.

#### The backlog

Files predating the flag. Gated on §9.1's number: if it is small, leave them. If not,
`--delete` on `readydata:media:report-orphans`, with the reference re-verify, an mtime age
gate and a per-run cap, run once. §9.1's trust guard must be a hard **refusal** in that mode
rather than the warning it is in report mode — clearing a backlog against a half-restored
database is as wrong as any cron doing it.

Only the broken branch refuses, though. The missing-media branch must not: a store with some
images absent from disk is ordinary, and refusing on it would mean a backlog can never be
cleared anywhere it matters. That is precisely why the two cases had to be told apart rather
than sharing one rate threshold.

#### What it still will not see

A `{{media url="catalog/product/..."}}` reference in a CMS page or block produces no row any
reference source can see (§9.1). Under this section's assumption that requires someone to
have discovered a feed-generated path and pasted it, which is why it is accepted rather than
engineered around — but it is the reason the flag exists as a flag.

And §9.1 keeps a job after §9.2 ships: it is the audit that proves prevention is holding. If
its unreferenced count stops growing, the flag is doing what it claims. If it climbs,
something is writing to `catalog/product` that the assumption says should not be.

Implemented as `Model/Media/Cleanup/MediaCleanupService` behind
`Model/Processor/BatchCleanupInterface` (post-commit and post-rollback, called from
`ImportService`, deliberately not from the event dispatcher) and the two observers in
`etc/events.xml`. The backlog mode remains unwritten and is blocked on a production run of
§9.1 rather than on a decision.

> **Keeping this document honest.** §1–§6 were rewritten against the code on
> 2026-08-17, after the `[P]` markers and the single-endpoint framing had drifted
> several releases behind. This is a *map*: the architecture, the invariants and the
> open decisions. Per-field payload semantics, config comments and caveats belong in
> the README, which is the reference that ships with the module. When the two disagree,
> the README is the one that gets read.
