<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Api\Data;

/**
 * Related / up-sell / cross-sell links carried by the linking product.
 *
 * Each sub-field is an independent set of target SKUs in display order. The
 * targets are ordinary products that must already exist — send them before or
 * with the linking product (see LinkProcessor). Grouped-product children are a
 * different link type and are not covered here.
 *
 * @api
 */
interface ProductLinksInterface
{
    public const RELATED = 'related';
    public const UP_SELL = 'up_sell';
    public const CROSS_SELL = 'cross_sell';

    /**
     * SKUs shown as related products, in display order.
     *
     * @return string[]|null
     */
    public function getRelated(): ?array;

    /**
     * @param string[] $related
     * @return $this
     */
    public function setRelated(array $related): self;

    /**
     * SKUs shown as up-sells, in display order.
     *
     * @return string[]|null
     */
    public function getUpSell(): ?array;

    /**
     * @param string[] $upSell
     * @return $this
     */
    public function setUpSell(array $upSell): self;

    /**
     * SKUs shown as cross-sells (in the cart), in display order.
     *
     * @return string[]|null
     */
    public function getCrossSell(): ?array;

    /**
     * @param string[] $crossSell
     * @return $this
     */
    public function setCrossSell(array $crossSell): self;
}
