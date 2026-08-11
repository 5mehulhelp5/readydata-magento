<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model;

/**
 * The named locks this module takes, in one place because three endpoints share
 * them and every one of them shares the timeouts.
 *
 * Each lock guards one **unkeyed read-then-create**: look for a row, not find
 * it, insert it — where the database has no unique key to catch a second
 * request doing the same thing at the same moment. They are separate names
 * rather than one because the payloads that reach them are largely disjoint: a
 * media-only feed and a category sync have no race with each other, and one
 * lock name would serialize them anyway.
 *
 * A lock is held from **before the miss-read until the COMMIT that makes the
 * insert visible** — releasing earlier would let the next holder read a state
 * that does not yet contain our row and insert a duplicate, which is the race
 * itself. In the product pipeline that means the batch transaction: acquired
 * once the batch's file downloads are done, released once it has committed or
 * rolled back. See {@see ImportService::batchLocks()}.
 *
 * Requests take a SET of these, always {@see inAcquisitionOrder()} — a fixed
 * global order is what keeps two requests wanting overlapping sets from
 * deadlocking on each other.
 */
final class ImportLocks
{
    /**
     * Attribute **definitions**. Held by the whole attribute sync endpoint:
     * definitions are read-then-created the same way, and a half-applied
     * definition (attribute created but unplaced, options half-seeded) is worse
     * than a rejected one.
     */
    public const ATTRIBUTE_SYNC = 'readydata_attribute_sync';

    /**
     * Attribute **options**. With option auto-creation on, AttributeProcessor
     * creates missing select/multiselect options. `eav_attribute_option` has no
     * key on the label at all — the label lives in `eav_attribute_option_value`
     * — so two concurrent runs writing a product with the same new option label
     * both miss and both insert, and the attribute ends up with two options of
     * the same name.
     *
     * Shared with the attribute sync endpoint, which seeds options too. That
     * closes a race this module documented rather than fixed for as long as the
     * two endpoints held one lock each: the cost is that an attribute sync now
     * blocks a product import carrying custom attributes, which is a wait
     * instead of a duplicated option.
     */
    public const ATTRIBUTE_OPTIONS = 'readydata_attribute_options';

    /**
     * Product rows. `catalog_product_entity.sku` carries a plain index, NOT a
     * unique key — Magento enforces SKU uniqueness in PHP. EntityProcessor reads
     * by SKU, misses, and inserts, so two concurrent runs naming the same new
     * SKU both insert and the catalog ends up with two rows for one SKU, each
     * with its own EAV, gallery and stock satellites.
     */
    public const PRODUCT_CREATE = 'readydata_product_create';

    /**
     * The category tree. The product import creates categories on demand (see
     * CategoryPathResolver) and so does the category sync endpoint. Nothing is
     * unique on (parent_id, name) or on a category url_key, so two concurrent
     * runs both miss and both insert — leaving a duplicate sibling, which makes
     * that path permanently ambiguous, or a url_rewrite unique-key violation
     * that fails whichever request loses.
     *
     * The VALUE is deliberately the one this module has always used, from when a
     * single lock guarded only the tree. Keeping it means that during a deploy,
     * where a request already running the previous code can overlap a request
     * running this one, the two still serialize on the race whose damage is
     * least recoverable.
     */
    public const CATEGORY_TREE = 'readydata_product_import';

    /**
     * Media gallery rows. `catalog_product_entity_media_gallery` has no key on
     * (attribute_id, value) and its value_id is an autoincrement, which is why a
     * fresh row cannot be re-selected by its own data at all (see
     * ProductMediaGallery). Two concurrent runs carrying the same file for one
     * product both miss and both insert, and the image is listed twice.
     */
    public const MEDIA_GALLERY = 'readydata_media_gallery';

    /**
     * The order every holder acquires in. Two requests that want overlapping
     * sets would otherwise be able to take them in opposite orders and wait on
     * each other until both time out.
     *
     * The order itself is the pipeline's: options before product rows before the
     * tree before the gallery, which is also the order a reader meets them in.
     */
    private const ORDER = [
        self::ATTRIBUTE_SYNC,
        self::ATTRIBUTE_OPTIONS,
        self::PRODUCT_CREATE,
        self::CATEGORY_TREE,
        self::MEDIA_GALLERY,
    ];

    /**
     * How long a request waits for a lock before giving up, when it has
     * committed nothing yet. Short on purpose: a caller that is told to try
     * again keeps its own retry policy, while a request queueing behind a long
     * import holds a PHP worker for nothing.
     */
    public const TIMEOUT_SEC = 10;

    /**
     * How long a request waits once it has already committed part of its work.
     * Longer, and deliberately so: abandoning batch 4 of 10 leaves the caller
     * reconciling a partial import, which is worth more than the worker-seconds
     * spent waiting for a lock whose holder is — by construction, since holds
     * are now one batch transaction long — about to release it.
     */
    public const CONTINUATION_TIMEOUT_SEC = 30;

    /**
     * Sort a set of lock names into {@see ORDER}, dropping anything unknown.
     *
     * @param string[] $locks
     * @return string[]
     */
    public static function inAcquisitionOrder(array $locks): array
    {
        return array_values(array_filter(
            self::ORDER,
            static fn (string $lock): bool => in_array($lock, $locks, true)
        ));
    }
}
