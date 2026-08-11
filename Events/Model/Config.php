<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;

/**
 * Typed accessor for the module system configuration.
 */
class Config
{
    public const DEFAULT_BUFFER_SIZE = 500;
    public const DEFAULT_MAX_QUEUE_DEPTH = 100000;
    public const DEFAULT_DELIVERY_BATCH_SIZE = 100;
    public const DEFAULT_MAX_RETRIES = 7;
    public const DEFAULT_TIMEOUT = 15;
    public const DEFAULT_BACKOFF_SECONDS = 60;
    public const DEFAULT_RETENTION_DAYS = 3;

    private const XML_PATH_ENABLED = 'readydata_events/general/enabled';
    private const XML_PATH_INSTANCE_ID = 'readydata_events/general/instance_id';
    private const XML_PATH_BUFFER_SIZE = 'readydata_events/capture/buffer_size';
    private const XML_PATH_MAX_QUEUE_DEPTH = 'readydata_events/capture/max_queue_depth';
    private const XML_PATH_DELIVERY_BATCH_SIZE = 'readydata_events/delivery/batch_size';
    private const XML_PATH_MAX_RETRIES = 'readydata_events/delivery/max_retries';
    private const XML_PATH_TIMEOUT = 'readydata_events/delivery/timeout';
    private const XML_PATH_BACKOFF_SECONDS = 'readydata_events/delivery/backoff_seconds';
    private const XML_PATH_RETENTION_DAYS = 'readydata_events/retention/days';
    private const XML_PATH_LOGGING_ENABLED = 'readydata_events/logging/enabled';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly DeploymentConfig $deploymentConfig
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED);
    }

    /**
     * Identifies this store in the CloudEvents envelope's `source`.
     *
     * Falls back to the installation id rather than an empty string: an
     * unlabelled event stream is indistinguishable from another store's, and
     * ReadyData keys deliveries by instance.
     */
    public function getInstanceId(): string
    {
        $configured = trim((string)$this->scopeConfig->getValue(self::XML_PATH_INSTANCE_ID));
        if ($configured !== '') {
            return $configured;
        }

        return (string)$this->deploymentConfig->get('install/date', 'magento');
    }

    public function getBufferSize(): int
    {
        return $this->positiveInt(self::XML_PATH_BUFFER_SIZE, self::DEFAULT_BUFFER_SIZE);
    }

    public function getMaxQueueDepth(): int
    {
        return $this->positiveInt(self::XML_PATH_MAX_QUEUE_DEPTH, self::DEFAULT_MAX_QUEUE_DEPTH);
    }

    public function getDeliveryBatchSize(): int
    {
        return $this->positiveInt(self::XML_PATH_DELIVERY_BATCH_SIZE, self::DEFAULT_DELIVERY_BATCH_SIZE);
    }

    public function getMaxRetries(): int
    {
        return $this->positiveInt(self::XML_PATH_MAX_RETRIES, self::DEFAULT_MAX_RETRIES);
    }

    public function getTimeout(): int
    {
        return $this->positiveInt(self::XML_PATH_TIMEOUT, self::DEFAULT_TIMEOUT);
    }

    public function getBackoffSeconds(): int
    {
        return $this->positiveInt(self::XML_PATH_BACKOFF_SECONDS, self::DEFAULT_BACKOFF_SECONDS);
    }

    public function getRetentionDays(): int
    {
        return $this->positiveInt(self::XML_PATH_RETENTION_DAYS, self::DEFAULT_RETENTION_DAYS);
    }

    public function isLoggingEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_LOGGING_ENABLED);
    }

    /**
     * A misconfigured zero here would mean "buffer nothing" or "never retry",
     * both of which fail silently, so a non-positive value falls back.
     */
    private function positiveInt(string $path, int $default): int
    {
        $value = (int)$this->scopeConfig->getValue($path);

        return $value > 0 ? $value : $default;
    }
}
