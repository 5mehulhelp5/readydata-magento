<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Api\Data;

/**
 * Configurable-product structure carried by a configurable parent.
 *
 * The parent declares its variation axes (super attribute codes) and the
 * child SKUs that make up its variations; the children themselves are
 * ordinary simple/virtual product payloads that carry their own option
 * values as custom_attributes. Send children before/with the parent so
 * their rows exist when the parent is linked (see ConfigurableProcessor).
 *
 * @api
 */
interface ConfigurableDataInterface
{
    public const SUPER_ATTRIBUTES = 'super_attributes';
    public const CHILDREN = 'children';

    /**
     * Attribute codes the product varies on (global-scope select attributes).
     *
     * @return string[]|null
     */
    public function getSuperAttributes(): ?array;

    /**
     * @param string[] $superAttributes
     * @return $this
     */
    public function setSuperAttributes(array $superAttributes): self;

    /**
     * SKUs of the child simple/virtual products making up the variations.
     *
     * @return string[]|null
     */
    public function getChildren(): ?array;

    /**
     * @param string[] $children
     * @return $this
     */
    public function setChildren(array $children): self;
}
