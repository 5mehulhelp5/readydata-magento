<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Data;

use ReadyData\Import\Api\Data\ConfigurableDataInterface;

class ConfigurableData implements ConfigurableDataInterface
{
    private ?array $superAttributes = null;
    private ?array $children = null;

    public function getSuperAttributes(): ?array
    {
        return $this->superAttributes;
    }

    public function setSuperAttributes(array $superAttributes): ConfigurableDataInterface
    {
        $this->superAttributes = $superAttributes;
        return $this;
    }

    public function getChildren(): ?array
    {
        return $this->children;
    }

    public function setChildren(array $children): ConfigurableDataInterface
    {
        $this->children = $children;
        return $this;
    }
}
