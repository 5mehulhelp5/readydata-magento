<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Data;

use ReadyData\Import\Api\Data\AmastyOptionSettingInterface;

class AmastyOptionSetting implements AmastyOptionSettingInterface
{
    private string $option = '';
    private ?int $storeId = null;
    private ?string $title = null;
    private ?string $image = null;
    private ?string $url = null;
    private ?string $description = null;
    private ?string $metaTitle = null;
    private ?string $metaDescription = null;
    private ?array $extra = null;

    public function getOption(): string
    {
        return $this->option;
    }

    public function setOption(string $option): AmastyOptionSettingInterface
    {
        $this->option = $option;
        return $this;
    }

    public function getStoreId(): ?int
    {
        return $this->storeId;
    }

    public function setStoreId(?int $storeId): AmastyOptionSettingInterface
    {
        $this->storeId = $storeId;
        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): AmastyOptionSettingInterface
    {
        $this->title = $title;
        return $this;
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function setImage(?string $image): AmastyOptionSettingInterface
    {
        $this->image = $image;
        return $this;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): AmastyOptionSettingInterface
    {
        $this->url = $url;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): AmastyOptionSettingInterface
    {
        $this->description = $description;
        return $this;
    }

    public function getMetaTitle(): ?string
    {
        return $this->metaTitle;
    }

    public function setMetaTitle(?string $metaTitle): AmastyOptionSettingInterface
    {
        $this->metaTitle = $metaTitle;
        return $this;
    }

    public function getMetaDescription(): ?string
    {
        return $this->metaDescription;
    }

    public function setMetaDescription(?string $metaDescription): AmastyOptionSettingInterface
    {
        $this->metaDescription = $metaDescription;
        return $this;
    }

    public function getExtra(): ?array
    {
        return $this->extra;
    }

    public function setExtra(?array $extra): AmastyOptionSettingInterface
    {
        $this->extra = $extra;
        return $this;
    }
}
