<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Media\Cleanup;

/**
 * Carries a product's gallery paths from before its delete to after.
 *
 * Needed because the two moments are on opposite sides of a transaction. By the
 * time `catalog_product_delete_after_done` fires — the only point at which
 * removing a file is safe, since it is dispatched after EntityManager::delete()
 * has committed — the gallery rows naming those files are gone. So they have to
 * be read on `catalog_product_delete_before` and remembered.
 *
 * A shared instance for the length of the request. Keyed by product id, and
 * {@see take()} removes as it reads so a delete that never reaches its "after"
 * event (an exception mid-transaction, say) cannot leave paths behind for an
 * unrelated product to pick up later.
 */
class DeletedProductMedia
{
    /** @var array<int, string[]> product id => stored paths */
    private array $paths = [];

    /**
     * @param string[] $paths
     */
    public function remember(int $productId, array $paths): void
    {
        if ($paths) {
            $this->paths[$productId] = $paths;
        }
    }

    /**
     * @return string[] the paths remembered for this product, and forgets them
     */
    public function take(int $productId): array
    {
        $paths = $this->paths[$productId] ?? [];
        unset($this->paths[$productId]);

        return $paths;
    }
}
