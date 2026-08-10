<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model;

/**
 * The named locks this module takes, in one place because two endpoints share
 * one of them and every one of them shares the timeout.
 */
final class ImportLocks
{
    /**
     * Held by every request that can perform an **unkeyed read-then-create**:
     * look for a row, not find it, insert it — where the database has no unique
     * key to catch a second request doing the same thing at the same time.
     *
     * There are four such sequences, and the lock exists for all of them:
     *
     * - **product rows.** `catalog_product_entity.sku` carries a plain index,
     *   NOT a unique key — Magento enforces SKU uniqueness in PHP. EntityProcessor
     *   reads by SKU, misses, and inserts, so two concurrent runs naming the same
     *   new SKU both insert and the catalog ends up with two rows for one SKU,
     *   each with its own EAV, gallery and stock satellites.
     * - **categories.** The product import creates them on demand (see
     *   CategoryPathResolver) and so does the category sync endpoint. Nothing
     *   is unique on (parent_id, name) or on a category url_key, so two
     *   concurrent runs both miss and both insert — leaving a duplicate sibling,
     *   or a url_rewrite unique-key violation that fails whichever request
     *   loses.
     * - **media gallery rows.** `catalog_product_entity_media_gallery` has no key
     *   on (attribute_id, value) and its value_id is an autoincrement, which is
     *   why a fresh row cannot be re-selected by its own data at all (see
     *   ProductMediaGallery). Two concurrent runs carrying the same file for one
     *   product both miss and both insert, and the image is listed twice.
     * - **attribute options.** With option auto-creation on, AttributeProcessor
     *   creates missing select/multiselect options. `eav_attribute_option` has no
     *   key on the label at all, so two concurrent runs writing a product with
     *   the same new option label both miss and both insert, and the attribute
     *   ends up with two options of the same name.
     *
     * A request that can reach none of them does not take the lock at all — see
     * {@see ImportService::needsWriteLock()}. The name is deliberately generic:
     * it is one lock for every race above, and the value is unchanged from when
     * it guarded only the tree.
     *
     * Not covered: the attribute endpoint seeds options too, under
     * {@see ATTRIBUTE_SYNC}, so a product import running concurrently with an
     * attribute sync can still duplicate one option label. Sharing one lock
     * would serialize two endpoints that otherwise have nothing to do with each
     * other, so the race is documented rather than closed.
     */
    public const PRODUCT_IMPORT = 'readydata_product_import';

    /**
     * Held by the whole attribute sync endpoint. Attribute definitions are
     * read-then-created the same way, and a half-applied definition (attribute
     * created but unplaced, options half-seeded) is worse than a rejected one.
     */
    public const ATTRIBUTE_SYNC = 'readydata_attribute_sync';

    /**
     * How long a request waits for a lock before giving up. Short on purpose: a
     * caller that is told to try again keeps its own retry policy, while a
     * request queueing behind a long import holds a PHP worker for nothing.
     */
    public const TIMEOUT_SEC = 10;
}
