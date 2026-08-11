# ReadyData_Import

Bulk product import for Magento 2 via a REST endpoint, writing **directly to the
database** for performance. Products are processed in configurable batches
(default **500** per batch, one DB transaction each) through a pluggable
processor pipeline.

Three endpoints, each standalone:

| Endpoint | Purpose | ACL |
|---|---|---|
| `POST /V1/readydata/products` | Bulk create/update products | `ReadyData_Import::import` |
| `POST /V1/readydata/attributes` | Product attribute *definitions* | `ReadyData_Import::attributes` |
| `POST /V1/readydata/categories` | Categories and their properties | `ReadyData_Import::categories` |

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
      "categories_replace_scope": [2],
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
      "clear_attributes": ["special_label"],
      "store_values": [
        {"store_id": 3, "name": "Beispielprodukt", "url_key": "beispielprodukt",
         "custom_attributes": [{"attribute_code": "description", "value": "<p>Langer Text</p>"}]},
        {"store_view_code": "fr_fr", "name": "Produit exemple",
         "clear_attributes": ["special_label"]}
      ]
    }
  ],
  "settings": {"store_view_code": "default", "continue_on_error": true}
}
```

`settings` also accepts `store_id` (instead of `store_view_code`, and winning
over it), `root_category_id` and `batch_size`.

### Attribute value scoping

Values are written in the scope each attribute is configured with, keyed off
the scope being addressed (absent/`admin` = default scope):

- **Global** (`is_global = 1`): always written at store 0, whatever the
  scope.
- **Website** (`is_global = 2`): written to **every store view of the
  website** containing the addressed store view (including inactive views),
  mirroring core Magento's website-scope emulation. At the default scope,
  only the store-0 row is written.
- **Store view** (`is_global = 0`): written at the addressed store view only.

New products additionally get a store-0 fallback row for non-global values.

The request's own scope comes from `settings.store_view_code`, or from
`settings.store_id` for callers that already hold the ID — the ID wins when
both are given, and one no store view has fails the request exactly as an
unknown code does.

### Store-scoped values

`settings` names **one** scope. A product's `store_values` names any number
more, so a single request can carry the product's default-scope identity and
every localized value set it has, instead of one request per store view.

Each block addresses its store view by `store_id` or `store_view_code` (the ID
wins) and carries what the product itself carries at the default scope: the
value-bearing fields `name`, `price`, `status`, `visibility`, `weight`,
`url_key`, plus `custom_attributes` and `clear_attributes`. Everything with no
store dimension
— `websites`, `categories`, `links`, `media`, `stock`, `tier_prices`, the
attribute set and the product type — stays on the product and is written once.

The scope a block names only decides which store view is being *addressed*.
What that means per attribute is still the table above: a website-scoped
attribute in a block naming `de_de` fans out across that store's website, and
`settings` may name the default scope while a block writes at store 3.

Guards, each a per-product message (prefixed `[store N]`) and never fatal:

- **Global attributes are refused in a block**, not written. Having no store
  dimension, the value would land at store 0 and overwrite the product's own
  default-scope value from inside a block that named one store view. Send them
  on the product.
- **An unknown store view skips that block only** — one bad scope does not cost
  the product its other scopes, or its default-scope write.
- **A block naming the request's own scope merges into it**, the block winning
  per attribute; two blocks naming the same store view merge the same way, so
  the last one wins. Merging rather than writing twice keeps the result
  independent of statement ordering.

A block never generates the store-0 fallback row a new product gets: the
default scope is what the product itself carries, and copying a translation
into it would make one store view's text the value every other store view
inherits. It also never *generates* a `url_key` — a generated slug is the
product's identity on the storefront, not a per-store translation — though an
explicit one is written and used (below).

#### Store-scoped URL keys

A `url_key` in a block is a real per-store slug: that store view's rewrites —
canonical and category-path alike — are built from it, and every other store
keeps the default one. The default-scope key is still what decides whether a
product gets rewrites at all; a store override with nothing to override is not
a slug.

An override already in the database is honoured even when the payload does not
mention it. Regenerating a store's rewrite from the default key would discard
it — including one an earlier run of this module wrote, which would make a
replay flip the storefront URL back and forth. The batch's own scoped key wins
over the stored one, being the newer value.

Conflict resolution, 301 history and the not-visible cleanup were already
per store and are unchanged.

### Clearing attribute values

A `null` (or absent) value in `custom_attributes` means **leave unchanged** —
safe for sparse feeds. To actually remove a stored value, list the attribute
code in `clear_attributes`. A clear DELETEs the EAV value rows in the same
scope a write would target (see "Attribute value scoping"): global attributes
at the default scope, website-scoped attributes across all store views of the
addressed store's website, store-scoped attributes at the addressed store view
(a cleared store row falls back to the default value, like "Use Default" in the
admin).

A `store_values` block has its own `clear_attributes`, evaluated against that
block's scope — which is how one store view drops an override and goes back to
inheriting the default while its neighbours keep theirs.

Guards (each a per-product warning in `results[].messages`, never fatal):
unknown and static attributes are skipped; required attributes cannot be
cleared at the default scope; when the same attribute is both written and
cleared **in the same scope**, the write wins. A clear in a block is subject to
the same refusal a write there is (global attributes). Clearing `url_key` does
not remove existing URL rewrites.

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
- Assignments are **global** (no store dimension): they are written once,
  whatever scopes the payload names, and a `store_values` block cannot carry
  them.
- `\` escapes the next character: `\/` is a literal slash inside a name
  (`"Default Category/Wo\/Men"` names the category `Wo/Men`), `\\` a literal
  backslash (names containing `\` MUST escape it), and a trailing lone `\`
  is a literal backslash. A digits-only *name* is referenceable as an
  escaped segment (`"Default Category/\42"`), while a bare `"42"` entry
  stays a numeric ID.

#### How far the replace reaches

By default the replace covers the **whole catalog**: every link the payload
does not list is removed, wherever it sits. That is right when one caller owns
the catalog, and wrong the moment a product belongs to **several root trees fed
by several sources** — there, each source's push deletes the links the others
just wrote, and the only symptom is storefront navigation quietly going
missing. Note the existing safety valve does not catch this: it withholds
deletions when a reference fails to *resolve*, and these resolve perfectly.

Two ways to bound it, per product and instance-wide:

```jsonc
{
  "sku": "ABC-123",
  "categories": ["Outdoor Catalog/Men/Shirts"],
  "categories_replace_scope": [29]        // only links under root 29 may be removed
}
```

| `categories_replace_scope` | Effect |
| --- | --- |
| omitted / `null` | the system configuration decides (below) |
| `[29]` | links under root 29 may be removed; every other root tree is left alone |
| `[]` | an explicit empty scope — nothing is removed, so the payload is purely additive for this product |

`readydata_import/categories/replace_scope` sets the default for payloads that
say nothing (*Stores → Configuration → ReadyData Import → Categories → Product
Category Replacement Reaches*):

| Value | Effect |
| --- | --- |
| **Whole Catalog** (default) | today's behaviour, unchanged |
| **Only the Roots the Payload Names** | the replace removes links only under the roots this product's own entries resolve into |

The default stays *Whole Catalog* deliberately: switching it would silently
redefine what an existing caller's `"categories": []` means.

Which brings up the one edge worth knowing. Under *Only the Roots the Payload
Names*, `"categories": []` names no roots and therefore **removes nothing** — a
warning says so. To empty one tree, name it explicitly:
`"categories": [], "categories_replace_scope": [29]`.

An entry that is not a root category is ignored with a per-product warning
rather than silently narrowing the scope. A link whose category cannot be
placed in a tree — deleted between the assignment read and the write — is kept:
a link that cannot be shown to be in scope is not removed.

#### Pinning the root a path resolves under

Magento enforces no uniqueness on root names, so two roots can both be called
`Shop` — and they are two different catalogs. A path's first segment then names
both, and this module resolves that by taking the **lowest `entity_id`**: the
assignment lands in whichever tree happened to be created first, with no error
anywhere.

`settings.root_category_id` settles it, for both endpoints:

```jsonc
{
  "products": [{"sku": "ABC-123", "categories": ["Shop/Men/Shirts"]}],
  "settings": {"root_category_id": 29}
}
```

Every path in the request then resolves under root 29 and nothing else. A path
whose first segment does not name that root is **refused, not reparented** —
the two statements contradict each other, and guessing which one was meant is
how a subtree ends up in the wrong catalog.

Leave it out and nothing changes: a unique root name resolves as it always has.

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
- Links are **global** (no store dimension): written once, whatever scopes the
  payload names.
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
- Tier prices are **global** (no store dimension): `store_view_code` does not
  affect them, the website dimension lives in the entry, and they are written
  once whatever scopes the payload names.
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

- **Downloads run before the batch transaction opens and before its import
  locks**, so neither database row locks nor the module's own named locks are ever
  held across network I/O, and they run **concurrently** up to *Download
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
  `store_view_code` does not affect it, and a `store_values` block cannot carry
  it, so it is written once whatever scopes the payload names.
  **Per-store labels, positions and `disabled` flags are out of scope** — the
  gallery value tables do have a store dimension, and unlike everything else on
  this endpoint the module does not write it. The one exception is a role
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
`elapsed_ms`), the `store_id` the request actually ran in, and a per-SKU
`results` array with `status`, `messages` and — when the product named scopes
beyond the request's own — `store_results`. Errors are per-product; a failing
product does not abort the request.

### Scoped results

```jsonc
{
  "received": 1, "created": 1, "updated": 0, "failed": 0,
  "store_id": 0,                                  // the scope the request ran in
  "results": [
    {
      "sku": "ABC-123", "entity_id": 42,
      "status": "created",                        // the product, at store_id above
      "messages": [],
      "store_results": [
        {"store_id": 3, "status": "updated", "reason": null, "messages": []},
        {"store_id": 5, "status": "skipped", "reason": null,
         "messages": ["Attribute \"special_price\" is global and has no store dimension; …"]},
        {"store_id": null, "status": "skipped", "reason": "unknown_store",
         "messages": ["Store values for store view ID 99 were skipped: no such store view."]}
      ]
    }
  ]
}
```

The response's `store_id` is what `results[].status` and `results[].messages`
are about. A caller cannot infer it — `/rest/V1/...` resolves against the
default store view rather than the admin scope, and only `settings` overrides
that — so it is echoed back rather than assumed.

`store_results` holds **one entry per `store_values` block**, in payload order,
so a caller can match rows to the blocks it sent. The request's own scope is
deliberately not repeated there: the product result already is that scope's
outcome, so a caller recording one history row per (product, scope) reads the
product result plus this list with nothing described twice. The field is absent
for a payload that named no scopes.

The rows use the **same four fields and the same status vocabulary as the
category endpoint** (`ScopeResultInterface`), so one mapping serves both:

| Status | Meaning |
| --- | --- |
| `updated` | Values or clears were applied in this scope. A clear alone counts — the scope applied a removal. |
| `unchanged` | The scope resolved and nothing differed. Never reported here: product values are upserted rather than compared. The category endpoint does report it. |
| `skipped` | Nothing was applied. `reason` and the messages say why: every value the block carried was refused, the block named a scope and carried nothing, or its store view does not exist. |
| `error` | The product failed, so nothing survives in any of its scopes — a batch is one transaction. Never about the scope alone. |

`store_id` is `null` when the block never resolved to a store view at all, with
`reason: "unknown_store"`. It is never `0`: the default scope is the one scope
this list never covers, so reporting 0 would name the wrong thing. A block that
*deliberately* names store 0 — legal here, when `settings` names a store view and
the block carries the fallback values — is merged into the product's own pass and
reported by the product's top-level result instead.

A message belongs to exactly one result: raised while writing a block, it is on
that block's entry; otherwise on the product's.

The counters count **products, not product-scopes**: a product created with
three localized value sets is one `created`.

### Concurrency

Overlapping imports are only serialized where they could actually corrupt each
other, and only for as long as it takes. What needs guarding is an **unkeyed
read-then-create**: look for a row, not find it, insert it, where the database
has no unique key to catch a second request doing the same thing at the same
moment. There are four of those, and each has a **lock of its own**:

| Lock | Guards |
| --- | --- |
| `readydata_product_create` | **product rows.** `catalog_product_entity.sku` carries a plain index, **not** a unique key — Magento enforces SKU uniqueness in PHP. Two concurrent runs naming the same new SKU both miss the read and both insert, leaving two rows for one SKU, each with its own EAV, gallery and stock satellites. |
| `readydata_product_import` | **the category tree.** Missing path segments are created on demand, and nothing is unique on `(parent_id, name)` or on a category `url_key`. Two concurrent runs both miss and both insert, leaving a duplicate sibling — which makes that path permanently ambiguous — or a `url_rewrite` unique-key violation that fails whichever request loses. The category endpoint takes this same lock. (The name is the historical one, from when a single lock guarded only the tree; keeping it means a request still running the previous release serializes against this one on the least recoverable race.) |
| `readydata_media_gallery` | **media gallery rows.** `catalog_product_entity_media_gallery` has no key on `(attribute_id, value)`, and its `value_id` is an autoincrement, so a fresh row cannot be re-selected by its own data at all. Two concurrent runs carrying the same file for one product both insert it and the image is listed twice. |
| `readydata_attribute_options` | **attribute options.** With *Auto-Create Missing Attribute Options* on, missing select/multiselect options are created. `eav_attribute_option` has no key on the label, so two concurrent runs writing the same new option label both insert and the attribute ends up with two options of the same name. The attribute endpoint takes this same lock. |

Separate names matter because the payloads that reach them are largely disjoint:
a media feed and a category sync cannot duplicate each other's work, so one lock
for both would serialize them for nothing.

**Scope of the hold.** Locks are taken **per batch**, not per request, and only
the ones that batch's own products can race on. Each is held from just before its
batch's transaction opens until that transaction commits or rolls back — no
longer, because the next holder has to be able to *see* the row that was
inserted, and no more, because:

- **image downloads happen first, unlocked.** They are the longest thing a batch
  does and they race with nothing;
- **indexing happens last, unlocked.** A partial reindex of a large payload is
  easily the longest thing the request does;
- **after-commit events happen after the release.** An observer is someone
  else's code doing an unknown amount of work, and by then the rows are visible.

So a competing import waits for one batch transaction, not for a feed's worth of
downloads and reindexing. Per-batch scoping also means one unknown SKU in a
5 000-product payload no longer makes the whole request serialize — only the
batch carrying it does. Lowering *batch size* shortens the hold further.

Deciding all this costs **one indexed query per batch**: its SKUs are looked up
before the locks, because nothing in the payload reveals whether a product row
has to be created. A batch whose SKUs all exist and that carries no `categories`,
no `media` and no `custom_attributes` (with option auto-creation on) runs
**lock-free**, concurrently with anything else — a price or stock refresh,
typically, which is the case the fast path is for.

The remaining tests are deliberately conservative: any `categories` field at all
(including `[]`), any `media` field at all (`[]` means "remove everything", which
is still work), and any `custom_attributes` while option auto-creation is on.
`store_values` blocks are **not** consulted — their custom attributes only ever
resolve option labels they did not create. Erring towards taking a lock costs a
serialized batch; erring the other way costs a duplicate product, category, image
or attribute option, and none of those is cheap to undo.

**When a lock cannot be taken**, a set is acquired all-or-nothing in one fixed
order (so two requests wanting overlapping sets cannot deadlock on each other),
and:

- the **first** batch is rejected outright with `Another import is already
  running.` — nothing has been committed, so the request did not happen. Note
  that its file downloads have already run; nothing is wasted, because a
  downloaded URL maps to a deterministic path that the retry re-uses;
- a **later** batch fails on its own, reporting `another import is holding …`
  against its products, and the request still returns results for the batches
  that did commit. Later batches also wait longer (30 s rather than 10 s):
  abandoning batch 4 of 10 leaves the caller reconciling a partial import.

That outright rejection is the **only** failure these endpoints answer with
**`429 Too Many Requests`**; everything else is a `400`. The distinction is the
point: a 400 means the request is wrong and will stay wrong, while this one means
nothing is wrong at all and sending it again shortly is the whole remedy. The
body carries a machine-readable reason so a caller never has to match the
message:

```json
{
  "message": "Another import is already running. Try again later.",
  "parameters": {
    "reason": "import_locked",
    "locks": ["readydata_product_import"],
    "retry_after": 10
  }
}
```

429 rather than 503 (which reads as "this store is unhealthy" to every proxy and
health check between the caller and PHP) or 409 (accurate about the state, but it
tells a caller to resolve a conflict when there is nothing to resolve but the
wait). No `Retry-After` header: the hint is in the body, where a non-HTTP caller
can read it too. Match on `parameters.reason`, not on the status alone — a bare
429 from a proxy in front of the store is somebody else's rate limiting.

One race the locks still do **not** cover: `url_rewrite` is unique on
`(request_path, store_id)`, so concurrent requests cannot duplicate a rewrite —
but they read the conflict set before writing, which makes the `error` and
`append` conflict strategies unreliable under concurrency and can leave the
loser's request path pointing at the other product. Lock-free and per-batch
scoping both widen the window in which that can happen.

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
`skipped`, `failed`, `elapsed_ms`) plus a per-attribute `results` array with
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
loses a race is re-read and treated as an update rather than failing.

This endpoint also seeds attribute **options**, so it takes the product import's
`readydata_attribute_options` lock as well — which is what stops the two
endpoints inserting the same new option label at the same time (a race this
module previously documented rather than closed). The cost is that an attribute
sync and a product import carrying `custom_attributes` now wait for each other;
the rejection then reads `Another import is already running.`, naming what
actually blocked. Both locks are held for the whole request: attribute payloads
are a feed's attribute list, sent as a pre-flight step, so there is nothing to
win from narrowing them.

Either rejection comes back as **`429`** with `parameters.reason:
"import_locked"`, exactly as the product endpoint's does (see "Concurrency"
there). That matters most here: this endpoint's own wording says *attribute
sync*, not *import*, so a caller matching the product endpoint's message never
recognised it — and never retried a refusal that only ever needed retrying.

Each
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

## Category sync

A third endpoint owns the categories themselves. The product endpoint already
creates missing categories on demand, but only as bare nodes carrying a name and
the resolver's defaults; this one lets the caller state what each category
should actually *be*, and reports back what happened to it. **Standalone** — no
product import required — and normally called as a pre-flight step so the
categories a feed references already exist with the right properties. Off by
default (see Configuration).

```
POST /rest/all/V1/readydata/categories
Authorization: Bearer <integration token>   (ACL: ReadyData_Import::categories)
```

```json
{
  "categories": [
    {
      "path": "Outdoor Catalog",
      "is_active": 1
    },
    {
      "path": "Default Category/Men",
      "is_active": 1,
      "include_in_menu": 1
    },
    {
      "path": "Default Category/Men/Shirts",
      "category_id": 42,
      "name": "Shirts",
      "url_key": "mens-shirts",
      "is_anchor": 1,
      "position": 10,
      "custom_attributes": [
        {"attribute_code": "description", "value": "<p>All shirts</p>"},
        {"attribute_code": "meta_title", "value": "Shirts"}
      ],
      "clear_attributes": ["meta_keywords"]
    },
    {
      "category_id": 43,
      "path": "Default Category/Men/Coats",
      "parent_path": "Default Category/Women"
    },
    {
      "path": "Default Category/Men/Clearance",
      "delete": 1
    },
    {
      "path": "Default Category/Men/Hats",
      "store_values": [
        {"store_id": 3, "name": "H\u00fcte",
         "custom_attributes": [{"attribute_code": "description", "value": "<p>Alle H\u00fcte</p>"}]},
        {"store_view_code": "fr_fr", "name": "Chapeaux", "clear_attributes": ["meta_keywords"]}
      ]
    }
  ],
  "settings": {"store_view_code": "default", "continue_on_error": true}
}
```

Response: summary counters (`received`, `created`, `updated`, `unchanged`,
`deleted`, `skipped`, `failed`, `elapsed_ms`) plus a per-category `results` array with the
resolved `path`, the `entity_id`, the `root_category_id` of the tree it landed
in, a `status`, a machine-readable `reason`, `messages`, and — when the entry
named scopes beyond the request's own — `store_results`. Errors are per
category; a failing category does not abort the request. Every entry gets exactly one result row, rejected ones included; the
only case where `results` is shorter than `received` is a payload that sends the
same category twice, which collapses to one entry with the last one winning.

Nothing here is inferred from **absence**: a category the caller stops sending is
never deactivated or deleted. This is deliberately *unlike* the `categories`
field on the product payload, which has replace semantics. Use `is_active: 0` to
retire a category, or `delete: 1` to remove it outright — but only ever because
the payload said so.

### Identity: path, plus `category_id` to rename

`path` is the full path from a level-1 root name and uses the same grammar as
the product payload's `categories` field — `/` separates segments only when
unescaped, so `Default Category/Wo\/Men` is two segments, the second being
`Wo/Men`. A single-segment path addresses the root itself.

A path alone cannot express a rename: the new name no longer matches the stored
path. So sending a `name` that differs from the last path segment is
`skipped` with `rename_requires_category_id` unless `category_id` is also given,
in which case the ID identifies the category and `path` becomes informational —
cross-checked against the stored parent, but never a source of the name. **Only
an explicit `name` renames.** Sending `category_id` with a path you kept on file
from before a rename therefore updates the other properties and leaves the name
as it is, rather than reverting it. The same holds for the tree: only
`parent_path`/`parent_category_id` moves a category, so a stale path cannot move
it back either.

Every result carries `entity_id` once the entry has been matched to a row —
including refusals like `move_not_supported` — so a caller that is the system of
record can store it and send it back. It is null only when nothing was
identified: `ambiguous_path`, a `parent_not_found` on the entry's *own* path, or a
rejected payload. (A `parent_not_found` about an unresolvable move *destination*
does carry it — the category itself was found.)

Two sibling categories sharing a name make a path ambiguous. Reads elsewhere in
this module resolve that by taking the lowest `entity_id`; a *write* refuses —
the entry is `skipped` with `ambiguous_path` and the candidate IDs in its
message. Send `category_id` to disambiguate. (For two *roots* sharing a name,
`root_category_id` does it without needing an ID for the category itself — see
"Ordering, parents and roots".)

### Ordering, parents and roots

Entries are processed **shallowest path first**, so a parent sent in the same
request is created and committed before the child that needs it — payload order
does not matter. Entries addressed only by `category_id` are processed last,
since they may target something created earlier in the same run.

A created category must have room for its slug. `url_key` is derived from the
name when the payload omits it, so two differently named siblings can easily want
the same one — and `url_rewrite` is unique on `(request_path, store_id)`, which
would otherwise surface as `Could not save category: URL key for specified store
already exists.` with the other category unnamed. That collision is
`destination_url_key_taken` instead, carrying the conflicting ID; send an explicit
`url_key` to place the category anyway. A *name* collision cannot arise on create
— a sibling already carrying the name is updated rather than duplicated.

Missing parents are **not** created implicitly: a category the caller never
asked for would get none of the properties they specified and would not appear
in the response at all. An unresolvable parent is `parent_not_found`, or
`unknown_root` when the first segment names no existing root — send that root as
a single-segment path in the same payload and it will be created first.

**Roots are first-class.** A single-segment path (`Outdoor Catalog`) creates a
level-1 root under the catalog tree root when it does not exist and updates it
when it does; a `category_id` naming a level-1 category is writable the same way,
rename included. The catalog tree root itself (entity `1`) is not a category and
is refused with `root_not_writable`. A root is *not* attached to a store: this
endpoint never writes `store_group.root_category_id`, so a new root has no
storefront presence until someone points a store group at it in the admin — at
which point Magento regenerates that store's rewrites itself.

Magento enforces no uniqueness on root names, so two roots can share one. As with
sibling categories, a *write* to such a name refuses with `ambiguous_path` and the
candidate IDs — both as the target and as the first segment of a deeper path —
because the two roots are two different catalogs.

**`root_category_id` picks one.** On `settings` it pins every path in the
request; on an entry it pins that entry alone and wins over the request's, for a
payload spanning several trees. It is the only disambiguator available on a
first run, when no `category_id` has been recorded yet:

```jsonc
{
  "categories": [
    {"path": "Shop/Men", "root_category_id": 29},
    {"path": "Outdoor/Tents", "root_category_id": 33}
  ],
  "settings": {"root_category_id": 29}
}
```

A pin is checked against the name it claims: a path starting `Shop` under a pin
naming a root called `Outdoor` is `unknown_root`, not a silent reparent. A pin
that is not a root category at all is the same refusal. Sending `category_id`
still works and still wins — it identifies the row outright.

Reads elsewhere in the module (product-import path resolution) still take the
lowest `entity_id` when no pin is given; the product endpoint accepts the same
`settings.root_category_id` to stop relying on that.

If an entry renames a category at default scope, any later entry in the same
request whose path runs through the old name is `skipped` with
`stale_parent_path` rather than silently resolving to the wrong node. A
*store-scoped* rename does not trigger this: path resolution matches store-0
names throughout the module, and those are untouched.

### Reconciliation — create or update to match the source

Per category:

- **missing** (below an existing parent) → **created**;
- **exists, nothing differs** → `unchanged`, with **no save at all** — no
  observers, no URL rewrite regeneration, no reindex. A replayed payload is
  therefore genuinely free;
- **exists, something differs** → **updated**;
- **exists under a different parent**, no destination in the payload → `skipped`
  with `move_not_supported`;
- **exists under a different parent than `parent_path`/`parent_category_id` says**
  → **moved**, reported as `updated` (see "Moving a category");
- **`delete: 1`** → **deleted**, or `unchanged` / `already_absent` when it was
  already gone (see "Deleting a category").

Values are compared loosely against the stored ones, because EAV round-trips
everything as a string and a strict comparison would report every flag as
changed on every sync.

`is_active`, `include_in_menu` and `is_anchor` are transported as **`0`/`1`
integers, not booleans** — Magento's EAV layer treats `false` as "empty" and
deletes the value row instead of storing a `0`.

Anything else on the category attribute set (`description`, `meta_*`,
`display_mode`, `landing_page`, `available_sort_by`, `default_sort_by`,
`page_layout`, …) goes through `custom_attributes`. Values are written
**verbatim** — there is no option-label resolution here, so a category `select`
attribute needs its option *ID*. Attributes Magento maintains itself (`path`,
`level`, `parent_id`, `children_count`, `url_path`, `position` via
`custom_attributes`, `image`) are rejected with `invalid_definition` rather than
silently dropped.

`clear_attributes` reverts an attribute to its default: at store scope it drops
the store override, at default scope it removes the value. Clearing something
that has no value to remove — in particular an attribute at store scope that
only ever had a default-scope value — is `unchanged`, not a no-op save, so
clears stay as replayable as everything else. `name`, `url_key`,
`url_path`, `is_active`, `include_in_menu`, `is_anchor` and Magento-owned
attributes cannot be cleared (`protected_attribute`) — clearing a required
attribute makes every later save of that category fail validation, and clearing
the name or URL key strands its rewrites and its descendants' `url_path`.

### Moving a category

A move is stated, never inferred. `parent_path` (or `parent_category_id`) names
the parent the category **should** be under; when it differs from the stored one
the category is reparented, subtree and all. Omit both and the parent is left
alone — which is what makes a `path` the caller kept on file from before an
earlier rename or move harmless rather than a silent instruction to undo it. Off
by default (see Configuration).

```json
{
  "categories": [
    {
      "category_id": 42,
      "path": "Default Category/Men/Shirts",
      "parent_path": "Default Category/Women"
    }
  ]
}
```

A move **requires `category_id`**, for the same reason a rename does: the moment
the category lands elsewhere its old path stops identifying it, so a path-only
move would not survive a replay. Without one the entry is `skipped` with
`move_requires_category_id`. With one, `path` becomes purely informational and the
usual `move_not_supported` cross-check is dropped — the path may legitimately be
either the pre-move or the post-move one, and both replay to `unchanged`.

`parent_category_id` wins when both are given; it is how a caller names a
destination whose path is ambiguous. `parent_category_id: 1` is the catalog tree
root and **promotes** the category to a level-1 root; a single-segment
`parent_path` moves it under that root. Demoting a root is allowed too — a root
is just a child of the tree root here.

Refusals, in the order they are checked: `store_scope_structural_change` (a move
is default-scope only — `parent_id`, `path` and `level` are columns with no store
dimension), `move_disabled`, `parent_not_found` / `unknown_root` /
`ambiguous_path` for a destination that does not resolve, `move_into_descendant`
for a destination that is the category itself or inside its own subtree,
`root_in_use` when the category is some store group's `root_category_id` —
demoting that would leave the storefront pointing at a non-root — `cross_root_move`
(below), and the two destination-collision refusals below that.

**A move between root categories needs its own switch.** The two roots are two
different catalogs, so a destination under a different root takes the category,
its whole descendant subtree and their product assignments off one storefront
and onto another. Core performs it without complaint, and nothing else here
catches it: the descendant check only looks downwards, and `root_in_use` only
fires for a store group's own root. Refused as `cross_root_move` unless
**Allow Moves Between Root Categories**
(`readydata_import/categories/allow_cross_root_move`, default **off**) is on —
which is separate from **Allow Category Moves**, so enabling ordinary
reparenting does not quietly enable this. A move within one root tree is
unaffected.

**The destination must have room for it.** A move whose new parent already holds
a category with the same name is `destination_name_taken`; one that would collide
on `url_key` is `destination_url_key_taken`. Both carry the conflicting category's
ID so the caller can act on it. The same two checks guard a create and a rename,
so a collision reads the same way whichever operation ran into it.

These are checked up front because the two collisions fail very differently if
left to the write. `catalog_category_entity` has **no unique key on
`(parent_id, name)`**, so the name case would simply succeed and leave the path
permanently ambiguous — refused by every later write to it, and silently resolved
to the lowest `entity_id` by the product import's path lookup. The `url_key` case
*does* have a backstop (`url_rewrite` is unique on `(request_path, store_id)`) but
it only fires from deep inside the save as `UrlAlreadyExistsException`, after a
nested rollback, naming neither category.

A move re-sequences siblings: the category is appended to the end of its new
parent's children and the gap it left behind closes. This is the one case where
the endpoint reorders anything (contrast `position`, which never makes room).

URLs cascade the way a rename's do. Core regenerates the category's rewrites and
its whole descendant subtree's, plus the rewrites of every product in it, and
reads `catalog/seo/save_rewrites_history` itself — so old category URLs 301
rather than 404, with no extra work from this endpoint.

**One move per subtree per request.** Core memoizes a category's children for the
whole request, and both the move plugin and the rewrite observer read through that
cache, so a second move or delete *inside* a subtree this request already moved
would regenerate rewrites from the pre-move tree — wrong URLs, silently. Such an
entry is `skipped` with `stale_parent_path`; send it in a separate request.

### Deleting a category

`delete: 1` removes a category. Deletion in Magento is **recursive**: the whole
descendant subtree goes with it, along with every affected category's URL rewrites
and product assignments. Products themselves are never deleted. Off by default
(see Configuration).

```json
{
  "categories": [
    {"path": "Default Category/Men/Clearance", "delete": 1},
    {"category_id": 77, "name": "Old Tree", "delete": 1, "delete_children": 1}
  ]
}
```

Because a wrong path would otherwise remove a whole branch of the catalog,
deleting a category that still has children needs **`delete_children: 1`** as an
explicit acknowledgement; without it the entry is `skipped` with `has_children`
and the child count in `messages`. A delete cannot also set values —
`invalid_definition` — because a payload that both removes a category and
describes what it should be has no coherent reading.

Deleting something that is already gone is `unchanged` with `already_absent`,
not an error: the caller's desired state holds, which keeps a replayed delete
free. That covers an unresolvable path as well as an unknown `category_id` — if
the parent is not there, neither is the category. An **ambiguous** path is the
exception and is refused, since removing a subtree on a guess cannot be undone.

Other refusals: `root_not_writable` for the catalog tree root, `root_in_use` when
a store group's `root_category_id` points at it (core would throw "Can't delete
root category."; this reports it cleanly instead), `wrong_store_root`,
`store_scope_structural_change` (the row is shared by every store, so a delete has
no store dimension), and `delete_disabled`.

Deletes run **after every other entry**, and among themselves **deepest first**.
Both orderings matter: creating something under a parent the same request removes
only reads one way round, and taking a parent before an explicitly requested child
would leave the child's own entry reporting `already_absent` rather than
`deleted`. Payload order is irrelevant. A delete and an update of the *same*
category collapse to one entry in the usual way, with the last one winning.

### URLs and redirects

Magento only derives a `url_key` when the stored one is empty, so a rename would
otherwise keep the old slug forever. On rename the endpoint derives the new
`url_key` from the new name (an explicit `url_key` always wins), which is what
makes the native rewrite cascade regenerate the category and its whole
descendant subtree. It also sets `save_rewrites_history` from
`catalog/seo/save_rewrites_history`, so old category URLs 301 instead of 404 —
the same guarantee the product import gives.

A rename is subject to the same sibling checks a move is: a new name a sibling
already carries is `destination_name_taken`, and a `url_key` — supplied or derived
— that a sibling already owns is `destination_url_key_taken` (see "Moving a
category" for why these are checked rather than left to the write). An explicit
`url_key` in the payload is what gets checked, not the one the name would derive.

Both checks evaluate **default scope**, which is the scope every structural write
uses and the scope whose names path resolution matches. A store-scoped write skips
them: a store-view rename cannot make a store-0 path ambiguous. The gap that
leaves is a sibling whose `url_key` exists *only* as a store-view override — that
collision is not predicted here and still surfaces the way it always did, as the
repository's own exception reported against that category.

Roots are the exception: a root's `url_key` is part of no storefront URL, so core
generates no rewrites for one and a root rename cascades nothing. The endpoint
still derives the slug, so the root carries a matching one for the day a store
group points at it.

### Store scope

`settings.store_view_code` selects the scope, as on the product endpoint. At
store scope only attribute **values** are written, and only the ones named in
the payload. Anything without a store dimension is refused with
`store_scope_structural_change` rather than written globally behind the caller's
back: creating a category (`path`/`level`/`parent_id` are columns, not scoped
attributes) and setting `position`, which is likewise one column shared by every
store. Omit `store_view_code` (or use `admin`) for the default scope.

A store-scoped write must also target a category the named store view actually
shows, that is, one under its store group's `root_category_id`. Anything else is
`wrong_store_root`: the write would succeed and be invisible on the storefront the
caller named. Precedence, when more than one refusal applies: a category that was
identified reports `wrong_store_root` (with its `entity_id`) ahead of
`store_scope_structural_change`, and an `ambiguous_path` outranks both — which
category is wrong-rooted cannot be said before knowing which one was meant. A
missing category under a *foreign* root is `wrong_store_root` rather than "omit
`store_view_code` to create it", which would be wrong advice.

Note the `all/` in the URL. Magento resolves `/rest/V1/...` against the default
store view, not the admin scope, so the store the values land in comes from the
URL unless the endpoint overrides it — which it does, from `store_view_code`.
Keeping `/rest/all/V1/...` is still the safe habit.

#### Several scopes in one request

`settings` names **one** scope. A category's `store_values` names any number
more, so a single request can carry its structure and every localized value set
it has instead of one request per store view:

```jsonc
{
  "path": "Default Category/Men/Hats",
  "is_active": 1,
  "store_values": [
    {"store_id": 3, "name": "Hüte"},
    {"store_view_code": "fr_fr", "name": "Chapeaux", "clear_attributes": ["meta_keywords"]}
  ]
}
```

A block carries only what has a store dimension — `name`, `url_key`,
`is_active`, `include_in_menu`, `is_anchor`, `custom_attributes`,
`clear_attributes`. The structural fields are not on the block at all, which is
the `store_scope_structural_change` rule moved out of a runtime refusal and into
the payload's shape: `position`, `parent_path`, `delete` and the rest stay on
the category and are written once.

Blocks run **after** the category's own write and **inside the same
transaction** — a half-localized category is worse than an unlocalized one, so a
failure anywhere takes the whole entry with it. Each reports its own row in
`store_results`:

The rows use the **same four fields and the same status vocabulary as the
product endpoint** (`ScopeResultInterface`), so one mapping serves both:

| Status | Meaning |
| --- | --- |
| `updated` | Values in this scope differed and were written. |
| `unchanged` | Nothing differed — no save at all, so a replay in this scope is free. |
| `skipped` | Nothing was attempted; `reason` says why. |
| `error` | The category's own write failed, so nothing survives in any of its scopes. |

The request's own scope is not repeated in `store_results`: the category's
top-level result is that scope's outcome, and `root_category_id` on the result
says which tree it landed in. A caller recording one history row per (category,
scope) reads the entry plus its blocks with nothing described twice.

There is one row per block, in payload order, so rows and blocks stay in step.
`store_id` is `null` on a block that never resolved to a store view — including
one that named the default scope, since 0 would name the scope this list never
covers.

Per-block refusals, none of which costs the category or its other scopes:

- `unknown_store` — no such store view;
- `wrong_store_root` — that store view's storefront shows a different root, so
  the write would succeed and be invisible there;
- `invalid_definition` — the block names the default scope (which the category
  itself writes, and which a block would otherwise overwrite for every store
  view that inherits it), or a second block names a store view an earlier one
  already claimed. Two blocks for one store view are refused rather than
  written in order: which of them wins is not something a caller should have to
  reason about.

A category that was itself skipped or errored reports **no** `store_results` at
all — there is nothing to localize, and its own `reason` is the whole story.

### Concurrency & indexing

Category sync takes the product import's **category-tree lock**
(`readydata_product_import`, rejecting with `Another import is already running.`
as **`429`** with `parameters.reason: "import_locked"` — see "Concurrency" under
the product endpoint), because both mutate the tree and there is no unique key on
`(parent_id, name)` to fall back on — two concurrent runs would resolve the same
missing path, both miss, and both insert. This endpoint takes it on **every**
request, since every request to it is a category write; the product endpoint
takes it only for the batches whose payload can reach the tree, and takes none of
its other locks on behalf of this one (see "Concurrency" under the product
endpoint).

It is held for the **whole request**, where the product import holds its locks one
batch at a time. The difference is the sibling map: children-by-name is read once
per depth bucket and is only invalidated by this request's own writes, so
releasing between entries would let another request insert a sibling the map
cannot see — which is the duplicate the lock exists to prevent. Narrowing it means
re-reading siblings per entry rather than per bucket, and that trade has not been
made.

Because the product import now releases its locks between batches, a delete
committed here can land between two of its batches. Its cached path → ID map
re-verifies every entry on each use, so a path whose category has gone is
resolved again (and re-created, as that endpoint creates missing paths) rather
than written to as a dangling ID.

Each category is processed in its own transaction and reported independently.
That transaction is what makes a **move** atomic: `changeParent()` re-paths the
subtree with relative `UPDATE`s, and `Category::move()` wraps them in a
transaction that nests inside ours, so a failure rolls the whole move back.

Category writes go through the category model, so `catalog_category_flat` and
`catalog_category_product` are already reindexed by Magento's own commit
callbacks; the endpoint additionally invalidates `catalogsearch_fulltext` (whose
documents carry `category_ids`) and registers FPC tags for the touched
categories **and their descendants**, since a `url_path` change cascades down. A
move or delete also tags the **products** in the affected subtree, whose canonical
URLs are derived from the category path when "Use Categories Path for Product
URLs" is on. For a delete the subtree is captured *before* the rows go, since
nothing can derive it afterwards.

One thing a move does that this endpoint does not control: core reindexes
`catalog_category_product` for the affected paths synchronously, inside our
transaction, whenever that indexer is in "Update on Save".

### Limits (v1)

Attaching a root to a store group (`store_group.root_category_id`), category
images, and assigning products from the category side (that is owned by the
product payload's `categories` field) are out of scope. Moving and deleting are
supported but each need their config switch turned on, a move between root
categories needs a switch of its own, and a move is limited to one per subtree
per request (see "Moving a category").

## What it does today

- Creates/updates `catalog_product_entity` + all scalar EAV values with
  multi-row `INSERT ... ON DUPLICATE KEY UPDATE` (one statement per value
  table per batch, chunked at 1000 rows).
- **Any number of store scopes in one request**: the request's own scope plus a
  `store_values` block per store view, each honouring the attribute's own
  global / website / store configuration, and each reporting its own outcome in
  `store_results` (see "Store-scoped values" and "Scoped results" above).
- Resolves select/multiselect option labels to IDs; auto-creates missing
  options (configurable).
- Website assignment (additive; new products default to the default website).
- Category assignments (replace semantics, paths or IDs, auto-creation of
  missing subtrees), with the replace bounded to chosen root categories and
  paths pinnable to one root on a catalog with same-named roots (see "Category
  assignments" above).
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
  direct product rewrites per store — each from that store's own `url_key`
  where it has one — with a configurable conflict strategy (append suffix /
  skip / error).
- Indexing: partial reindex of affected IDs (default), invalidate, or none.
  Indexers in "Update by Schedule" mode are left to mview (DB triggers pick
  up direct writes). FPC tags of touched products are cleaned.
- Concurrency guard: four named locks, one per unkeyed read-then-create, each
  taken only by the batches whose payload can reach it and held only for that
  batch's transaction — never across image downloads or indexing (see
  "Concurrency").
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

The **Categories** group has four switches, all default **off**, plus one
setting that belongs to the *product* endpoint:

| Setting | Path | Notes |
|---|---|---|
| Enable Category Sync | `readydata_import/categories/enabled` | The kill switch for the whole `POST /V1/readydata/categories` endpoint. When off it is a no-op that reports every category as `skipped`/`disabled` without taking a lock or writing anything. |
| Allow Category Moves | `readydata_import/categories/allow_move` | Gates reparenting. When off, an entry naming a destination is `skipped`/`move_disabled` and nothing is written. |
| Allow Category Deletion | `readydata_import/categories/allow_delete` | Gates `delete: 1`. When off, every delete entry is `skipped`/`delete_disabled` before anything is even resolved. |
| Allow Moves Between Root Categories | `readydata_import/categories/allow_cross_root_move` | Gates a move whose destination is under a *different* root. Separate from Allow Category Moves, because the two roots are two different catalogs — see "Moving a category". Refused as `cross_root_move` when off. |
| Product Category Replacement Reaches | `readydata_import/categories/replace_scope` | Default `all_roots`. How far the **product** payload's `categories` field reaches when it replaces assignments — see "How far the replace reaches". **Not** gated on the switch above: it governs the product endpoint, which has nothing to do with whether category sync is enabled. |

The endpoint is off by default because creating and renaming categories reshapes
storefront navigation and category URLs; moves and deletes are gated *again*
because their blast radius is a whole subtree, and a delete is irreversible.
Note the master switch gates only the endpoint — the product import's on-demand
category creation is unaffected, and so is the replace scope.

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
- **A batch is one transaction, and that now spans every scope a product
  names.** A failure while writing a product's third `store_values` block rolls
  back the first two, and the rest of the batch with them — where separate
  per-store requests used to fail independently. This is deliberate (a
  half-localized product is worse than an unlocalized one) but it changes what
  `continue_on_error` means: it resumes at the next *batch*, never at the next
  scope. The per-scope messages in `store_results` say which scope caused it.
- **A product `categories` replace reaches the whole catalog by default.** On a
  catalog whose root trees are fed by different sources, each feed's push then
  deletes the links the others just wrote, and the only symptom is storefront
  navigation quietly going missing — the existing additive safety valve does not
  catch it, because those references resolve perfectly. Bound it with
  `categories_replace_scope` per product or
  `readydata_import/categories/replace_scope` instance-wide (see "How far the
  replace reaches").
- **Bypasses the product model**: plugins/observers on product save do NOT
  run. That is the point, but audit your customizations before adopting.
  Exception: **categories** are saved through the category model/repository
  (path/level maintenance, url_key, URL rewrites), both when auto-created by a
  product import and when written by the category endpoint, so category-save
  plugins and observers DO run for them.
- Duplicate sibling category names are ambiguous. Path resolution for a *read*
  (product→category assignment) picks the lowest entity_id, deterministically;
  the category sync endpoint refuses to guess and reports `ambiguous_path`. When
  the duplicate is at the *root*, `settings.root_category_id` (both endpoints)
  or a per-entry `root_category_id` (category endpoint) settles it outright. It
  also refuses to *create* the situation: a move or rename that would land a
  category on a sibling's name is `destination_name_taken`, because
  `catalog_category_entity` has no unique key on `(parent_id, name)` and the write
  would otherwise succeed and poison that path for good.
- **A category move re-sequences siblings and reindexes synchronously.** It is
  appended to the end of its new parent's children (passing no "after" position
  would instead put it *first* and shift everything up), the gap it left closes,
  and core reindexes `catalog_category_product` for the affected paths inside our
  transaction when that indexer is in "Update on Save".
- **Only one move per subtree per request.** Core's `ChildrenCategoriesProvider`
  memoizes a category's children for the whole request, and the move plugin and
  rewrite observer both read through it, so a second structural change inside a
  just-moved subtree would build rewrites from the old tree. Those entries are
  refused with `stale_parent_path` rather than silently producing wrong URLs.
- **Deleting is recursive and irreversible.** The descendant subtree, its URL
  rewrites and its `catalog_category_product` rows all go; products survive.
  `delete_children: 1` is required before a non-empty category is removed, and a
  category some store group has adopted as its root is refused with `root_in_use`
  (core would throw "Can't delete root category." mid-batch instead).
- **The sibling-collision guards are the category endpoint's, not the product
  import's.** `CategoryPathResolver`'s on-demand subtree creation goes through
  `CategoryWriter::createBare()` and is deliberately left unguarded: it has no
  per-entry result row to report a refusal into, and its documented contract is
  to throw so the batch rolls back with the real reason (see the next bullet). A
  product feed referencing a path whose slug is taken therefore still fails its
  batch rather than reporting `destination_url_key_taken`.
- **Category creation now fails its batch instead of being reported per path.**
  A category is created through the repository, which runs its own transaction;
  when that fails inside the import's batch transaction the connection is left
  flagged as partially rolled back, so every later write and the commit itself
  would fail with an unrelated "Partial rollback is not supported". The failure
  is therefore re-thrown so the batch rolls back cleanly and reports the real
  reason (typically a URL-key conflict).
- **Category creation is forced to default scope.** `CategoryRepository::save()`
  takes its store from the store manager and ignores `setStoreId()`, so calling
  `/rest/V1/...` instead of `/rest/all/V1/...` used to write the category name
  at the default store view with no store-0 row — invisible to every store-0
  name lookup, and duplicated on the next import. Writes are now wrapped in
  explicit store-0 emulation, but `/rest/all/V1/...` remains the correct URL.
  Both callers go through the same `CategoryWriter`, so the defaults and the
  emulation cannot drift between an auto-created and an endpoint-created
  category.
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
- **Media is written at the default scope only**; neither `store_view_code` nor
  a `store_values` block affects it, and per-store labels, positions and
  `disabled` flags are **out of scope** — it is the one part of the product
  payload with a store dimension the module does not write. Send media roles
  inside `media`, never in `custom_attributes`: for
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
bin/magento setup:di:compile   # on a compiled (production) deployment
```

### Upgrading to the typed lock rejection

A lock conflict used to be a `400` that callers recognised by its message; it is
now a `429` carrying `parameters.reason: "import_locked"` (see "Concurrency").
**Update the caller first.** A caller that only knows the old signal stops
recognising the new one, and a refusal it would have retried becomes a hard
failure — so it has to accept both before this module starts sending the new one.
Recognising both is all that is needed; nothing has to be switched over
afterwards, since the wording is unchanged.
