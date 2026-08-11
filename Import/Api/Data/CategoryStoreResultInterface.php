<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Api\Data;

/**
 * What happened to one CATEGORY in one of the store scopes its payload named
 * beyond the request's own — one entry per `store_values` block, in payload
 * order.
 *
 * Shape and vocabulary are {@see ScopeResultInterface}, shared with the product
 * endpoint. Two notes specific to categories:
 *
 * - `unchanged` is load-bearing here. Values are compared before saving, so a
 *   replayed payload writes nothing in a scope and says so — the property that
 *   makes the whole endpoint free to re-run.
 * - store 0 is never reported. A block naming the default scope is refused
 *   outright (`invalid_definition`) rather than written, because the category
 *   itself writes that scope and a block overwriting it would make one store
 *   view's translation the value every other view inherits. A refusal with no
 *   resolvable scope carries `store_id: null`.
 *
 * `reason` uses {@see CategorySyncResultInterface}'s vocabulary
 * (`unknown_store`, `wrong_store_root`, `invalid_definition`, …), so a caller
 * has one set of reason codes for the endpoint rather than two.
 *
 * @api
 */
interface CategoryStoreResultInterface extends ScopeResultInterface
{
}
