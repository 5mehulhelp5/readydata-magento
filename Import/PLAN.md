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
        ├─ 3. transaction    ─────────────────────────────────────────────
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
        ├─ 4. release locks
        └─ 5. dispatchAfterCommit()  catalog_product_save_commit_after +
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
  changes. Two opt-in interfaces extend a step beyond `process()`:
  **`PreparableInterface`** for work that must happen before the transaction opens
  (network, filesystem), and **`LockAwareInterface`** for a step that performs an unkeyed
  read-then-create and must declare which named lock covers it.
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
    │   ├── ImportLocks.php                # the five lock names + acquisition order
    │   ├── ImportState.php                # import-active flag, shared instance
    │   ├── Config.php                     # typed accessor for system config
    │   ├── UrlKeyGenerator.php
    │   ├── Data/                          # DTO implementations of Api/Data
    │   ├── Exception/                     # ImportLocked, MediaReference,
    │   │                                  #   AttributeValidation, CategoryValidation
    │   ├── Processor/
    │   │   ├── ProcessorInterface.php
    │   │   ├── PreparableInterface.php    # opt-in pre-transaction phase
    │   │   ├── LockAwareInterface.php     # opt-in "which lock will I need"
    │   │   ├── AbstractPlaceholderProcessor.php   # base for inert steps; unused
    │   │   ├── AttributeProcessor.php     100
    │   │   ├── EntityProcessor.php        200
    │   │   ├── EavValueProcessor.php      300
    │   │   ├── WebsiteProcessor.php       400
    │   │   ├── StockProcessor.php         500
    │   │   ├── CategoryLinkProcessor.php  700
    │   │   ├── MediaProcessor.php         710  (Preparable + LockAware)
    │   │   ├── LinkProcessor.php          720
    │   │   ├── ConfigurableProcessor.php  730
    │   │   ├── TierPriceProcessor.php     740
    │   │   ├── UrlRewriteProcessor.php    750
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
- **Category writes are transaction-coupled to the batch.** `CategoryRepository::save()`
  opens its own transaction inside the batch's; when it fails, the connection is left
  partially rolled back and every subsequent statement — including the COMMIT — fails
  with an unrelated "Partial rollback is not supported". The failure is therefore
  re-thrown so the batch rolls back cleanly with the real reason. The consequence is that
  a product feed naming a category path whose slug is taken **fails its whole batch**
  instead of reporting that one product, and `CategoryPathResolver`'s on-demand creation
  is deliberately left without the sibling-collision guards the category endpoint has (it
  has no per-entry result row to report a refusal into). Decoupling this would mean
  creating categories outside the batch transaction, which trades a clean rollback for
  committed categories on a failed batch.
- **Concurrency:** two simultaneous imports of the same SKUs can corrupt each other
  wherever there is an unkeyed read-then-create. Guarded by named locks (via
  `Magento\Framework\Lock\LockManagerInterface`, see `ImportLocks`): five names, four of
  which the product pipeline can reach — options, product rows, the category tree, the
  gallery — plus `ATTRIBUTE_SYNC`, held by the attribute endpoint alone. A set is acquired
  all-or-nothing in one fixed order, per BATCH, held only for that batch's transaction —
  never across image downloads, indexing or after-commit events.

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
- **`url_rewrite` conflict resolution is not covered by any lock.** The table is unique on
  `(request_path, store_id)`, so concurrency cannot duplicate a rewrite — but the
  conflict set is read before the write, which makes the `error` and `append` strategies
  unreliable under concurrency and can leave the loser's request path pointing at the
  other product. Per-batch, probe-decided locking deliberately widened that window in
  exchange for throughput. The fix is either a lock of its own or upsert-and-retry on the
  unique key; neither is written.
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

## 8. Planned fixes

Three of §7's risks have a concrete remedy. They are written up here rather than fixed in
place because two of them move the lock layer, and the order they land in matters.

### 8.1 `url_rewrite`: a lost race writes a cross-wired row

**What actually happens.** `url_rewrite` is unique on `(request_path, store_id)`, and
`UrlRewrite::replaceProductRewrites()` upserts with the update list
`['target_path', 'redirect_type', 'is_autogenerated', 'metadata']` — **`entity_id` is not
in it**. So when two imports both read a path as free:

| | |
|---|---|
| import A commits | `(P, S, entity_id = A, target = …/id/A)` |
| import B commits | ON DUPLICATE fires → `target = …/id/B`, **`entity_id` stays A** |

The row now claims to belong to A and points at B. Three things follow, and the middle one
is the bad one:

1. the storefront serves `P` as product B;
2. A's next import calls `deleteAutogenerated([A], [S])`, which matches on `entity_id` and
   **deletes B's live URL** — from a request that never mentioned B;
3. `saveProductCategoryRelations()` reads `entity_id` and writes a category relation with
   `product_id = A` for a rewrite that resolves to B.

This is concurrency-only. Within one request it cannot happen: `$claimed` blocks
self-collision inside a batch, and a later batch sees the earlier batch's row through
`findConflicts()`, because the exclusion list is only the *current* batch's entities.

**Part 1 — make the row self-consistent.** Add `entity_id` to the update column list. A
lost race then yields a coherent row wholly owned by the last writer: the loser simply has
no rewrite in that store, which its next import regenerates through the ordinary conflict
path. No cross-wired row, no cross-product delete, no bogus relation.

The only other way a duplicate can be hit is a preserved 301 row of *our own* entity whose
`request_path` equals a path we are now claiming (the delete spares `redirect_type != 0`)
— there `entity_id` is already ours and writing it changes nothing. So the new column
affects the race and nothing else. One line, no behavioural change outside the race, ships
first and independently.

**Part 2 — a probe-decided `URL_REWRITE` lock.** Add `ImportLocks::URL_REWRITE`
(`readydata_url_rewrite`), last in `ORDER` — the order is the pipeline's, and
`UrlRewriteProcessor` sorts at 750. Have the processor implement `LockAwareInterface`.

The exact question ("will this batch claim a path it does not already own?") is not
answerable at probe time: entity IDs for new products, `CONTEXT_WEBSITE_IDS` and
`CONTEXT_LINK_IDS` do not exist until the transaction runs. The same wall `MediaProcessor`
hit. So the probe is a sound over-approximation — take the lock when

- any SKU in the batch is new (a new product always claims paths), **or**
- a payload `url_key`, default-scope or store-scoped, differs from the stored one (one
  bulk EAV read that `resolveUrlKeys()` largely performs anyway), **or**
- the batch changes category assignments *and* the store has
  `generate_category_product_rewrites` on;

otherwise skip. The fast paths stay lock-free: a price or stock refresh carries no
`url_key` and no categories, and a steady-state feed echoing an unchanged `url_key`
compares equal. That is the same "probe what is actually missing" reasoning
`EntityProcessor` and `CategoryLinkProcessor` already use, and it preserves the contention
win §7 describes.

**The residual is permanent.** A named lock serializes this module against itself only — a
concurrent admin save or a core `UrlRewriteHandler` run can still take a path between our
probe and our write. Part 1 is what makes that outcome non-corrupting, which is why it is
worth doing even though Part 2 follows. Document it exactly as the media `value_id`
residual is documented.

Rejected: catching the duplicate-key error and retrying. `insertOnDuplicate` never raises
one, so detection would mean per-row `insert()` plus a 23000 catch — abandoning the bulk
write on the module's hottest table to fix a race the two parts above already contain.

### 8.2 Category creation: move it into `prepare()`

**Why it fails a whole batch today.** `CategoryPathResolver::createChain()` calls
`CategoryWriter::createBare()` → `CategoryRepository::save()`, which opens its own
transaction *nested inside* the batch's. Magento's adapter counts nesting rather than
emitting savepoints, so the inner `rollBack()` flags the connection partially-rolled-back
and every later statement — the COMMIT included — dies with "Partial rollback is not
supported" instead of the real cause. The re-throw is the only correct move once you are
there; the cost is a batch lost to one bad path, and a `createBare()` that cannot carry
the category endpoint's sibling-collision guards because it has no per-entry result row to
refuse into.

**The fix is an extension point the module already has.** `PreparableInterface` runs
before the transaction opens, holds the `BatchContext`, reports per-product problems
through `addMessage()`/`fail()`, and documents that a throw "fails the whole batch — but
before any transaction exists, so it is reported without a rollback". That is precisely
the contract this needs. Make `CategoryLinkProcessor` implement it and, in `prepare()`:

1. collect the batch's paths — `collectReferences()` already does this for the lock probe;
2. resolve them, and create the missing tails **at the top level**, where the repository's
   transaction is outermost and resolves cleanly on its own;
3. report a creation failure against the product: `fail()`, or the existing additive-mode
   warning. The batch survives;
4. stash the path→ID map on the context bag; `process()` consumes it instead of resolving
   a second time.

Three consequences, all to be stated rather than discovered:

- **`CATEGORY_TREE` moves with it.** The lock must span the miss-read *and* the commit
  that publishes the new row, and that commit now happens in `prepare()`. Take it there,
  around the creation step only, and release it before the transaction-phase set is
  acquired — no lock is ever held across both acquisitions, so the fixed-order deadlock
  argument is untouched. `CATEGORY_TREE` then leaves `batchLocks()` and the
  transaction-phase `ORDER`. The category endpoint's whole-request hold is unaffected.
- **A rolled-back batch leaves created categories behind.** The same trade already made
  for downloaded media files, with the same mitigation — creation is idempotent, so the
  retry resolves them instead of duplicating them — and a product-less bare category is
  inert. It earns a README line next to the orphan-media one.
- **`createBare()` can finally be guarded.** With a per-product result row available,
  `CategoryWriter::findNewChildConflict()` — already written, already used by the category
  endpoint — runs before creating, turning today's batch-killing URL-key conflict into a
  per-product warning. That closes the asymmetry the README currently documents as
  deliberate.

Ordering falls out for free: `prepareBatch()` walks preparables in `getSortOrder()`, and
`CategoryLinkProcessor` (700) precedes `MediaProcessor` (710), so a category failure is
found before the batch spends its downloads.

Rejected: wrapping the repository call in a `SAVEPOINT`. The adapter counts nested
transactions and emits no savepoints, so this means raw `SAVEPOINT` SQL around code that
itself calls `beginTransaction()` — an interaction with the adapter's internal counter
that is exactly the sort of thing a minor Magento upgrade breaks silently.

### 8.3 Categories through the model: contain it, do not remove it

Not a defect. `CategoryWriter`'s reasoning holds — path/level/`children_count`
maintenance, `url_key`/`url_path` derivation and subtree rewrite cascades would be the
riskiest SQL in the module, and category cardinality never justified the bulk-write
argument. Keep the exception. Two things leak out of it, though:

- **The transaction coupling**, which is 8.2 and is the whole of the harm.
- **The observer asymmetry.** Product-save events are ours to gate — the event layer has
  a switch per event, and a store that cannot afford an in-transaction observer turns
  `dispatch_save_after` off. A category save fires everything unconditionally, including
  for a category a *product* import auto-created, with no switch anywhere. A store with
  an expensive or throwing category-save observer therefore gets it invoked
  mid-product-import, inside the batch transaction — the same hazard `dispatch_save_after`
  carries, minus the ability to decline it.
  8.2 downgrades it from "rolls back the batch" to "fails these products", which is a
  second reason it is the right shape. The residual belongs in §7 and the README.

Optional and low priority: `readydata_import/categories/auto_create` (default on), so an
operator with heavy category observers can switch on-demand creation off entirely and make
the category endpoint a required pre-flight step.

### 8.4 Order and provability

1. **8.1 Part 1** — one line, independent, no lock changes. Ship first.
2. **8.2** — the largest change; unlocks the `createBare()` guard.
3. **8.1 Part 2** — after 8.2, because both edit `ImportLocks::ORDER` and 8.2 changes what
   `batchLocks()` returns.
4. **8.3** — documentation, plus the optional flag.

Unit coverage extends to all of it: the update-column list is assertable directly, and
8.2's per-product reporting is a throwing `CategoryWriter` double. **None of the race
behaviour is provable without the integration harness §7 names as missing** — which is the
argument for 8.1 Part 1 being unconditional rather than contingent on the lock landing.

> **Keeping this document honest.** §1–§6 were rewritten against the code on
> 2026-08-17, after the `[P]` markers and the single-endpoint framing had drifted
> several releases behind. This is a *map*: the architecture, the invariants and the
> open decisions. Per-field payload semantics, config comments and caveats belong in
> the README, which is the reference that ships with the module. When the two disagree,
> the README is the one that gets read.
