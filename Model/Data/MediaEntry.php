<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Data;

use ReadyData\Import\Api\Data\MediaEntryInterface;

class MediaEntry implements MediaEntryInterface
{
    private string $file = '';
    private ?string $label = null;
    private ?int $position = null;
    private ?bool $disabled = null;
    private ?array $roles = null;
    private ?string $mediaType = null;
    private ?string $videoProvider = null;
    private ?string $videoUrl = null;
    private ?string $videoTitle = null;
    private ?string $videoDescription = null;
    private ?string $videoMetadata = null;

    public function getFile(): string
    {
        return $this->file;
    }

    public function setFile(string $file): MediaEntryInterface
    {
        $this->file = $file;
        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(string $label): MediaEntryInterface
    {
        $this->label = $label;
        return $this;
    }

    public function getPosition(): ?int
    {
        return $this->position;
    }

    public function setPosition(int $position): MediaEntryInterface
    {
        $this->position = $position;
        return $this;
    }

    public function getDisabled(): ?bool
    {
        return $this->disabled;
    }

    public function setDisabled(bool $disabled): MediaEntryInterface
    {
        $this->disabled = $disabled;
        return $this;
    }

    public function getRoles(): ?array
    {
        return $this->roles;
    }

    public function setRoles(array $roles): MediaEntryInterface
    {
        $this->roles = $roles;
        return $this;
    }

    public function getMediaType(): ?string
    {
        return $this->mediaType;
    }

    public function setMediaType(string $mediaType): MediaEntryInterface
    {
        $this->mediaType = $mediaType;
        return $this;
    }

    public function getVideoProvider(): ?string
    {
        return $this->videoProvider;
    }

    public function setVideoProvider(string $videoProvider): MediaEntryInterface
    {
        $this->videoProvider = $videoProvider;
        return $this;
    }

    public function getVideoUrl(): ?string
    {
        return $this->videoUrl;
    }

    public function setVideoUrl(string $videoUrl): MediaEntryInterface
    {
        $this->videoUrl = $videoUrl;
        return $this;
    }

    public function getVideoTitle(): ?string
    {
        return $this->videoTitle;
    }

    public function setVideoTitle(string $videoTitle): MediaEntryInterface
    {
        $this->videoTitle = $videoTitle;
        return $this;
    }

    public function getVideoDescription(): ?string
    {
        return $this->videoDescription;
    }

    public function setVideoDescription(string $videoDescription): MediaEntryInterface
    {
        $this->videoDescription = $videoDescription;
        return $this;
    }

    public function getVideoMetadata(): ?string
    {
        return $this->videoMetadata;
    }

    public function setVideoMetadata(string $videoMetadata): MediaEntryInterface
    {
        $this->videoMetadata = $videoMetadata;
        return $this;
    }
}
