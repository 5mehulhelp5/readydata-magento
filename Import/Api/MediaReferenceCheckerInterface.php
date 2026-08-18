<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Api;

/**
 * Answers "does any product still reference this media file?" for consumers of
 * `readydata_import_product_media_changed`.
 *
 * The event's `removed_files` means *"detached from the products in this batch"*,
 * which is deliberately NOT "safe to delete": the batch cannot see products it did
 * not touch. This is the check that closes that gap, so an image CDN or optimiser
 * acting on the event does not delete a file another product still shows.
 *
 * WHAT IS COUNTED AS A REFERENCE
 *  - a gallery row bound to a product, i.e. one reachable through
 *    catalog_product_entity_media_gallery_value_to_entity;
 *  - a value of any configured image ROLE attribute (`image`, `small_image`,
 *    `thumbnail`, `swatch_image` by default) in ANY store scope, because a role
 *    can point at a file whose gallery row belongs to a different product.
 *
 * Note what the first bullet excludes. Core does not clean up after a product
 * delete — `Magento\Catalog\Model\Product\Gallery\DeleteHandler` exists but is not
 * wired into the entity manager's `delete` actions — so deleting a product leaves
 * its gallery row behind, unbound, still carrying the path. Core's own
 * `Gallery::countImageUses()` counts rows by path with no regard for binding and
 * therefore reports those dead rows as uses, which would keep every such file
 * un-collectable forever. Requiring the binding is the difference.
 *
 * WHAT IS NOT COUNTED, AND WHY THAT MATTERS
 * Only product references are visible here. A file can also be referenced by
 * CMS pages and blocks, `{{media url=...}}` in a product or category description,
 * a category image, a third-party module's own tables, or a hand-written template.
 * None of those are checked, so a file this reports as unreferenced is *not*
 * provably unused store-wide. Core's admin path has the same blind spot and
 * deletes anyway, but it does so for one image at a time on a human's decision,
 * whereas a batch can detach thousands at once — so weigh the blast radius before
 * wiring this to a delete.
 *
 * The answer is also a point-in-time read, taken outside any transaction: a
 * concurrent import can adopt a file moments after this reports it unreferenced,
 * by resolving it to the same deterministic path and skipping the download. A
 * caller that deletes on the strength of this can therefore leave a committed
 * gallery row pointing at a file that is gone.
 *
 * That window is seconds wide and repairs itself — the next import of that SKU
 * finds the path absent and downloads it again — so the mitigation is to accept
 * one product missing one image until its next feed run, not to invent a delay.
 * A caller for whom that is unacceptable needs its own quarantine, and should
 * know it is buying protection against something that already heals.
 *
 * Resized renditions under pub/media/catalog/product/cache are a separate
 * concern; deleting a source file leaves them behind (core purges them via
 * `Magento\Catalog\Model\Product\Image\RemoveDeletedImagesFromCache`).
 *
 * @api
 */
interface MediaReferenceCheckerInterface
{
    /**
     * Narrow a set of stored paths to those nothing references any more.
     *
     * Batched deliberately: this is meant to take a whole `removed_files` array
     * in one call rather than be looped over.
     *
     * @param string[] $files stored paths in the form the event reports them
     *        ("/a/b/file.jpg"); order and duplicates do not matter
     * @return string[] the subset with no remaining product reference, as a list
     */
    public function getUnreferenced(array $files): array;

    /**
     * Single-path convenience. Prefer {@see getUnreferenced()} for more than one
     * file — this issues the same queries for each call.
     */
    public function isReferenced(string $file): bool;
}
