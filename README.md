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
      "tier_prices": [
        {"customer_group": "all groups", "qty": 1, "price": 17.99},
        {"customer_group": "Wholesale", "qty": 10, "percentage_discount": 15}
      ],
      "media": [
        {"file": "https://cdn.example.com/img/shirt-front.jpg", "label": "Front",
         "roles": ["image", "small_image", "thumbnail"]},
        {"file": "/s/h/shirt-back.jpg", "label": "Back"},
        {"file": "/s/h/shirt-video-preview.jpg",
         "video_url": "https://www.youtube.com/watch?v=abc123", "video_title": "How it fits"}
      ],
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

### Tier prices

A `tier_prices` array declares the product's tier (group) prices — one entry per
(customer group, quantity, website) triple:

```json
{
  "sku": "SHIRT-01",
  "price": 100,
  "tier_prices": [
    {"customer_group": "all groups", "qty": 1,  "price": 90},
    {"customer_group": "NOT LOGGED IN", "qty": 1, "price": 95},
    {"customer_group": "Wholesale",  "qty": 10, "percentage_discount": 20},
    {"customer_group": "3",          "qty": 25, "percentage_discount": 25, "website": "base"}
  ]
}
```

- Semantics are **replace**: a present array (including `[]`) makes the product's
  tier prices become exactly this set. `null`/omitted leaves them untouched.
  Existing rows are matched on their triple — the same unique key Magento uses —
  so a re-import keeps each row's `value_id` and, when nothing changed, issues
  **no SQL at all**.
- **`customer_group`** is a group code (matched case-insensitively and trimmed,
  as Magento's own tier-price API does), a numeric group ID, or the sentinel
  `"all groups"` (`"all"` is accepted as shorthand) for every group. Note that
  `NOT LOGGED IN` is a real group (ID 0) and is *not* the same as "all groups" —
  both are storable side by side, and a group-specific price wins over the
  all-groups one at the same quantity. When two groups share a code the lowest ID
  wins, deterministically; a group whose code is digits-only can only be
  referenced by its ID.
- **`price` XOR `percentage_discount`** — exactly one. `price` is an absolute
  amount in the base currency; `percentage_discount` is a percentage taken **off
  the product's price** (`20` on a product at 100 means 80). Sending both, or
  neither, skips the entry with a warning rather than guessing.
- **`website`** is a website code, or omitted / `"all websites"` / `"all"` for
  every website. Legal only when **Catalog Price Scope** is *Website*; under the
  default *Global* scope an entry naming a website is skipped with a warning. It
  is deliberately not widened to All Websites — quietly applying one website's
  price everywhere is a pricing error, not a normalisation — and not stored
  as-is either, since such a row is invisible in the admin and the next admin
  save would delete it.
- **Safety valve** (as with categories and media), scoped **per product**: if any
  entry fails to resolve or validate, that product is applied additively — new
  and changed prices are written, but no existing tier price is removed (a
  warning explains this). Unlike `links` the valve is not per sub-dimension: tier
  prices are one set whose dimensions come from the data, with no named
  sub-field to isolate.
- Each guard is a per-product warning in `results[].messages`, never fatal:
  unknown or empty customer group, unknown website code, a website under global
  price scope, `qty` not greater than zero, a negative `price`,
  `percentage_discount` outside 0–100, both or neither amount, and an absolute
  price on a **bundle** (bundles accept `percentage_discount` only, as in core).
  A **duplicate triple** within one product keeps the first entry and warns, but
  deliberately does *not* trip the valve — one of the two rows is written, so the
  set is still complete.
- Values are stored at the column scales `qty` 4, `price` 6 and
  `percentage_discount` 2 decimals, and both sides of the diff are compared at
  those scales — which is what makes a re-import a no-op instead of a
  delete-and-reinsert churn.
- Tier prices are **global** (no store dimension) — `store_view_code` does not
  affect them, the website dimension lives in the entry — so send them on one
  store pass only.
- Skipped entirely for product types outside the `tier_price` attribute's
  `apply_to` (**configurable** and **grouped** on a stock install): a warning is
  reported and existing rows are left alone, never removed.

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

### Product media gallery

A `media` array owns the product's gallery. Each entry names a `file` that is
either an **http(s) URL** the module downloads into `pub/media/catalog/product`
with Magento's standard dispersion (`hero.jpg` → `/h/e/hero.jpg`) or a **path
relative to `pub/media/catalog/product`** for a file pushed out of band
(`/s/h/shirt-back.jpg`; a leading `catalog/product/` is accepted and stripped).
The two forms are told apart by the scheme.

```json
"media": [
  {"file": "https://cdn.example.com/img/front.jpg", "label": "Front",
   "roles": ["image", "small_image", "thumbnail"]},
  {"file": "/s/h/back.jpg", "label": "Back", "position": 5, "disabled": true},
  {"file": "/s/h/preview.jpg", "video_url": "https://youtu.be/abc123",
   "video_title": "How it fits", "video_description": "Fit guide"}
]
```

- **Downloads run before the batch transaction opens**, so no database locks are
  ever held across network I/O, and they run **concurrently** up to *Download
  Concurrency* (default 4; set it to 1 for fully sequential).
- A downloaded file is stored under its sanitised name plus a short digest of its
  URL — `https://cdn.example.com/img/hero.jpg` becomes `/h/e/hero_1a2b3c4d.jpg`.
  The digest makes the path a pure function of the URL, so two suppliers whose
  images are both called `hero.jpg` can never collide on one file.
- Semantics are **replace**: a present `media` array makes the gallery exactly
  that ordered set, `[]` removes every entry, `null`/omitted leaves the gallery
  untouched. Entries are matched against the **stored file path**, so a
  re-import of an unchanged gallery performs **no writes at all** and existing
  entries keep their rows — and with them their video records and any per-store
  data the admin added.
- **Position follows the array order** (0-based, gap-free over the entries that
  resolved) unless an entry sets `position` explicitly. Duplicate files within
  one product are skipped, first occurrence wins.
- `label` is the alt text; `disabled` hides the entry from the storefront
  without deleting the file.
- `roles` may contain `image`, `small_image`, `thumbnail` and `swatch_image`. A
  role claimed by more than one entry keeps its first claim (with a warning). A
  role whose file this import removes is cleared in **every** scope, so no store
  view is left asking for a deleted file. With **Auto-Assign Media Roles** on
  (default), a product whose `media` block declares no roles at all and has no
  image role yet gets `image`/`small_image`/`thumbnail` pointed at its first
  enabled entry; `swatch_image` is never auto-assigned, and a role a merchant
  already chose is never overwritten. Roles sent through `custom_attributes` are
  overwritten for products carrying a `media` block — **send roles inside
  `media`**.
- **External video**: an entry with `video_url` becomes `media_type =
  "external-video"`; `file` is still required and is its preview image.
  `video_provider` is derived from the URL host (`youtube`, `vimeo`) when
  omitted, and an unrecognised provider skips the entry with a warning.
  `video_title`, `video_description` and `video_metadata` are stored verbatim.
  Without `Magento_ProductVideo` the entry is imported as a plain image, with a
  warning.
- **Safety valve** (as with categories), scoped per product: if any entry fails
  to resolve, that product is applied additively — new entries and metadata
  updates apply, nothing existing is removed.
- Media is written at the **default scope only** (`store_id = 0`):
  `store_view_code` does not affect it, so **send media on one store pass only**.
  Store-scoped labels and positions are out of scope. The one exception is a role
  attribute that already has a store-scoped row, which is kept in sync.
- **What observers see**: by default the products handed to product-save
  observers carry no gallery, and what this import changed is published as a
  batch event instead. Both are covered under "Events".
- A URL whose target file already exists is **not fetched again** (see
  *Re-Download Existing Files*). Publish a changed image under a new URL, replace
  the file out of band, or turn that setting on — with it on, a changed image
  replaces the stored bytes and a warning notes that the resized renditions under
  `pub/media/catalog/product/cache` are then stale for that file.
- Files are streamed to a temporary name and renamed into place, and their
  content is verified against the extension they claim before a byte is written,
  so neither a disguised payload nor a half-written file can end up somewhere a
  later run would trust.

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
- Tier / group prices: `catalog_product_entity_tier_price` with replace semantics
  diffed on the (website, all-groups, customer group, quantity) unique key,
  absolute or percentage values, an additive safety valve, and customer-group /
  website resolution by code or ID (see "Tier prices" above).
- Media gallery: downloads image URLs into `pub/media/catalog/product` (or
  accepts pre-uploaded paths), writes `catalog_product_entity_media_gallery`
  and its `_value` / `_value_to_entity` / `_value_video` children with replace
  semantics diffed by file path, an additive safety valve, per-entry
  label/position/disabled, external-video entries and the
  `image`/`small_image`/`thumbnail`/`swatch_image` roles (see "Product media
  gallery" above). All file I/O runs before the batch transaction opens. What
  changed per product is published as `readydata_import_product_media_changed`,
  and dispatched products can carry the gallery itself (see "Events").
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
size, continue-on-error, option auto-creation, URL conflict strategy, media
downloads, reindex mode, cache cleaning, event dispatch, logging.

The **Attribute Definitions** group has a single switch, **Enable Attribute
Definition Sync** (`readydata_import/attributes/auto_create`, default **off**),
the kill switch for the `POST /V1/readydata/attributes` endpoint. When off, the
endpoint is a no-op that reports every attribute as `skipped`/`disabled`. There
is intentionally no attribute-shape config here — scope, flags and placement are
supplied per attribute by the caller (the system of record).

### Media gallery

The **Media Gallery** group governs the `media` block:

| Setting | Default | Notes |
|---|---|---|
| Enable Media Gallery Import | on | Off skips the block entirely, downloads included. |
| Download Timeout | 15 s | Per image. |
| Download Concurrency | 4 | Images fetched at once, max 32. `1` is fully sequential. Each in-flight download holds up to 2 MB in memory before spilling to disk, so this multiplies the transfer-time footprint. |
| Maximum File Size | 10240 KB | Refused from `Content-Length` before the body transfers, and enforced again while streaming for origins that omit or understate it. |
| Allowed File Extensions | `jpg,jpeg,png,gif,webp` | Applies to downloads and pre-uploaded paths. SVG is deliberately absent — it can carry script. A download whose extension has no known image signature is refused even if allow-listed. |
| Allowed Download Hosts | *(empty)* | **Empty means any host.** See the caveats below. |
| Re-Download Existing Files | off | Off makes re-imports do no network I/O at all. |
| Auto-Assign Media Roles | on | See "Product media gallery" above. |

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
- **Include Media Gallery In Dispatched Events** (`hydrate_media`, default
  off) — before dispatching, read the batch's galleries and image roles in
  **two bulk queries** and put them on each dispatched product, so
  `getMediaGallery('images')`, `getMediaGalleryImages()` and
  `getMediaGalleryEntries()` (including each entry's `types` and
  `video_content`) return what a loaded product returns. Applies to **every**
  product in the batch, including ones whose payload carried no `media` block,
  and is **independent of "Enable Media Gallery Import"** — the gallery in the
  database is the product's gallery whether or not this import wrote it. Off by
  default because it changes what existing observers see; turn it on when a
  media-aware observer needs to run on imports. Depends on "Dispatch Product
  Save Events" being on.

Whenever product-save events are dispatched, the module suppresses Magento's
own URL-rewrite and inventory save observers for the duration of the import
(they are re-implemented in bulk by the pipeline), so they never double-write.

The dispatched product is a **notification carrier, not a persistable entity**.
It carries **no `origData`**: `getOrigData()` is empty and `dataHasChangedFor()`
reports every field as changed. That is deliberate — the importer never reads
pre-image state, so a partial `origData` would answer "unchanged" for fields
nobody snapshotted, and populating it at all makes Magento skip the protective
reload it does for an entity with no original data. It also has no attribute
set, so saving it through the EAV resource would schedule value-row deletions.
Read from it; do not save it. When both per-product toggles are on, the
`*_save_after` and `*_save_commit_after` events receive the **same instance**
for a product, as core does.

### Custom events

Batch-level events for integrations that want the delta rather than a
per-product callback. All fire after the batch commits, and only when they have
something to report:

| Event | Payload |
|---|---|
| `readydata_import_products_save_after` | `store_id`, `sku_to_id`, `created_skus`, `updated_skus`, `entity_ids` |
| `readydata_import_attribute_options_created` | `options_by_attribute` |
| `readydata_import_category_products_changed` | `store_id`, `category_ids`, `product_ids` |
| `readydata_import_product_media_changed` | `store_id`, `changes`, `sku_to_id`, `created_files`, `removed_files` |

`readydata_import_product_media_changed` is the one to hook for image CDNs,
optimisers and cache warmers. `changes` is keyed by SKU and carries:

| Key | Meaning |
|---|---|
| `entity_id` | the product's ID |
| `created` | files whose gallery row this batch inserted |
| `updated` | kept files whose label, position, `disabled`, media type or video record changed |
| `removed` | files whose gallery rows this batch deleted from **this** product |
| `roles` | role code => the file it now points at, or `null` where it was cleared as stale — only the roles this batch actually wrote |
| `partial` | the safety valve withheld removals, so the desired set was incomplete |

`roles` is a dimension of its own because a role can move between two files that
are both already in the gallery: a base-image swap changes no gallery row, and
without it the most storefront-visible media change there is would report
nothing. Products whose media this batch left exactly as it was are **absent**,
so a re-import of an unchanged feed reports nothing at all.

`created_files` and `removed_files` are deduplicated unions across the batch,
for consumers that work per file rather than per product. Read `removed_files`
as *"detached from the products this batch touched"*, **not** as "safe to
delete": a file that one product dropped and another kept or gained is excluded
from it, but the module cannot know about products outside the batch, so a
disk-level delete still needs its own reference check. The per-SKU `removed` is
exact and is not filtered this way.

Two things it does not report: a re-download that replaced the **bytes** behind
an existing path (the path is unchanged — see *Re-Download Existing Files*), and
removals of legacy junk rows whose stored path was NULL or a duplicate.

## Extending the pipeline

No placeholder steps remain — every dimension in the pipeline is implemented.
To add one: implement `ProcessorInterface` (plus `PreparableInterface` when the
step needs network or filesystem access before the batch transaction opens) and
register it in `etc/di.xml` (`ImportService`, argument `processors`). Position is
`getSortOrder()`, not the order of the XML items; core steps use 100–750 with
gaps left for third-party insertion. `AbstractPlaceholderProcessor` remains as the
base for a step that should be registered but inert until it is written.

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
- **Tier prices are global** (no store dimension): `store_view_code` does not
  affect them. The website dimension lives in the entry and is governed by
  *Catalog Price Scope*, not by the `tier_price` attribute's own scope — that
  column is installed as "Website" and core never switches it, because the
  scope-change observer only touches attributes whose input type is `price`.
- Under **Website** price scope the payload owns the product's whole tier-price
  set **across all websites**: a feed that sends one pass per website would have
  each pass wipe the other's rows. Restrict such a feed to entries with no
  `website`, or send every website's entries in one product payload.
- **Only `qty = 1` tier prices reach the price index.** Magento's price indexer
  joins the tier price table with `qty = 1`, so larger quantity breaks price the
  cart and appear in the product page's tier table but never move the indexed
  `final_price`/`min_price` — they will not affect layered-navigation price
  filters or price sorting. That is core behaviour, not a limitation of the
  importer.
- A **fixed tier price of `0` is accepted** (core's own validator allows
  `price >= 0`, and the live price indexer branches on `percentage_value`, so it
  indexes as 0). Be aware that Magento's *deprecated* `DefaultPrice` indexer
  branches on `value = 0` instead; a third-party price indexer still extending it
  would misread such a row as a percentage discount. Prefer
  `percentage_discount: 100` to express "free at this quantity".
- Tier prices are **not attached to dispatched product events**. The dispatched
  product deliberately carries no `tier_price` key: the payload's shape is nothing
  like the `price_qty`/`cust_group`/`website_id` array core puts there, and that
  key is what triggers core's tier-price save handlers — so an observer calling
  `$product->save()` would re-write the rows this import just wrote, against an
  empty `origData` in which every stored row diffs as new.
- Changing tier prices does **not** recollect existing shopping carts. Core's
  product save marks affected quotes for recollection; this module (like its
  `price` and `special_price` writes) does not, so carts already holding the
  product keep their old row totals until they are next recollected.
- Fractional `qty` is written as sent. Core additionally rejects `qty < 1` for
  product types that cannot use decimal quantities — keep quantity breaks
  integral unless the type allows otherwise.
- Customer group codes are matched **case-insensitively**, unlike SKUs. This
  follows core's own tier-price API rather than the module's case-sensitive SKU
  rule.
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
  Media is written against the product's *current* row, with no staging-update
  awareness — the same posture as the rest of the module.
- **Media is written at the default scope only**; `store_view_code` does not
  affect it. Send media roles inside `media`, never in `custom_attributes`: for
  a product carrying a `media` block the media role write happens last and
  repoints the default scope **and** every store view that already had a row —
  including one a `custom_attributes` role just created earlier in the same
  batch — so the `custom_attributes` value is silently overwritten.
- Each product gets its **own** gallery row per file, even when several products
  share the same file on disk. The row carries `media_type`, `disabled` and the
  video record, none of which have a product dimension, and a shared row would
  let one product's removal cascade the image away from all the others. The file
  itself is shared.
- A **rolled-back batch can leave downloaded files in `pub/media`** unreferenced.
  They are re-used, never re-downloaded, on retry, and are not garbage-collected.
- **Image URLs are fetched by the store.** With *Allowed Download Hosts* empty, a
  compromised feed can make the store request any URL it can reach, including
  internal ones. Downloads are extension-filtered, signature-verified before
  anything is written, size-capped, timeout-capped and redirect-capped, and a
  redirect may only target HTTP/HTTPS. When an allow-list *is* set it is applied to
  every redirect hop as well, not just the URL in the payload, so a permitted host
  cannot bounce the fetch onto an internal address. DNS rebinding and IP-literal
  hosts are not solved — set an allow-list in hardened environments. *Download Concurrency*
  also bounds how hard a single feed can make the store hit one origin.
- Database media storage (`Magento_MediaStorage`) is **not supported** for media
  import: files written to the local media directory would be invisible to the
  storefront. Media is refused with a clear per-product message.
- Third-party product-save observers see the gallery **only with *Include Media
  Gallery In Dispatched Events* on** (default off) — otherwise the lightweight
  product object the event dispatcher builds carries the payload's scalars only.
  With it on, the gallery and image roles are read from the database, so a
  product whose payload omitted `media` still reports its full gallery, but
  `label`, `position` and per-entry `disabled` are the **default-scope** values
  (an admin-authored store override is not reflected). Everything else on the
  object is still payload-only, not a database read. See "Events".
- With that setting on, an observer that calls `$product->save()` makes
  Magento's gallery save handler run. It cannot create or delete gallery rows
  and cannot move files (every hydrated entry carries its `value_id` and none is
  flagged `removed`), and the module **locks** `media_gallery` on the object so
  the handler bails before writing the store-scoped `gallery_value` rows this
  module never creates. An observer that means to rewrite the gallery must
  `unlockAttribute('media_gallery')` first — and see the note in "Events" about
  not saving the dispatched object at all.
- Media downloads run concurrently, but the whole batch still happens inside one
  request. For a large first-time import raise *Download Concurrency*, and use
  `batch_size` and `max_execution_time` to keep each request within its timeout —
  or pre-upload the files and send relative paths. The cap is per import and
  shared across hosts, so a feed pulling from several origins divides it between
  them.
- Run indexers in "Update by Schedule" mode for best throughput.

## Installation

```
composer require readydata/module-import
bin/magento module:enable ReadyData_Import
bin/magento setup:upgrade
```
