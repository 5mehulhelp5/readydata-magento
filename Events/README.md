# ReadyData_Events

Pushes Magento events to ReadyData: a curated catalogue of subscribable events,
a durable queue, and a signed batched POST to one registered subscriber.

The architecture is Adobe's — the `observer.*` / `plugin.*` naming, the field
allow-list, the queue with its status machine, the batch cron and the retention
sweep all come from `magento/commerce-eventing`, which is a proven production
design. What differs is deliberate and listed in [Differences from Adobe's
eventing](#differences-from-adobes-eventing). Adobe's modules are Adobe
Commerce–only and deliver to Adobe I/O Events rather than to us, so this is a
re-implementation of the design, not a use of it.

```
Magento event ──▶ capture ──▶ readydata_event_queue ──▶ cron ──▶ POST ──▶ ReadyData
                 (filter,      (durable, MySQL)         (batch,   (HMAC-signed
                  extract)                               retry)    CloudEvents)
```

## Installation

Ships in the `readydata/magento-modules` package alongside `ReadyData_Import`,
which it depends on.

```
bin/magento module:enable ReadyData_Events
bin/magento setup:upgrade
bin/magento setup:di:compile   # on a compiled (production) deployment
```

Nothing is captured until eventing is enabled, a subscriber is registered and at
least one subscription exists. An instance with no subscriptions pays only the
cost of the registered hooks, measured below.

## Configuration

**Stores → Configuration → ReadyData → Eventing**, or over REST.

| Setting | Default | Notes |
|---|---|---|
| Enable Eventing | off | Nothing is captured or delivered while off. |
| Instance ID | derived | Identifies this store in every event. **Give staging and production different values** or ReadyData cannot tell their events apart. |
| Buffer Size | 500 | Events held before one multi-row INSERT. |
| Maximum Queue Depth | 100000 | Above this, capture stops and logs, rather than filling the disk. |
| Delivery Batch Size | 100 | Events per HTTP request. |
| Maximum Retries | 7 | Then the event is dead-lettered. |
| Retry Backoff | 60s | Multiplied by attempt count. |
| Retention | 3 days | Settled events only; waiting events are never deleted. |

## REST API

Under `/rest/all/V1/readydata/eventing/`, ACL-guarded by `ReadyData_Events::manage`
(`::queue` for the health endpoint), using the same integration credentials as
`ReadyData_Import`.

| Method + path | Purpose |
|---|---|
| `GET subscribers` | the registered destination (secret withheld) |
| `POST subscribers` | register — **returns the signing secret once** |
| `DELETE subscribers/:code` | deregister; cascades to its subscriptions |
| `GET subscriptions` | list |
| `POST subscriptions` | subscribe, or update an existing subscription |
| `DELETE subscriptions/:id` | unsubscribe |
| `GET supported` | every subscribable code — feeds ReadyData's event picker |
| `GET queue` | depth, status counts, oldest waiting event, and `hooked` |
| `POST test` | deliver a synthetic event now, proving connectivity |

Subscription changes take effect on the **next request, with no deploy**. That is
the property the whole design is built around; see below.

### Registering

```bash
curl -X POST "$BASE/rest/all/V1/readydata/eventing/subscribers" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"subscriber":{"code":"readydata","endpointUrl":"https://readydata.example/events/gemoss"}}'
```

```bash
curl -X POST "$BASE/rest/all/V1/readydata/eventing/subscriptions" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"subscription":{"eventCode":"observer.catalog_product_save_commit_after",
                       "enabled":true,"fields":["sku"]}}'
```

## Wire format

Batched CloudEvents 1.0. CloudEvents because it costs nothing and any future
subscriber that is not ReadyData already knows how to read it.

```json
{
  "instance_id": "gemoss-prod",
  "events": [{
    "specversion": "1.0",
    "id": "0f8c…",
    "source": "magento/gemoss-prod",
    "type": "observer.catalog_product_save_commit_after",
    "time": "2026-08-11T09:14:22Z",
    "datacontenttype": "application/json",
    "data": {"sku": "ABC-1", "entity_id": 4211}
  }]
}
```

Headers: `X-ReadyData-Instance`, `X-ReadyData-Delivery-Id`, and
`X-ReadyData-Signature: t=<unix>,v1=<hex hmac-sha256 of "t.body">`.

**Delivery is at-least-once.** A 2xx lost in transit is re-sent, so `id` is the
idempotency key and **the receiver must dedupe on it**.

The timestamp is inside the signed material, so a captured payload cannot be
replayed forever. Verify with a skew tolerance (300s is reasonable) and compare
in constant time — `Model\Delivery\Signer::verify()` is the reference
implementation.

## What gets captured

`Model/Catalogue.php` defines the subscribable set: 15 entity prefixes × 4
lifecycle events, 4 standalone events and 3 intercepted service contracts — **67
codes**. Two hook kinds are generated because neither covers the set alone:

- **Observers** for Magento's own dispatch, post-commit by default.
- **Plugins** where no event exists. `Magento\Inventory\Model\SourceItem\Command\SourceItemsSave`
  **dispatches no events at all**, so on an MSI store a plugin is the only way a
  stock change is visible.

Prefer `*_save_commit_after` over `*_save_after`: the former fires after the
transaction commits (verified — transaction level 0 vs 1), and a thin event whose
premise is "ReadyData re-reads the source of truth" would otherwise race the commit.

### Fields

Dot-notation paths, resolved against the event data and falling back to its
primary entity — so `sku` finds the product's sku, and `order.customer_email`
works too. Leave `fields` empty for the **thin default**: identifiers only, with
ReadyData re-reading. `["*"]` sends every scalar the entity carries and is how
personal and payment data ends up on the wire; name fields instead.

### Rules and gates

Rules are `field | operator | value`, ANDed, evaluated before anything is
queued. Operators: `eq neq gt gte lt lte in nin contains starts_with ends_with
empty not_empty regex`. An unknown operator **fails closed**.

Every operator is value-based and there is deliberately **no "changed since"
predicate**: `ReadyData_Import` re-emits product events with no `origData`, so
`dataHasChangedFor()` reports every field as changed and a change-based rule
would be meaningless on exactly the events most in need of filtering.

When a rule cannot express the condition, a subscription may name a
`gate_class` implementing `Api\EventGateInterface`. Note the trade: **a rule is
remote configuration, a gate is a deploy.**

## Loop suppression

ReadyData writes to Magento through `ReadyData_Import`, which deliberately
re-emits the core save events so third-party observers still see imports.
Without a guard, every import feeds itself straight back.

Capture injects `ReadyData\Import\Model\ImportState` and skips while
`isImporting()`. The per-subscription `ignore_readydata_origin` flag, **on by
default**, makes the guard visible and overridable rather than invisible module
behaviour. Turning it off on a product event points the pipeline at itself.

Verified against the real importer, not only in unit tests: guard on, a 5-SKU
import queues 0 events; guard off, the same import queues 5.

## Performance

Measured on Magento 2.4.8-p5 in production mode with a 122-hook catalogue:

| | Cost |
|---|---|
| Per request, hooks registered and idle | **+0.06 ms** (~0.5 µs per registered event) |
| Per event that actually fires | **+0.79 µs** |
| Page render impact | **none measurable** (below ±3% run-to-run noise) |
| Catalogue events during storefront traffic | **zero** — save/delete events do not occur on a page view |

The design registers hooks for the whole catalogue and decides at dispatch time
whether anything is subscribed, rather than generating hooks only for what is
subscribed. That costs the constant above and buys the thing this module exists
for: **a REST subscribe takes effect immediately, with no SSH and no recompile.**

A code outside the catalogue is refused at subscribe time — nothing would capture
it — and needs `Model/Catalogue.php` extended, `bin/magento readydata:events:generate`,
and a recompile.

## Operations

Two jobs in their own cron group `readydata_events`, so a slow delivery cannot
delay Magento's `default` group:

| Job | Schedule | Does |
|---|---|---|
| `readydata_events_dispatch` | every minute | claims a batch, POSTs it, retries with backoff, dead-letters after `max_retries` |
| `readydata_events_clean` | daily 03:00 | deletes settled events past retention |

Queue statuses: `0` waiting, `1` sent, `2` failed, `3` in progress, `4`
dead-lettered. The claim is a single atomic `UPDATE` stamping a `lock_token`, so
concurrent cron nodes cannot double-send. A dispatcher killed mid-flight leaves
rows claimed; they are reclaimed after 15 minutes.

Logs go to `var/log/readydata_events.log`.

### When events are not arriving

Check `GET queue` first:

- **`hooked: false`** — an upgrade skipped generation or a compile ran against an
  older catalogue. The module is installed, enabled and emitting nothing.
- **`enabled: false`** or no `subscriberCode` — nothing is configured.
- **deep queue, old `oldestWaitingAt`** — cron is not running. Depth alone cannot
  distinguish a busy store from a broken cron; age can.
- **`deadLettered` > 0** — the endpoint rejected events until retries ran out;
  `info` on each row holds the last response.

Then `POST test`, which proves envelope, signature, network and endpoint
synchronously.

**A throwing observer earlier in the chain silently costs events.** Magento runs
observers in sequence and an exception aborts the rest, so another module can
prevent capture without any error surfacing on our side. This is not
theoretical — it happened on the first real test run, where core's
`ImageResizeAfterProductSave` threw and `ImportEventDispatcher` swallowed it
while reporting a successful import. Reconciliation, not hook ordering, is the
real guarantee.

## Events narrow the work; they do not guarantee completeness

**No hook sees every write.** A native `bin/magento import` or a third-party
integration writing SQL directly is invisible to observers and service-contract
plugins alike. Neither is a store whose cron stopped, nor a delivery that 2xx'd
into a crash on the far side.

So the model is: **events narrow scope, cursors decide truth.** An arriving event
marks an object as needing work — the cheap, low-latency path. ReadyData's
existing scheduled run still asks what changed since each object's cursor and
syncs whatever the event path dropped. A dropped event is then a *latency*
problem, not a data problem.

The honest pitch for this module is latency, not correctness. Turn it off and the
platform behaves exactly as before, just with more delay. Anyone selling it as
"we no longer need the scheduled sync" has it backwards.

## Differences from Adobe's eventing

| Adobe | Here |
|---|---|
| Subscriptions in `config.php` / `env.php` | DB table, so REST edits need no deploy |
| Hooks generated only for subscribed events | Curated superset registered at build time, subscription checked at dispatch |
| Publishes to Adobe I/O Events | POSTs directly to the registered subscriber |
| OAuth server-to-server to the bus | HMAC-signed payloads |
| Failures stay at status 2 | Distinct status 4, so "needs a human" is queryable |
| — | Loop suppression (Adobe never had to write to Magento) |
| — | Gate classes, per-request entity dedupe, queue-depth guard |

## Layout

```
Api/                    service contracts + EventGateInterface
Console/                readydata:events:generate
Cron/                   DispatchQueue, CleanQueue
Model/Capture/          EventCapture, FieldExtractor, RuleEvaluator, GateRegistry, QueueBuffer
Model/Delivery/         Dispatcher, EnvelopeBuilder, Signer
Model/Subscriber/       Subscriber + repository (secret encrypted at rest)
Model/Subscription/     Subscription, SubscriptionMap (cached), repository
Observer/               CaptureObserver — one class, every catalogue event
Plugin/                 SourceItemsSaveCapture (MSI), ProductRepositoryCapture
etc/events.xml          GENERATED — edit Model/Catalogue.php and regenerate
```

## Known limits in this version

- **One subscriber.** The schema and endpoints are multi-capable; the dispatcher
  and state machine are written for one. A second destination is a
  schema-compatible follow-up, not a rewrite.
- **No near-real-time path.** Delivery is the ≤60s cron. The message-queue
  priority path is a later phase, so no store has to run an extra consumer.
- **`priority` and `coalesce_by`** are stored and honoured for per-request dedupe
  but do not yet drive a coalescing window.
- **No admin grid** over the queue yet; `GET queue` and the log are the tools.
