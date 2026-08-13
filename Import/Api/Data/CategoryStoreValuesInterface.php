<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Api\Data;

/**
 * One store view's worth of a category's values, carried on the category
 * itself so a single request can write its structure and every localized value
 * set together.
 *
 * Carries nothing but a scope and the store-dimensioned values — the structural
 * fields are not on this interface at all, which is the same rule
 * `store_scope_structural_change` enforces for a store-scoped request, moved
 * from a runtime refusal into the payload's shape.
 *
 * @api
 */
interface CategoryStoreValuesInterface extends CategoryValuesInterface, ScopedValuesInterface
{
    public const STORE_ID = 'store_id';
    public const STORE_VIEW_CODE = 'store_view_code';

    /**
     * Target store view ID. Wins over store_view_code when both are given.
     *
     * A block naming neither, naming a store view that does not exist, or
     * naming one whose storefront shows a different root category, is skipped
     * with its own result row — one bad scope must not cost the category its
     * other scopes.
     *
     * 0 is refused rather than accepted: the default scope is what the category
     * itself writes, and a block silently overwriting it would make one store
     * view's translation the value every other view inherits.
     *
     * @return int|null
     */
    public function getStoreId(): ?int;

    /**
     * @param int|null $storeId
     * @return $this
     */
    public function setStoreId(?int $storeId): self;

    /**
     * Target store view code, for callers that address stores by code.
     * Ignored when store_id is set.
     *
     * @return string|null
     */
    public function getStoreViewCode(): ?string;

    /**
     * @param string|null $storeViewCode
     * @return $this
     */
    public function setStoreViewCode(?string $storeViewCode): self;
}
