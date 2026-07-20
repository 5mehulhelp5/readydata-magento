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
}
