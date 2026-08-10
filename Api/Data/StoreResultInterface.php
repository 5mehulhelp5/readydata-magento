<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Api\Data;

/**
 * What happened to one PRODUCT in one of the store scopes its payload named
 * beyond the request's own — one entry per `store_values` block, in payload
 * order.
 *
 * Shape and vocabulary are {@see ScopeResultInterface}, shared with the category
 * endpoint. Two notes specific to products:
 *
 * - `unchanged` never appears. Values are upserted rather than compared, so a
 *   scope that resolved and carried something reports `updated` even when the
 *   stored value was already that.
 * - a block may legitimately name store 0 — the default scope — when the
 *   REQUEST's scope is a store view and the block carries the fallback values.
 *   Such a block is merged into the product's own pass rather than reported
 *   here, since the product's top-level result already is that scope's outcome.
 *
 * @api
 */
interface StoreResultInterface extends ScopeResultInterface
{
}
