<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Model\Data;

use ReadyData\Events\Api\Data\EventDescriptionInterface;

class EventDescription implements EventDescriptionInterface
{
    private ?string $code = null;
    private ?string $kind = null;
    private ?bool $hooked = null;
    private ?string $entity = null;
    private ?string $derivedFrom = null;
    private ?array $fields = null;
    private ?string $sample = null;

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): EventDescriptionInterface
    {
        $this->code = $code;

        return $this;
    }

    public function getKind(): ?string
    {
        return $this->kind;
    }

    public function setKind(?string $kind): EventDescriptionInterface
    {
        $this->kind = $kind;

        return $this;
    }

    public function getHooked(): ?bool
    {
        return $this->hooked;
    }

    public function setHooked(?bool $hooked): EventDescriptionInterface
    {
        $this->hooked = $hooked;

        return $this;
    }

    public function getEntity(): ?string
    {
        return $this->entity;
    }

    public function setEntity(?string $entity): EventDescriptionInterface
    {
        $this->entity = $entity;

        return $this;
    }

    public function getDerivedFrom(): ?string
    {
        return $this->derivedFrom;
    }

    public function setDerivedFrom(?string $derivedFrom): EventDescriptionInterface
    {
        $this->derivedFrom = $derivedFrom;

        return $this;
    }

    public function getFields(): ?array
    {
        return $this->fields;
    }

    public function setFields(?array $fields): EventDescriptionInterface
    {
        $this->fields = $fields;

        return $this;
    }

    public function getSample(): ?string
    {
        return $this->sample;
    }

    public function setSample(?string $sample): EventDescriptionInterface
    {
        $this->sample = $sample;

        return $this;
    }
}
