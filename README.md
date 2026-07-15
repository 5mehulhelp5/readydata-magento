# ReadyData_Import

Bulk product import for Magento 2 via a REST endpoint, writing **directly to the
database** for performance. Products are processed in configurable batches
(default **500** per batch, one DB transaction each) through a pluggable
processor pipeline.

See [PLAN.md](PLAN.md) for the full architecture and roadmap.

## Endpoint

```
POST /rest/all/V1/readydata/products
Authorization: Bearer <integration token>   (ACL: ReadyData_Import::import)
```

```json
{
  "products": [
    {
      "sku": "ABC-123",
      "type_id": "simple",
      "attribute_set": "Default",
      "name": "Example Product",
      "price": 19.99,
      "status": 1,
      "visibility": 4,
      "websites": ["base"],
      "stock": {"qty": 100, "is_in_stock": true},
      "url_key": "example-product",
      "custom_attributes": [
        {"attribute_code": "color", "value": "Red"},
        {"attribute_code": "description", "value": "<p>Long text</p>"}
      ],
      "clear_attributes": ["special_label"]
    }
  ],
  "settings": {"store_view_code": "default", "continue_on_error": true}
}
```

### Clearing attribute values

A `null` (or absent) value in `custom_attributes` means **leave unchanged** —
safe for sparse feeds. To actually remove a stored value, list the attribute
code in `clear_attributes`. A clear DELETEs the EAV value row in the same
scope a write would target: global attributes at the default scope,
store-scoped attributes at the request's `store_view_code` (a cleared store
row falls back to the default value, like "Use Default" in the admin).

Guards (each a per-product warning in `results[].messages`, never fatal):
unknown and static attributes are skipped; required attributes cannot be
cleared at the default scope; when the same attribute is both written and
cleared, the write wins. Clearing `url_key` does not remove existing URL
rewrites.

Response: summary counters (`received`, `created`, `updated`, `failed`,
`elapsedMs`) plus a per-SKU `results` array with `status` and `messages`.
Errors are per-product; a failing product does not abort the request.

## What it does today

- Creates/updates `catalog_product_entity` + all scalar EAV values with
  multi-row `INSERT ... ON DUPLICATE KEY UPDATE` (one statement per value
  table per batch, chunked at 1000 rows).
- Resolves select/multiselect option labels to IDs; auto-creates missing
  options (configurable).
- Website assignment (additive; new products default to the default website).
- Stock: legacy `cataloginventory_stock_item` + MSI `inventory_source_item`
  when MSI is installed.
- URL rewrites: generates `url_key` from the name when absent, regenerates
  direct product rewrites per store, with a configurable conflict strategy
  (append suffix / skip / error).
- Indexing: partial reindex of affected IDs (default), invalidate, or none.
  Indexers in "Update by Schedule" mode are left to mview (DB triggers pick
  up direct writes). FPC tags of touched products are cleaned.
- Concurrency guard: a named lock rejects overlapping imports.
- Logging to `var/log/readydata_import.log`.

## Configuration

Stores → Configuration → ReadyData → Product Import: enable/disable, batch
size, continue-on-error, option auto-creation, URL conflict strategy,
reindex mode, cache cleaning, logging.

## Placeholders (registered, disabled)

Category links, media gallery, related/up-sell/cross-sell links,
configurable structure, tier prices — see
`Model/Processor/*Processor.php` docblocks for the planned scope of each.
Implement `execute()` and flip `isEnabled()` to activate. Third-party
steps: implement `ProcessorInterface`, register in `etc/di.xml`
(`ImportService`, argument `processors`).

## Important caveats

- **Bypasses the product model**: plugins/observers on product save do NOT
  run. That is the point, but audit your customizations before adopting.
- **Adobe Commerce (EE) staging**: updates work; creating new products on a
  staged catalog is not yet supported (clear per-product error is returned).
- Run indexers in "Update by Schedule" mode for best throughput.

## Installation

```
composer require readydata/module-import
bin/magento module:enable ReadyData_Import
bin/magento setup:upgrade
```
