<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Data;

use ReadyData\Import\Api\Data\AttributeSetPlacementInterface;

class AttributeSetPlacement implements AttributeSetPlacementInterface
{
    private ?string $set = null;
    private ?string $group = null;
    private ?int $sortOrder = null;

    public function getSet(): ?string
    {
        return $this->set;
    }

    public function setSet(?string $set): AttributeSetPlacementInterface
    {
        $this->set = $set;
        return $this;
    }

    public function getGroup(): ?string
    {
        return $this->group;
    }

    public function setGroup(?string $group): AttributeSetPlacementInterface
    {
        $this->group = $group;
        return $this;
    }

    public function getSortOrder(): ?int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(?int $sortOrder): AttributeSetPlacementInterface
    {
        $this->sortOrder = $sortOrder;
        return $this;
    }
}
