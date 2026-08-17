# ReadyData Magento 2 Bulk Product Import Module — Implementation Plan

## Goal

A Magento 2 module (`ReadyData_Import`) exposing a REST endpoint that accepts batches of
product JSON (default 500 products per request/batch, configurable) and imports them via
**direct database writes**, bypassing `Magento\Catalog\Model\Product` save and the stock
`ImportExport` framework for performance.

Target: thousands of products per minute on commodity hardware. All heavy paths must use
multi-row `INSERT ... ON DUPLICATE KEY UPDATE`, batched lookups, and in-memory metadata
caches. No per-product model instantiation, no per-product events, no per-product queries.

---

## 1. High-level architecture

```
POST /rest/V1/readydata/products
        │
        ▼
Api\ProductImportInterface (Web API service contract)
        │  validates auth (ACL) + payload shape
        ▼
Model\ImportService (orchestrator)
        │  splits payload into batches (config: batch size, default 500)
        │  wraps each batch in a DB transaction
        ▼
Processor pipeline (sorted pool, injected via di.xml — the extension point)
        ├─ AttributeProcessor        ensure attributes/options exist, cache metadata
        ├─ EntityProcessor           catalog_product_entity rows (create/update)
        ├─ EavValueProcessor         *_varchar/int/decimal/text/datetime value tables
        ├─ WebsiteProcessor          catalog_product_website
        ├─ StockProcessor            cataloginventory_stock_item + MSI inventory_source_item
        ├─ UrlRewriteProcessor       url_rewrite (+ url_key generation/dedup)
        ├─ CategoryLinkProcessor     catalog_category_product            [placeholder]
        ├─ MediaProcessor            gallery tables + file handling
        ├─ LinkProcessor             related/upsell/crosssell            [placeholder]
        ├─ ConfigurableProcessor     super link/attribute tables         [placeholder]
        └─ TierPriceProcessor        catalog_product_entity_tier_price
        │
        ▼
Model\Indexer\InvalidationHandler
        │  partial reindex by entity IDs, or mark invalid (configurable)
        ▼
Response: per-SKU results {sku, entity_id, status: created|updated|error, messages[]}
```

Design rules:

- Every processor implements `ProcessorInterface` and receives the **whole batch** plus a
  shared `BatchContext` (SKU→entity_id map, attribute metadata, store/website maps).
  Processors never loop-query; they bulk-read and bulk-write.
- Adding functionality later = adding a processor to the `di.xml` pool. No orchestrator changes.
- Each batch is one DB transaction: a failed batch rolls back and is reported; other
  batches proceed (configurable: fail-fast vs. continue).

## 2. REST API

- **Endpoint:** `POST /V1/readydata/products` — bulk create/update.
  Later (placeholders): `POST /V1/readydata/products/delete`, `GET /V1/readydata/import/:id/status`.
- **Auth:** standard Magento integration tokens (OAuth/bearer), ACL resource
  `ReadyData_Import::import`.
- **Payload:** array of product objects. Service contract uses data interfaces
  (`Api/Data/ProductInterface`, `StockInterface`, `ImportResultInterface`, ...) so the
  schema is discoverable via `/rest/schema`. Custom attributes ride in a
  `custom_attributes` key-value array to stay flexible.
- **Response:** summary (received/created/updated/failed counts, elapsed ms) + per-SKU
  results. Errors are per-product, not all-or-nothing.

Example request body:

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
      "stock": {"qty": 100, "is_in_stock": true, "source_code": "default"},
      "url_key": "example-product",
      "custom_attributes": [{"attribute_code": "color", "value": "Red"}]
    }
  ],
  "settings": {"store_view_code": "default", "continue_on_error": true}
}
```

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

| Path | Default | Purpose |
|---|---|---|
| `readydata_import/general/enabled` | 1 | kill switch |
| `readydata_import/general/batch_size` | 500 | products per internal batch/transaction |
| `readydata_import/general/continue_on_error` | 1 | per-batch fail-fast vs. continue |
| `readydata_import/behavior/create_missing_options` | 1 | auto-create select options |
| `readydata_import/behavior/url_rewrite_conflict` | append | error/append/skip |
| `readydata_import/media/enabled` | 1 | media gallery step (downloads included) |
| `readydata_import/media/download_timeout` | 15 | seconds per image |
| `readydata_import/media/download_concurrency` | 4 | images fetched at once (1 = sequential, max 32) |
| `readydata_import/media/max_file_size_kb` | 10240 | largest accepted image |
| `readydata_import/media/allowed_extensions` | jpg,jpeg,png,gif,webp | downloads and pre-uploaded paths |
| `readydata_import/media/allowed_hosts` | *(empty)* | download host allow-list; empty = any host |
| `readydata_import/media/redownload_existing` | 0 | re-fetch a URL whose target file exists |
| `readydata_import/media/auto_assign_roles` | 1 | base roles → first enabled entry |
| `readydata_import/indexing/mode` | partial | none/invalidate/partial |
| `readydata_import/logging/enabled` | 1 | dedicated log file |

## 5. File tree (initial skeleton; `[P]` = placeholder stub for expansion)

```
app/code/ReadyData/Import/
├── registration.php
├── composer.json
├── README.md
├── Api/
│   ├── ProductImportInterface.php
│   └── Data/
│       ├── ProductInterface.php
│       ├── StockDataInterface.php
│       ├── ImportSettingsInterface.php
│       ├── ImportResultInterface.php
│       └── ImportResponseInterface.php
├── Model/
│   ├── ProductImport.php                  # Web API entry, thin
│   ├── ImportService.php                  # batching, transactions, orchestration
│   ├── BatchContext.php                   # shared per-batch state
│   ├── Config.php                         # typed accessor for system config
│   ├── Data/                              # DTO implementations of Api/Data
│   ├── Processor/
│   │   ├── ProcessorInterface.php
│   │   ├── AttributeProcessor.php
│   │   ├── EntityProcessor.php
│   │   ├── EavValueProcessor.php
│   │   ├── WebsiteProcessor.php
│   │   ├── StockProcessor.php
│   │   ├── UrlRewriteProcessor.php
│   │   ├── PreparableInterface.php        # opt-in pre-transaction phase
│   │   ├── CategoryLinkProcessor.php      [P]
│   │   ├── MediaProcessor.php
│   │   ├── LinkProcessor.php              [P]
│   │   ├── ConfigurableProcessor.php      [P]
│   │   └── TierPriceProcessor.php
│   ├── ResourceModel/
│   │   ├── ProductEntity.php              # entity upserts + SKU→ID resolution
│   │   ├── EavValue.php                   # per-backend-type value upserts
│   │   ├── AttributeOption.php            # option lookup/bulk create
│   │   ├── Stock.php                      # stock_item + MSI source items
│   │   ├── ProductMediaGallery.php        # the four media gallery tables
│   │   ├── TierPrice.php                  # tier price diff/upsert + decimal scaling
│   │   ├── UrlRewrite.php
│   │   └── Website.php
│   ├── Media/
│   │   ├── FileResolver.php               # validates payload file references, writes them
│   │   ├── DownloaderInterface.php
│   │   └── PooledDownloader.php           # bounded concurrent fetch (Guzzle Pool)
│   ├── Cache/
│   │   ├── AttributeMetadataCache.php
│   │   ├── CustomerGroupMap.php           # group code/ID resolution
│   │   └── StoreWebsiteMap.php
│   └── Indexer/
│       └── InvalidationHandler.php
├── Logger/                                # Handler + Logger (var/log/readydata_import.log)
├── etc/
│   ├── module.xml
│   ├── di.xml                             # processor pool, preferences
│   ├── acl.xml
│   ├── webapi.xml
│   ├── config.xml
│   └── adminhtml/system.xml
└── Test/
    ├── Unit/                              # url_key generation, batching, DTO mapping
    └── Integration/                       [P] full-import round-trip against test DB
```

Placeholders are real classes implementing `ProcessorInterface` with a guarded
"not implemented" no-op (log + skip), already registered in the `di.xml` pool but
disabled via a `enabled` constructor flag — so enabling a feature later is: implement
the body, flip the flag.

## 6. Implementation order

1. Skeleton: registration, module.xml, composer.json, ACL, webapi.xml, config, DTOs.
2. `ImportService` + `BatchContext` + `EntityProcessor` + `EavValueProcessor`
   (a product with core attributes imports end-to-end).
3. `AttributeProcessor` (option auto-create), `WebsiteProcessor`.
4. `StockProcessor` (legacy + MSI).
5. `UrlRewriteProcessor`.
6. `InvalidationHandler` + cache cleaning.
7. Logging, per-SKU error reporting polish, README, unit tests.
8. Placeholder processors stubbed throughout.

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
  optionally `catalog_product_save_after` (default off) per product, plus four batch-level
  `readydata_import_*` events. Two decisions that come with it are worth revisiting:
  - the dispatched product is a **notification carrier** — no `origData`, no attribute
    set, so `dataHasChangedFor()` reports everything changed and saving it corrupts.
    Read-only by contract, enforced by nothing;
  - `dispatch_save_after` runs **inside** the batch transaction, which hands a third
    party the ability to roll back an import. Off by default for that reason.
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

> **Section drift:** the `[P]` markers in §1, §5 and §6 are stale. Every pipeline step is
> implemented — `CategoryLinkProcessor`, `LinkProcessor` and `ConfigurableProcessor`
> included — and the module has grown two endpoints the plan never described (attribute
> definitions, category sync) plus an Amasty writer, an event layer and two suppression
> plugins. `AbstractPlaceholderProcessor` survives as a base class with no subclasses.
