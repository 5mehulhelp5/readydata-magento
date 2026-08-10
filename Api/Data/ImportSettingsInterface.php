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
    public const ROOT_CATEGORY_ID = 'root_category_id';
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
     * @param int|null $storeId
     * @return $this
     */
    public function setStoreId(?int $storeId): self;

    /**
     * Pins the first segment of every category path in this request to one
     * root category, instead of letting the name pick it.
     *
     * Magento enforces no uniqueness on root names, and two roots sharing one
     * are two different catalogs. Without a pin a read resolves such a name to
     * the lowest entity ID — which silently lands the write in whichever tree
     * happens to have been created first — and a write on the category
     * endpoint refuses outright with `ambiguous_path`. Naming the root here
     * resolves both, and it is the only way to disambiguate on a first run,
     * before any `category_id` is known.
     *
     * A path whose first segment does not name the pinned root is refused
     * rather than reparented: the two statements contradict each other, and
     * guessing which one the caller meant is how a subtree ends up in the wrong
     * catalog.
     *
     * @return int|null
     */
    public function getRootCategoryId(): ?int;

    /**
     * @param int|null $rootCategoryId
     * @return $this
     */
    public function setRootCategoryId(?int $rootCategoryId): self;

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
