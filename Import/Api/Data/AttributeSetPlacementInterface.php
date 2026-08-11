<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Api\Data;

/**
 * One attribute-set (and group) an attribute should belong to.
 *
 * Placement is additive: the attribute is added to each listed set/group and
 * never removed from sets it already belongs to. An omitted group falls back
 * to the set's default group; an omitted set falls back to the entity's
 * default attribute set.
 *
 * @api
 */
interface AttributeSetPlacementInterface
{
    public const SET = 'set';
    public const GROUP = 'group';
    public const SORT_ORDER = 'sort_order';

    /**
     * Attribute set name or numeric ID.
     *
     * @return string|null
     */
    public function getSet(): ?string;

    /**
     * @param string|null $set
     * @return $this
     */
    public function setSet(?string $set): self;

    /**
     * Group name within the set (created if missing).
     *
     * @return string|null
     */
    public function getGroup(): ?string;

    /**
     * @param string|null $group
     * @return $this
     */
    public function setGroup(?string $group): self;

    /**
     * @return int|null
     */
    public function getSortOrder(): ?int;

    /**
     * @param int|null $sortOrder
     * @return $this
     */
    public function setSortOrder(?int $sortOrder): self;
}
