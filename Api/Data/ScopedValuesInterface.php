<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Api\Data;

/**
 * How a `store_values` block names the store view it applies to — the one thing
 * a product block and a category block have in common.
 *
 * Shared so the two endpoints resolve a scope the same way
 * ({@see \ReadyData\Import\Model\Cache\StoreWebsiteMap::findScopeStoreId()}) and
 * name an unresolvable one the same way
 * ({@see \ReadyData\Import\Model\Cache\StoreWebsiteMap::describeScope()}).
 * What each endpoint does with store 0 is its own rule and stays on the child
 * interface: a product block may address the default scope, a category block may
 * not.
 *
 * @api
 */
interface ScopedValuesInterface
{
    /**
     * Target store view ID. Wins over store_view_code when both are given.
     *
     * @return int|null
     */
    public function getStoreId(): ?int;

    /**
     * Target store view code, for callers that address stores by code.
     * Ignored when store_id is set.
     *
     * @return string|null
     */
    public function getStoreViewCode(): ?string;
}
