<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Api\Data;

/**
 * Per-request import settings. All fields optional; system configuration
 * provides the defaults.
 *
 * @api
 */
interface ImportSettingsInterface
{
    public const STORE_VIEW_CODE = 'store_view_code';
    public const STORE_ID = 'store_id';
    public const CONTINUE_ON_ERROR = 'continue_on_error';
    public const BATCH_SIZE = 'batch_size';

    /**
     * Store view code for store-scoped attribute values. Defaults to the admin (global) scope.
     *
     * @return string|null
     */
    public function getStoreViewCode(): ?string;

    /**
     * @param string $storeViewCode
     * @return $this
     */
    public function setStoreViewCode(string $storeViewCode): self;

    /**
     * Store view ID for store-scoped attribute values, for callers that
     * already hold the ID and should not have to translate it back into a
     * code. Wins over store_view_code when both are given; 0 is the admin
     * (default) scope. An ID no store view has fails the request, exactly as
     * an unknown code does.
     *
     * @return int|null
     */
    public function getStoreId(): ?int;

    /**
     * @param int $storeId
     * @return $this
     */
    public function setStoreId(int $storeId): self;

    /**
     * @return bool|null
     */
    public function getContinueOnError(): ?bool;

    /**
     * @param bool $continueOnError
     * @return $this
     */
    public function setContinueOnError(bool $continueOnError): self;

    /**
     * Override the configured batch size for this request.
     *
     * @return int|null
     */
    public function getBatchSize(): ?int;

    /**
     * @param int $batchSize
     * @return $this
     */
    public function setBatchSize(int $batchSize): self;
}
