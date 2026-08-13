<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Plugin;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use ReadyData\Events\Model\Capture\EventCapture;

/**
 * Service-contract hooks for products.
 *
 * Not the product default — that is the post-commit observer, because it is the
 * only one of the two that sees a ReadyData direct-SQL import, and it sees
 * everything this plugin sees. Generated anyway for stores where a service
 * contract is the primary write path, but the coverage is not a superset in
 * either direction, and neither hook sees a native `bin/magento import` or a
 * third-party integration writing SQL directly. That blind spot is why the
 * scheduled reconciliation run stays the guarantee of completeness.
 */
class ProductRepositoryCapture
{
    private const CODE_SAVE = 'plugin.magento.catalog.product_repository.save';
    private const CODE_DELETE = 'plugin.magento.catalog.product_repository.delete_by_id';

    public function __construct(private readonly EventCapture $capture)
    {
    }

    public function afterSave(
        ProductRepositoryInterface $subject,
        ProductInterface $result,
        ProductInterface $product
    ): ProductInterface {
        $this->capture->capture(self::CODE_SAVE, [
            'sku' => $result->getSku(),
            'entity_id' => $result->getId(),
            'product' => $result,
        ]);

        return $result;
    }

    public function afterDeleteById(
        ProductRepositoryInterface $subject,
        bool $result,
        string $sku
    ): bool {
        // Only on a truthful delete: the repository returns false rather than
        // throwing in some paths, and announcing a deletion that did not happen
        // would have ReadyData drop a product that still exists.
        if ($result) {
            $this->capture->capture(self::CODE_DELETE, ['sku' => $sku]);
        }

        return $result;
    }
}
