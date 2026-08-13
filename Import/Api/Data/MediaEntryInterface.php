<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Api\Data;

/**
 * One entry of a product's media gallery, in display order.
 *
 * "file" is either an http(s) URL the module downloads into
 * pub/media/catalog/product (standard Magento dispersion, /a/b/abc.jpg) or a
 * path relative to pub/media/catalog/product for a file pushed out of band. The
 * two are told apart by the scheme, so one field covers both.
 *
 * An entry carrying "video_url" becomes an external-video entry; "file" is then
 * its preview image and is still required — Magento renders the gallery
 * thumbnail from the stored file path.
 *
 * Labels, positions and roles are written at the DEFAULT scope only (store 0);
 * the request's store_view_code does not affect media (see MediaProcessor).
 *
 * @api
 */
interface MediaEntryInterface
{
    public const FILE = 'file';
    public const LABEL = 'label';
    public const POSITION = 'position';
    public const DISABLED = 'disabled';
    public const ROLES = 'roles';
    public const MEDIA_TYPE = 'media_type';
    public const VIDEO_PROVIDER = 'video_provider';
    public const VIDEO_URL = 'video_url';
    public const VIDEO_TITLE = 'video_title';
    public const VIDEO_DESCRIPTION = 'video_description';
    public const VIDEO_METADATA = 'video_metadata';

    public const MEDIA_TYPE_IMAGE = 'image';
    public const MEDIA_TYPE_EXTERNAL_VIDEO = 'external-video';

    /**
     * An http(s) URL to download, or a path relative to
     * pub/media/catalog/product for an already-uploaded file.
     *
     * @return string
     */
    public function getFile(): string;

    /**
     * @param string $file
     * @return $this
     */
    public function setFile(string $file): self;

    /**
     * Alt text, written at the default scope only.
     *
     * @return string|null
     */
    public function getLabel(): ?string;

    /**
     * @param string $label
     * @return $this
     */
    public function setLabel(string $label): self;

    /**
     * Display order. Omitted positions follow the payload order (0-based,
     * gap-free over the entries that resolved).
     *
     * @return int|null
     */
    public function getPosition(): ?int;

    /**
     * @param int $position
     * @return $this
     */
    public function setPosition(int $position): self;

    /**
     * Hide the entry from the storefront gallery without removing the file.
     *
     * @return bool|null
     */
    public function getDisabled(): ?bool;

    /**
     * @param bool $disabled
     * @return $this
     */
    public function setDisabled(bool $disabled): self;

    /**
     * Role attributes this file fills: image, small_image, thumbnail,
     * swatch_image. A role claimed by more than one entry of the same product
     * keeps its first claim.
     *
     * @return string[]|null
     */
    public function getRoles(): ?array;

    /**
     * @param string[] $roles
     * @return $this
     */
    public function setRoles(array $roles): self;

    /**
     * "image" (default) or "external-video"; derived from video_url when
     * omitted.
     *
     * @return string|null
     */
    public function getMediaType(): ?string;

    /**
     * @param string $mediaType
     * @return $this
     */
    public function setMediaType(string $mediaType): self;

    /**
     * Video provider ("youtube", "vimeo"). Derived from the video URL host when
     * omitted.
     *
     * @return string|null
     */
    public function getVideoProvider(): ?string;

    /**
     * @param string $videoProvider
     * @return $this
     */
    public function setVideoProvider(string $videoProvider): self;

    /**
     * Watch URL of an external video. Its presence makes the entry a video.
     *
     * @return string|null
     */
    public function getVideoUrl(): ?string;

    /**
     * @param string $videoUrl
     * @return $this
     */
    public function setVideoUrl(string $videoUrl): self;

    /**
     * @return string|null
     */
    public function getVideoTitle(): ?string;

    /**
     * @param string $videoTitle
     * @return $this
     */
    public function setVideoTitle(string $videoTitle): self;

    /**
     * @return string|null
     */
    public function getVideoDescription(): ?string;

    /**
     * @param string $videoDescription
     * @return $this
     */
    public function setVideoDescription(string $videoDescription): self;

    /**
     * Opaque provider metadata blob, stored verbatim.
     *
     * @return string|null
     */
    public function getVideoMetadata(): ?string;

    /**
     * @param string $videoMetadata
     * @return $this
     */
    public function setVideoMetadata(string $videoMetadata): self;
}
