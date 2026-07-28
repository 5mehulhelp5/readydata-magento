<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Api\Data;

/**
 * Per-option brand/landing data for one attribute option value, as authored by
 * the calling application. Targets Amasty Shop by Brand's option-setting store
 * (e.g. amasty_amshopby_option_setting).
 *
 * The option is identified by its admin-scope LABEL (`option`); the module
 * resolves it to the concrete option_id. All persisted values are friendly and
 * optional — the module maps them to whatever columns the installed Amasty
 * version actually exposes and silently drops the rest. `store_id` defaults to 0
 * (admin/all-store) when omitted.
 *
 * @api
 */
interface AmastyOptionSettingInterface
{
    public const OPTION = 'option';
    public const STORE_ID = 'store_id';
    public const TITLE = 'title';
    public const IMAGE = 'image';
    public const URL = 'url';
    public const DESCRIPTION = 'description';
    public const META_TITLE = 'meta_title';
    public const META_DESCRIPTION = 'meta_description';
    public const EXTRA = 'extra';

    /**
     * Admin-scope option label to attach the setting to.
     *
     * @return string
     */
    public function getOption(): string;

    /**
     * @param string $option
     * @return $this
     */
    public function setOption(string $option): self;

    /**
     * @return int|null
     */
    public function getStoreId(): ?int;

    /**
     * @param int|null $storeId
     * @return $this
     */
    public function setStoreId(?int $storeId): self;

    /**
     * @return string|null
     */
    public function getTitle(): ?string;

    /**
     * @param string|null $title
     * @return $this
     */
    public function setTitle(?string $title): self;

    /**
     * Brand logo/image path (relative to media), as stored by Amasty.
     *
     * @return string|null
     */
    public function getImage(): ?string;

    /**
     * @param string|null $image
     * @return $this
     */
    public function setImage(?string $image): self;

    /**
     * Landing-page URL alias/key.
     *
     * @return string|null
     */
    public function getUrl(): ?string;

    /**
     * @param string|null $url
     * @return $this
     */
    public function setUrl(?string $url): self;

    /**
     * @return string|null
     */
    public function getDescription(): ?string;

    /**
     * @param string|null $description
     * @return $this
     */
    public function setDescription(?string $description): self;

    /**
     * @return string|null
     */
    public function getMetaTitle(): ?string;

    /**
     * @param string|null $metaTitle
     * @return $this
     */
    public function setMetaTitle(?string $metaTitle): self;

    /**
     * @return string|null
     */
    public function getMetaDescription(): ?string;

    /**
     * @param string|null $metaDescription
     * @return $this
     */
    public function setMetaDescription(?string $metaDescription): self;

    /**
     * Version-specific extra values, already keyed by real Amasty column name.
     * Merged over the friendly fields and intersected with the live table.
     *
     * @return array<string, string|int>|null
     */
    public function getExtra(): ?array;

    /**
     * @param array<string, string|int>|null $extra
     * @return $this
     */
    public function setExtra(?array $extra): self;
}
