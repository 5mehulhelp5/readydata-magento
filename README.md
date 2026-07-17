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
      "categories": ["Default Category/Men/Shirts", "42"],
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

### Category assignments

Each `categories` entry is either a **full category path** from the root
category name (`"Default Category/Men/Shirts"`, separator `/`) or a
**numeric category ID** (`"42"`). Semantics are **replace**: when the field
is present, the product's assignments become exactly the resolved set —
links not in the payload are removed. `null`/omitted leaves assignments
untouched; `[]` removes them all.

- Missing path segments **below an existing root** are auto-created (active,
  in menu, auto-generated `url_key`, name at the default scope). Root
  categories are never auto-created: an unmatched first segment is a
  per-product warning, so a typo cannot spawn a new tree. Path segments are
  matched against admin (store-0) names, trimmed, case-sensitively.
  Required custom int/select category attributes without a default value are
  filled with `0` ("No") so validation cannot block creation; required
  attributes of other types may still block it (per-product warning).
- Unknown numeric IDs and root-category IDs are skipped with a warning.
- **Safety valve**: if any of a product's entries fails to resolve, that
  product is applied additively for the request — new links are inserted,
  but no existing links are removed (a warning explains this).
- Only the path leaf is linked; enable `is_anchor` on ancestors for rollup.
- Position is not settable: new links get position 0, existing links keep
  their admin-set positions.
- Assignments are **global** (no store dimension) — send `categories` on one
  store pass only.
- `\` escapes the next character: `\/` is a literal slash inside a name
  (`"Default Category/Wo\/Men"` names the category `Wo/Men`), `\\` a literal
  backslash (names containing `\` MUST escape it), and a trailing lone `\`
  is a literal backslash. A digits-only *name* is referenceable as an
  escaped segment (`"Default Category/\42"`), while a bare `"42"` entry
  stays a numeric ID.

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
- Category assignments (replace semantics, paths or IDs, auto-creation of
  missing subtrees — see "Category assignments" above).
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

Media gallery, related/up-sell/cross-sell links,
configurable structure, tier prices — see
`Model/Processor/*Processor.php` docblocks for the planned scope of each.
Implement `execute()` and flip `isEnabled()` to activate. Third-party
steps: implement `ProcessorInterface`, register in `etc/di.xml`
(`ImportService`, argument `processors`).

## Important caveats

- **Bypasses the product model**: plugins/observers on product save do NOT
  run. That is the point, but audit your customizations before adopting.
  Exception: **auto-created categories** are saved through the category
  model/repository (path/level maintenance, url_key, URL rewrites), so
  category-save plugins and observers DO run for them.
- Duplicate sibling category names are ambiguous; path resolution picks the
  lowest entity_id, deterministically.
- **Value coercion**: datetime attribute values are normalized to UTC
  `Y-m-d H:i:s` (offset-less input is taken as already-UTC); unparseable
  datetime and non-numeric decimal values are skipped with a per-SKU
  message, never written. No cross-field checks (e.g. `special_from_date`
  vs `special_to_date`) — validate windows at the source.
- Attribute scope handling only distinguishes global (`is_global = 1`,
  written at store 0) from store-view scope. Website-scoped attributes
  (e.g. prices under "Catalog Price Scope: Website") are written at the
  request's store-view scope, not fanned out per website.
- **Adobe Commerce (EE) staging**: updates work; creating new products on a
  staged catalog is not yet supported (clear per-product error is returned).
- Run indexers in "Update by Schedule" mode for best throughput.

## Installation

```
composer require readydata/module-import
bin/magento module:enable ReadyData_Import
bin/magento setup:upgrade
```
