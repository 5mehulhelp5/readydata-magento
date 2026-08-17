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
    public const DEFAULT_MEDIA_DOWNLOAD_CONCURRENCY = 4;
    public const MAX_MEDIA_DOWNLOAD_CONCURRENCY = 32;
    public const DEFAULT_MEDIA_MAX_FILE_SIZE_KB = 10240;
    public const DEFAULT_MEDIA_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    public const URL_CONFLICT_ERROR = 'error';
    public const URL_CONFLICT_APPEND = 'append';
    public const URL_CONFLICT_SKIP = 'skip';

    /** A product's `categories` field replaces its links across the whole catalog. */
    public const REPLACE_SCOPE_ALL_ROOTS = 'all_roots';
    /** It replaces only within the root categories its own entries resolve into. */
    public const REPLACE_SCOPE_PAYLOAD_ROOTS = 'payload_roots';

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
    private const XML_PATH_HYDRATE_MEDIA = 'readydata_import/events/hydrate_media';
    private const XML_PATH_AUTO_CREATE_ATTRIBUTES = 'readydata_import/attributes/auto_create';
    private const XML_PATH_CATEGORIES_ENABLED = 'readydata_import/categories/enabled';
    private const XML_PATH_CATEGORIES_ALLOW_MOVE = 'readydata_import/categories/allow_move';
    private const XML_PATH_CATEGORIES_ALLOW_DELETE = 'readydata_import/categories/allow_delete';
    private const XML_PATH_CATEGORIES_REPLACE_SCOPE = 'readydata_import/categories/replace_scope';
    private const XML_PATH_CATEGORIES_ALLOW_CROSS_ROOT_MOVE = 'readydata_import/categories/allow_cross_root_move';
    private const XML_PATH_MEDIA_ENABLED = 'readydata_import/media/enabled';
    private const XML_PATH_MEDIA_DOWNLOAD_TIMEOUT = 'readydata_import/media/download_timeout';
    private const XML_PATH_MEDIA_DOWNLOAD_CONCURRENCY = 'readydata_import/media/download_concurrency';
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
     * site.
     *
     * On by default: an observer written against core's save timing expects the
     * in-transaction event, and an importer that skips it changes that
     * contract silently. The cost is accepted deliberately — it is heavier than
     * the commit-after events, and running pre-commit means a throwing observer
     * rolls the batch back. Stores that would rather no third party be able to
     * fail an import turn it off.
     */
    public function isDispatchSaveAfter(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_DISPATCH_SAVE_AFTER);
    }

    /**
     * Whether the dispatched product objects carry their media gallery and image
     * roles, read back in bulk once per batch. Gated by
     * {@see isDispatchProductEvents()} at the call site, and deliberately
     * independent of {@see isMediaEnabled()}: the gallery in the database is the
     * product's gallery whether or not this import wrote it.
     *
     * On by default, for the same reason as {@see isDispatchSaveAfter()}: an
     * observer should see the product a normal save would hand it, and one that
     * silently carries no gallery is the harder bug to find. Costs two queries
     * per batch, which is what a store turns it off to save.
     */
    public function isHydrateEventMedia(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_HYDRATE_MEDIA);
    }

    /**
     * Master switch for the attribute-definition sync endpoint. On by default:
     * the endpoint is the supported way to provision the attributes a feed
     * references, and a feed whose pre-flight step silently no-ops fails later
     * and less legibly, when the product import skips every unknown code.
     *
     * It still creates and alters attribute definitions, which is a
     * catalog-structure change — a store where that structure is owned by
     * someone other than the feed turns this off, and the
     * ReadyData_Import::attributes ACL resource gates it independently. Note
     * this is NOT the same posture as {@see isCategorySyncEnabled()}, which
     * stays off.
     */
    public function isAutoCreateAttributes(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_AUTO_CREATE_ATTRIBUTES);
    }

    /**
     * Master switch for the category sync endpoint. Off by default: creating and
     * renaming categories reshapes the storefront's navigation and its URLs, so
     * it must be opted into.
     */
    public function isCategorySyncEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_CATEGORIES_ENABLED);
    }

    /**
     * Whether the category endpoint may reparent a category. Off by default and
     * separate from the endpoint's own switch: a move re-paths the whole
     * descendant subtree and rewrites every URL under it, which is a much larger
     * blast radius than creating or renaming a single node.
     */
    public function isCategoryMoveAllowed(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_CATEGORIES_ALLOW_MOVE);
    }

    /**
     * Whether the category endpoint may delete a category. Off by default: a
     * delete is recursive and irreversible, taking the descendant subtree, its
     * URL rewrites and its product assignments with it.
     */
    public function isCategoryDeleteAllowed(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_CATEGORIES_ALLOW_DELETE);
    }

    /**
     * Whether a move may cross from one root category's tree into another's.
     * Off by default and separate from {@see isCategoryMoveAllowed()}: an
     * ordinary move rearranges one catalog, while this one takes a category,
     * its whole subtree and their product assignments out of one storefront and
     * into another. The two roots are two different catalogs, and no amount of
     * "the payload said so" makes that a routine reparenting.
     */
    public function isCrossRootMoveAllowed(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_CATEGORIES_ALLOW_CROSS_ROOT_MOVE);
    }

    /**
     * How far a product payload's `categories` field reaches when it replaces
     * the product's assignments — the whole catalog, or only the root
     * categories the payload's own entries resolve into.
     *
     * Governs the PRODUCT endpoint, not the category one, and is deliberately
     * not gated on {@see isCategorySyncEnabled()}.
     *
     * `all_roots` stays the default because it is what the module has always
     * done, and because switching it would silently redefine what an existing
     * caller's `"categories": []` means. `payload_roots` is the setting for a
     * catalog with several root trees fed by several sources, where a replace
     * across the whole catalog deletes links the caller never knew about.
     */
    public function getCategoryReplaceScope(): string
    {
        $scope = (string)$this->scopeConfig->getValue(self::XML_PATH_CATEGORIES_REPLACE_SCOPE);

        return $scope === self::REPLACE_SCOPE_PAYLOAD_ROOTS
            ? self::REPLACE_SCOPE_PAYLOAD_ROOTS
            : self::REPLACE_SCOPE_ALL_ROOTS;
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

    /**
     * How many image downloads may be in flight at once. 1 is fully sequential.
     *
     * Clamped: this multiplies the transfer-time memory footprint (each in-flight
     * response holds up to 2 MB in memory before spilling to disk) and the load
     * put on the image origin, so an unbounded value would be a foot-gun.
     */
    public function getMediaDownloadConcurrency(): int
    {
        $concurrency = (int)$this->scopeConfig->getValue(self::XML_PATH_MEDIA_DOWNLOAD_CONCURRENCY);
        if ($concurrency < 1) {
            $concurrency = self::DEFAULT_MEDIA_DOWNLOAD_CONCURRENCY;
        }

        return min($concurrency, self::MAX_MEDIA_DOWNLOAD_CONCURRENCY);
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
