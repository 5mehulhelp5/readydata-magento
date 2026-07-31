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
        └─ TierPriceProcessor        catalog_product_entity_tier_price   [placeholder]
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
│   │   └── TierPriceProcessor.php         [P]
│   ├── ResourceModel/
│   │   ├── ProductEntity.php              # entity upserts + SKU→ID resolution
│   │   ├── EavValue.php                   # per-backend-type value upserts
│   │   ├── AttributeOption.php            # option lookup/bulk create
│   │   ├── Stock.php                      # stock_item + MSI source items
│   │   ├── ProductMediaGallery.php        # the four media gallery tables
│   │   ├── UrlRewrite.php
│   │   └── Website.php
│   ├── Media/
│   │   └── FileResolver.php               # downloads/validates payload file references
│   ├── Cache/
│   │   ├── AttributeMetadataCache.php
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
  field; staging-aware writes are out of scope initially (documented limitation).
- **Direct DB writes skip plugins/observers** other modules attach to product save —
  by design, but must be a documented, loud caveat in README.
- **Concurrency:** two simultaneous imports of the same SKUs can deadlock; v1 uses a
  lock (`readydata_import` mutex via `Magento\Framework\Lock\LockManagerInterface`),
  async queue-based imports are the future path.
- **Async mode:** for very large feeds, accept-and-queue (bulk API pattern with
  `operation` status endpoint) is the planned expansion — hence the status endpoint
  placeholder.
- **Media `value_id` read-back:** a gallery row has no natural key, so the watermark
  re-select is not provably unambiguous — a concurrent admin product save can interleave
  its own rows. Guarded by three stacked predicates plus a positional `value` comparison
  that aborts to "no inserts" rather than guessing. If it ever fires in the field, the
  escalation is per-row `insert()` + `lastInsertId()` for new files only.
- **Orphan media files:** a rolled-back batch leaves whatever `prepare()` downloaded in
  `pub/media`. Deterministic target paths make retries converge on the same file instead
  of accumulating copies, but nothing garbage-collects the unreferenced ones.
- **Media downloads are serial and synchronous**, bounded per batch. The async path above
  is also the answer for large first-time image imports.
