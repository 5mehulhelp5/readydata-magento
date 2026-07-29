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
      "links": {"related": ["DEF-456"], "cross_sell": ["GHI-789"]},
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

### Attribute value scoping

Values are written in the scope each attribute is configured with, keyed off
the request's `store_view_code` (absent/`admin` = default scope):

- **Global** (`is_global = 1`): always written at store 0, whatever the
  request scope.
- **Website** (`is_global = 2`): written to **every store view of the
  website** containing the request's store view (including inactive views),
  mirroring core Magento's website-scope emulation. At the default scope,
  only the store-0 row is written.
- **Store view** (`is_global = 0`): written at the request's store view only.

New products additionally get a store-0 fallback row for non-global values.

### Clearing attribute values

A `null` (or absent) value in `custom_attributes` means **leave unchanged** —
safe for sparse feeds. To actually remove a stored value, list the attribute
code in `clear_attributes`. A clear DELETEs the EAV value rows in the same
scope a write would target (see "Attribute value scoping"): global attributes
at the default scope, website-scoped attributes across all store views of the
request store's website, store-scoped attributes at the request's
`store_view_code` (a cleared store row falls back to the default value, like
"Use Default" in the admin).

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

### Related, up-sell & cross-sell links

A `links` block declares the product's merchandising links, each type an
ordered list of target SKUs:

```json
{
  "sku": "SHIRT-01",
  "links": {
    "related":    ["BELT-01", "SOCKS-02"],
    "up_sell":    ["SHIRT-01-PREMIUM"],
    "cross_sell": []
  }
}
```

- Semantics are **replace**, per sub-field: a present `related`, `up_sell` or
  `cross_sell` array (including `[]`) makes that link type become exactly the
  resolved set, while an omitted sub-field leaves that link type untouched. So
  `{"related": [...]}` rewrites only the related products. `null`/omitted
  `links` leaves all links untouched.
- **Position follows the array order** (0-based) and is written to
  `catalog_product_link_attribute_int`. Because the payload owns the whole set
  for a link type, it also owns the order: admin-set positions on the link types
  the feed sends are overwritten, while omitted link types keep theirs.
- Targets must **already exist**; any product type may be a target. Unknown SKUs
  are skipped with a per-product warning. Targets are resolved against the
  database, so **send targets before (or in the same batch as) the linking
  product** — a target scheduled in a later batch will not resolve.
- **Safety valve** (as with categories), scoped **per link type**: if a target in
  `related` fails to resolve, only the related removals are withheld — new links
  are still added and a clean `cross_sell` set still applies in full (a warning
  explains this).
- A product **linking to itself** is skipped with a warning and does *not* trip
  the safety valve, so a feed that echoes the linking SKU into its own related
  list still gets its obsolete links removed. Duplicate SKUs within one array are
  deduplicated, first occurrence wins.
- Links are **global** (no store dimension) — send them on one store pass only.
- Grouped-product children are a different link type and are not touched.

### Configurable products

A `configurable` parent declares its variation axes and children with a
`configurable` block; the children are ordinary simple/virtual product
payloads that carry their own option values in `custom_attributes`:

```json
{
  "sku": "SHIRT-01",
  "type_id": "configurable",
  "attribute_set": "Default",
  "name": "Example Shirt",
  "configurable": {
    "super_attributes": ["color", "size"],
    "children": ["SHIRT-01-RED-S", "SHIRT-01-RED-M"]
  }
}
```

- `super_attributes` are attribute codes the product varies on; each must be
  a **global-scope select** attribute that **already exists** (the importer
  never creates attributes — see "Attributes must already exist"). Non-existent
  or non-conforming codes are skipped with a per-product warning. Beware: if a
  code is dropped this way, the parent varies on fewer axes than intended, and
  children that were distinguished only by the dropped axis collapse onto a
  shared value combination — the DB links them all, but only one shows as an
  assigned variation. If a configurable ends up with only some of its children
  visible, check `results[].messages` for a skipped super attribute first.
- `children` are the SKUs of the variation products. A child must already
  exist and be a **simple or virtual** product; unknown or wrong-typed SKUs
  are skipped with a warning. Children are resolved against the database, so
  **send children before (or in the same batch as) the parent** — a child
  scheduled in a later batch is not yet created and will not resolve.
- Semantics are **replace**, per sub-field: a present `super_attributes` or
  `children` array (including `[]`) makes that dimension become exactly the
  resolved set, while an omitted sub-field leaves that dimension untouched.
  So `{"children": [...]}` updates only the child links and preserves the
  existing super attributes. `null`/omitted `configurable` leaves the whole
  structure untouched.
- **Safety valve** (as with categories): if any super attribute or child
  fails to resolve, that parent is applied additively — new attributes and
  links are added, but nothing existing is removed (a warning explains this).
- The parent's price index is refreshed after linking so it reflects the
  children's prices.

Response: summary counters (`received`, `created`, `updated`, `failed`,
`elapsedMs`) plus a per-SKU `results` array with `status` and `messages`.
Errors are per-product; a failing product does not abort the request.

## Attribute definitions

A separate endpoint provisions the product attribute *definitions* a feed
references. It is **standalone** — no product import is required before or
after — and is normally called as a pre-flight step so the attributes exist
before their values are imported. Off by default (see Configuration).

```
POST /rest/all/V1/readydata/attributes
Authorization: Bearer <integration token>   (ACL: ReadyData_Import::attributes)
```

```json
{
  "attributes": [
    {
      "attribute_code": "material",
      "frontend_input": "select",
      "frontend_label": "Material",
      "scope": "global",
      "is_required": 0,
      "is_filterable": 1,
      "used_in_product_listing": 1,
      "options": ["Cotton", "Wool"],
      "placements": [{"set": "Default", "group": "General"}]
    }
  ]
}
```

The **caller is the system of record** for what each attribute should be: it
sends an already-Magento-shaped definition (`frontend_input`, and optionally
`backend_type`, models, `scope`, flags, label, `options`, `placements`, and an
`amasty` block for layered-navigation data — see "Amasty layered navigation").
`attribute_code` is always required. `frontend_input` is required to **create**
an attribute but optional when **updating** an existing one — an omitted input
leaves the stored shape untouched. Every other omitted property falls back to
Magento's own column defaults. Definitions are persisted through
Magento's `EavSetup`, so the EAV cache, attribute-set/group membership, option
values and (where enabled) flat-catalog columns are all maintained correctly.

### Reconciliation — create or update to match the source

There is no create-only/update toggle. Per attribute:

- **missing** → **created** to match the payload;
- **exists, identical** → `unchanged`;
- **exists, safe columns differ** (label, `is_required`, `default_value`,
  searchable/filterable/listing/grid flags) → **updated** to match the payload;
- **exists, a _structural_ column differs** (`backend_type`, `frontend_input`,
  `is_global`) → **`skipped`** with reason `structural_change_required`.

`scope` maps to `is_global`: `global`/`website`/`store`. Placement is
**additive** — an attribute already in a set is left where it is (never moved
between groups); a named group is created if missing; an omitted group uses the
set's default group; an omitted set uses the entity's default set.

Response: summary counters (`received`, `created`, `updated`, `unchanged`,
`skipped`, `failed`, `elapsedMs`) plus a per-attribute `results` array with
`status`, a machine-readable `reason`, and `messages`.

### Amasty layered navigation

An attribute definition may carry an optional `amasty` block that drives
[Amasty Improved Layered Navigation](https://amasty.com/) and Shop by Brand data
alongside the base attribute sync. It is **optional and soft-dependent**: omit
it and nothing Amasty-related is touched; send it on a store without the matching
Amasty module and the unsupported parts are simply skipped (see below).

```json
{
  "attribute_code": "brand",
  "frontend_input": "select",
  "scope": "global",
  "options": ["Nike", "Adidas"],
  "amasty": {
    "display_mode": 4,
    "is_multiselect": 1,
    "url_alias": "brand",
    "is_expanded": 0,
    "tooltip": "Pick a brand",
    "slider_step": 1,
    "is_brand": 1,
    "filter_extra": {"block_position": 2},
    "option_settings": [
      {"option": "Nike", "title": "Nike", "image": "brands/nike.png",
       "url": "nike", "description": "Just do it."}
    ]
  }
}
```

The block groups three independent concerns, each guarded and applied on its own:

- **Filter settings** → the ILN per-attribute row (`amasty_amshopby_filter_setting`,
  keyed by `attribute_code`). `display_mode` is Amasty's numeric enum — `0` Labels,
  `1` Dropdown, `2` Slider, `3` From-To only, `4` Images, `5` Images+Labels,
  `6` Text swatch. `url_alias` is written to the `attribute_url_alias` column;
  `is_multiselect`, `is_expanded`, `tooltip` and `slider_step` map to their
  like-named columns.
- **Brand designation** (`is_brand: 1`) → points Amasty Shop by Brand at this
  attribute (sets `amshopby_brand/general/attribute_code`). Any other value
  leaves the brand config untouched.
- **Per-option brand/landing data** (`option_settings[]`) → one row per option in
  the option-setting table. Each entry is keyed by `option`, the option's
  **admin-scope label** (resolved to its option ID), plus optional `store_id`
  (default `0` = admin/all-store), `title`, `image`, `url` (written to the
  `url_alias` column), `description`, `meta_title`, `meta_description`.

`filter_extra` and each option's `extra` are verbatim passthrough maps for
version-specific columns: keys must be **real Amasty column names**, merged over
the friendly fields and intersected with the live table.

**Soft dependency, never fatal.** The module has no hard dependency on Amasty.
Table names vary across Amasty releases (the first existing candidate is used),
and every column is checked against the live table. A missing module, table,
column, or an unresolved option label is collected as a per-attribute entry in
the response `messages` — the base attribute sync always succeeds regardless.
Amasty properties are applied **last**, after options exist, so per-option data
can resolve option labels to IDs.

### Structural changes require a deliberate migration

`backend_type`, `frontend_input` and `is_global` are **create-only**: once an
attribute exists, the sync never rewrites them, because each needs a real
value-data migration that has no safe automatic answer —

- **`backend_type`**: values live in `catalog_product_entity_<oldType>`; the new
  type reads a different table, so values must be moved + coerced or dropped;
- **`frontend_input` text→select/multiselect**: free-text values must become
  options (create options from distinct values, remap to option IDs);
- **`is_global`/scope**: value rows must be re-partitioned across stores.

(Core Magento likewise blocks `backend_type` edits in the admin once an
attribute has data.) When a definition disagrees with the stored attribute on
one of these, the sync reports `skipped` / `structural_change_required` with the
`have` vs `requested` values in `messages`, applies nothing, and leaves the fix
to a deliberate, out-of-band migration (a data patch or attribute recreate, with
backups). Re-sync afterwards reconciles the safe columns.

### Concurrency & indexing

Concurrent syncs serialize on a `readydata_attribute_sync` lock (with a short
wait); the `eav_attribute` unique key is the ultimate backstop, so a create that
loses a race is re-read and treated as an update rather than failing. Each
attribute is processed independently (no wrapping transaction) and reported per
code. After any change the sync cleans the `eav`/`config`/`full_page`/`block_html`
cache types and **invalidates** (not partial-reindex — there are no product IDs)
the `catalog_product_attribute`, `catalogsearch_fulltext` and (if enabled)
`catalog_product_flat` indexers. **With Flat catalog enabled, a new listing/sort
attribute is invisible until the flat indexer rebuilds** — run a flat reindex
after a sync.

### Limits (v1)

Per-store labels (`store_labels`), swatch/media/weee input types, attribute
deletion, and moving an existing attribute between groups are out of scope.
Unsupported input types and definitions that would break a module invariant
(e.g. a `backend_type` outside `varchar/int/decimal/text/datetime`, a
`multiselect` on `varchar`, or a missing backend/source model class) are
`skipped` with a clear per-attribute reason.

## What it does today

- Creates/updates `catalog_product_entity` + all scalar EAV values with
  multi-row `INSERT ... ON DUPLICATE KEY UPDATE` (one statement per value
  table per batch, chunked at 1000 rows).
- Resolves select/multiselect option labels to IDs; auto-creates missing
  options (configurable).
- Website assignment (additive; new products default to the default website).
- Category assignments (replace semantics, paths or IDs, auto-creation of
  missing subtrees — see "Category assignments" above).
- Configurable products: super attributes + child links (replace semantics,
  additive safety valve — see "Configurable products" above).
- Related / up-sell / cross-sell links: replace semantics per link type with a
  per-type additive safety valve and payload-order positions (see "Related,
  up-sell & cross-sell links" above).
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
reindex mode, cache cleaning, event dispatch, logging.

The **Attribute Definitions** group has a single switch, **Enable Attribute
Definition Sync** (`readydata_import/attributes/auto_create`, default **off**),
the kill switch for the `POST /V1/readydata/attributes` endpoint. When off, the
endpoint is a no-op that reports every attribute as `skipped`/`disabled`. There
is intentionally no attribute-shape config here — scope, flags and placement are
supplied per attribute by the caller (the system of record).

### Events

Because the importer writes directly to the database, none of the usual
product-save events fire on their own. The **Events** group re-emits them so
third-party observers still react to imports:

- **Dispatch Product Save Events** (`dispatch_product_events`, default on) —
  after each committed batch, re-emit `catalog_product_save_commit_after`
  (and the custom `readydata_import_*` events) per product. These run
  **after** the batch transaction commits, so a throwing observer is logged
  and swallowed rather than rolling the import back.
- **Also Dispatch catalog_product_save_after** (`dispatch_save_after`,
  default off) — additionally fire `catalog_product_save_after` per product
  **inside** the batch transaction, mirroring core's save timing. This is
  heavier than the commit-after events and, because it runs pre-commit, a
  **throwing observer rolls the whole batch back**. Only enable it when a
  specific third-party observer must run on this in-transaction event.
  Depends on "Dispatch Product Save Events" being on.

Whenever product-save events are dispatched, the module suppresses Magento's
own URL-rewrite and inventory save observers for the duration of the import
(they are re-implemented in bulk by the pipeline), so they never double-write.

## Placeholders (registered, disabled)

Media gallery and tier prices — see
`Model/Processor/*Processor.php` docblocks for the planned scope of each.
Implement `execute()` and flip `isEnabled()` to activate. Third-party
steps: implement `ProcessorInterface`, register in `etc/di.xml`
(`ImportService`, argument `processors`).

## Important caveats

- **The product import never creates attribute _definitions_ inline**: it
  resolves attribute codes but does not create the definition during a product
  import. An unknown code — whether a `custom_attributes` entry or a
  configurable `super_attributes` code — is skipped with a per-product warning
  and its value/axis is simply not written. The only thing the *product* import
  auto-creates is missing **option values** on existing select/multiselect
  attributes (config `create_missing_options`, default on). This separation is
  deliberate: an attribute definition carries decisions a product payload cannot
  (frontend input, backend type, scope, flags, attribute-set placement), some
  effectively immutable once data exists. Provision definitions **beforehand**
  via the standalone **`POST /V1/readydata/attributes`** endpoint (see "Attribute
  definitions"), a data patch, or the admin (for super attributes: a
  **global-scope select** added to the product's attribute set).
- **Bypasses the product model**: plugins/observers on product save do NOT
  run. That is the point, but audit your customizations before adopting.
  Exception: **auto-created categories** are saved through the category
  model/repository (path/level maintenance, url_key, URL rewrites), so
  category-save plugins and observers DO run for them.
- Duplicate sibling category names are ambiguous; path resolution picks the
  lowest entity_id, deterministically.
- **Referenced SKUs are matched case-sensitively** — configurable `children` and
  the SKUs in a `links` block are looked up by their **stored** spelling, so
  `"belt-01"` against a stored `"BELT-01"` is reported as not found (and trips
  that dimension's safety valve). Two products differing only by case are
  physically possible, so the importer does not fold case; send SKUs exactly as
  stored.
- Product links (related/up-sell/cross-sell) feed no catalog indexer, and links
  are directional — only the linking product's FPC tags are cleaned, which the
  normal post-import invalidation already covers. Link targets are not
  invalidated.
- **Value coercion**: datetime attribute values are normalized to UTC
  `Y-m-d H:i:s` (offset-less input is taken as already-UTC); unparseable
  datetime and non-numeric decimal values are skipped with a per-SKU
  message, never written. No cross-field checks (e.g. `special_from_date`
  vs `special_to_date`) — validate windows at the source.
- Website-scoped attributes (e.g. prices under "Catalog Price Scope:
  Website") are fanned out to all store views of the request store's
  website — one value row per view, like core. Sending them on one store
  view per website is enough; other websites keep their own values.
- **Adobe Commerce (EE) staging**: updates work; creating new products on a
  staged catalog is not yet supported (clear per-product error is returned).
- Run indexers in "Update by Schedule" mode for best throughput.

## Installation

```
composer require readydata/module-import
bin/magento module:enable ReadyData_Import
bin/magento setup:upgrade
```
