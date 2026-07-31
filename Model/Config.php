<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Typed accessor for the module system configuration.
 */
class Config
{
    public const DEFAULT_BATCH_SIZE = 500;

    public const INDEXING_MODE_NONE = 'none';
    public const INDEXING_MODE_INVALIDATE = 'invalidate';
    public const INDEXING_MODE_PARTIAL = 'partial';

    public const DEFAULT_MEDIA_DOWNLOAD_TIMEOUT = 15;
    public const DEFAULT_MEDIA_MAX_FILE_SIZE_KB = 10240;
    public const DEFAULT_MEDIA_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    public const URL_CONFLICT_ERROR = 'error';
    public const URL_CONFLICT_APPEND = 'append';
    public const URL_CONFLICT_SKIP = 'skip';

    private const XML_PATH_ENABLED = 'readydata_import/general/enabled';
    private const XML_PATH_BATCH_SIZE = 'readydata_import/general/batch_size';
    private const XML_PATH_CONTINUE_ON_ERROR = 'readydata_import/general/continue_on_error';
    private const XML_PATH_CREATE_MISSING_OPTIONS = 'readydata_import/behavior/create_missing_options';
    private const XML_PATH_URL_REWRITE_CONFLICT = 'readydata_import/behavior/url_rewrite_conflict';
    private const XML_PATH_INDEXING_MODE = 'readydata_import/indexing/mode';
    private const XML_PATH_CLEAN_CACHE = 'readydata_import/indexing/clean_cache';
    private const XML_PATH_LOGGING_ENABLED = 'readydata_import/logging/enabled';
    private const XML_PATH_DISPATCH_PRODUCT_EVENTS = 'readydata_import/events/dispatch_product_events';
    private const XML_PATH_DISPATCH_SAVE_AFTER = 'readydata_import/events/dispatch_save_after';
    private const XML_PATH_AUTO_CREATE_ATTRIBUTES = 'readydata_import/attributes/auto_create';
    private const XML_PATH_MEDIA_ENABLED = 'readydata_import/media/enabled';
    private const XML_PATH_MEDIA_DOWNLOAD_TIMEOUT = 'readydata_import/media/download_timeout';
    private const XML_PATH_MEDIA_MAX_FILE_SIZE_KB = 'readydata_import/media/max_file_size_kb';
    private const XML_PATH_MEDIA_ALLOWED_EXTENSIONS = 'readydata_import/media/allowed_extensions';
    private const XML_PATH_MEDIA_ALLOWED_HOSTS = 'readydata_import/media/allowed_hosts';
    private const XML_PATH_MEDIA_REDOWNLOAD_EXISTING = 'readydata_import/media/redownload_existing';
    private const XML_PATH_MEDIA_AUTO_ASSIGN_ROLES = 'readydata_import/media/auto_assign_roles';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED);
    }

    public function getBatchSize(): int
    {
        $size = (int)$this->scopeConfig->getValue(self::XML_PATH_BATCH_SIZE);

        return $size > 0 ? $size : self::DEFAULT_BATCH_SIZE;
    }

    public function isContinueOnError(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_CONTINUE_ON_ERROR);
    }

    public function isCreateMissingOptions(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_CREATE_MISSING_OPTIONS);
    }

    public function getUrlRewriteConflictStrategy(): string
    {
        return (string)($this->scopeConfig->getValue(self::XML_PATH_URL_REWRITE_CONFLICT)
            ?: self::URL_CONFLICT_APPEND);
    }

    public function getIndexingMode(): string
    {
        return (string)($this->scopeConfig->getValue(self::XML_PATH_INDEXING_MODE)
            ?: self::INDEXING_MODE_PARTIAL);
    }

    public function isCleanCache(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_CLEAN_CACHE);
    }

    public function isLoggingEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_LOGGING_ENABLED);
    }

    /**
     * Master switch for re-emitting product lifecycle events (commit_after +
     * the custom import events) after each committed batch.
     */
    public function isDispatchProductEvents(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_DISPATCH_PRODUCT_EVENTS);
    }

    /**
     * Whether to additionally fire the in-transaction catalog_product_save_after
     * event per product. Gated by {@see isDispatchProductEvents()} at the call
     * site; heavier than the commit-after events and a throwing observer rolls
     * the batch back.
     */
    public function isDispatchSaveAfter(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_DISPATCH_SAVE_AFTER);
    }

    /**
     * Master switch for the attribute-definition sync endpoint. Off by default:
     * creating/updating attribute definitions is a catalog-structure change and
     * must be opted into.
     */
    public function isAutoCreateAttributes(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_AUTO_CREATE_ATTRIBUTES);
    }

    /**
     * Master switch for the media gallery step. It also gates the pre-transaction
     * phase, so a disabled media importer downloads nothing.
     */
    public function isMediaEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_MEDIA_ENABLED);
    }

    public function getMediaDownloadTimeout(): int
    {
        $timeout = (int)$this->scopeConfig->getValue(self::XML_PATH_MEDIA_DOWNLOAD_TIMEOUT);

        return $timeout > 0 ? $timeout : self::DEFAULT_MEDIA_DOWNLOAD_TIMEOUT;
    }

    public function getMediaMaxFileSizeKb(): int
    {
        $size = (int)$this->scopeConfig->getValue(self::XML_PATH_MEDIA_MAX_FILE_SIZE_KB);

        return $size > 0 ? $size : self::DEFAULT_MEDIA_MAX_FILE_SIZE_KB;
    }

    /**
     * @return string[] lower-cased, without leading dots
     */
    public function getMediaAllowedExtensions(): array
    {
        $extensions = $this->splitList(self::XML_PATH_MEDIA_ALLOWED_EXTENSIONS);
        $extensions = array_map(static fn (string $ext): string => ltrim($ext, '.'), $extensions);

        return array_values(array_filter($extensions)) ?: self::DEFAULT_MEDIA_EXTENSIONS;
    }

    /**
     * Hosts image URLs may be downloaded from. An EMPTY list means any host —
     * see the field comment in system.xml.
     *
     * @return string[]
     */
    public function getMediaAllowedHosts(): array
    {
        return $this->splitList(self::XML_PATH_MEDIA_ALLOWED_HOSTS);
    }

    /**
     * Whether a URL is fetched again when its target file already exists. Off by
     * default: skipping makes a re-import cost no network I/O at all, and makes
     * a retry after a rolled-back batch converge on the same file.
     */
    public function isMediaRedownloadExisting(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_MEDIA_REDOWNLOAD_EXISTING);
    }

    public function isMediaAutoAssignRoles(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_MEDIA_AUTO_ASSIGN_ROLES);
    }

    /**
     * @return string[] trimmed, lower-cased, non-empty entries of a CSV setting
     */
    private function splitList(string $path): array
    {
        $raw = (string)$this->scopeConfig->getValue($path);
        $items = array_map(
            static fn (string $item): string => mb_strtolower(trim($item)),
            explode(',', $raw)
        );

        return array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
    }
}
