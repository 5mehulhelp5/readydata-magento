<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Data;

use ReadyData\Import\Api\Data\ProductLinksInterface;

class ProductLinks implements ProductLinksInterface
{
    private ?array $related = null;
    private ?array $upSell = null;
    private ?array $crossSell = null;

    public function getRelated(): ?array
    {
        return $this->related;
    }

    public function setRelated(array $related): ProductLinksInterface
    {
        $this->related = $related;
        return $this;
    }

    public function getUpSell(): ?array
    {
        return $this->upSell;
    }

    public function setUpSell(array $upSell): ProductLinksInterface
    {
        $this->upSell = $upSell;
        return $this;
    }

    public function getCrossSell(): ?array
    {
        return $this->crossSell;
    }

    public function setCrossSell(array $crossSell): ProductLinksInterface
    {
        $this->crossSell = $crossSell;
        return $this;
    }
}
