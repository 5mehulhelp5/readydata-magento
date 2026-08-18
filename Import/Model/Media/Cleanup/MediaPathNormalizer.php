<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Media\Cleanup;

use Magento\Catalog\Model\Product\Media\Config as MediaConfig;

/**
 * The one place the filesystem's idea of a media path and the database's meet.
 *
 * Two conventions have to meet:
 *
 *  - the filesystem, via the media directory, yields "catalog/product/a/b/x.jpg";
 *  - catalog_product_entity_media_gallery.value and the image role attributes
 *    store "/a/b/x.jpg" — base path stripped, leading slash kept.
 *
 * Canonical is the database form, because that is what the join has to match and
 * converting the other way would mean rewriting two columns instead of one. So
 * the only conversion here is disk-to-database, and it is the single place a
 * mistake would make every referenced file look like an orphan.
 *
 * Pure: no I/O, no database, nothing to mock but the config.
 */
class MediaPathNormalizer
{
    /**
     * Both `value` columns this is compared against are varchar(255), and
     * Magento's connection runs with SQL_MODE='' — so an over-long path is not
     * rejected by MySQL, it is silently truncated. Truncated into the candidate
     * table's primary key it would both collide with its neighbours and fail to
     * match its own reference row, manufacturing orphans. Caught here instead.
     */
    public const MAX_PATH_LENGTH = 255;

    public function __construct(
        private readonly MediaConfig $mediaConfig
    ) {
    }

    /**
     * "catalog/product", without surrounding slashes.
     */
    public function basePath(): string
    {
        return trim($this->mediaConfig->getBaseMediaPath(), '/');
    }

    /**
     * "catalog/product/a/b/x.jpg" -> "/a/b/x.jpg".
     *
     * Returns null for anything that is not a file path under the product media
     * directory. Rejecting rather than passing through matters: a path outside
     * the base means the walk escaped the tree, and treating it as canonical
     * would compare an unrelated file against the gallery.
     *
     * @return string|null null when the path is outside the base path, is the
     *         base path itself, or is empty once normalised
     */
    public function fromMediaRelative(string $path): ?string
    {
        $path = $this->collapseSlashes(str_replace('\\', '/', trim($path)));
        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }

        $base = $this->basePath();
        $path = ltrim($path, '/');
        if ($path === $base) {
            return null;
        }
        // str_starts_with on the base PLUS its separator: "catalog/productX/y"
        // must not be accepted as being inside "catalog/product".
        if (!str_starts_with($path, $base . '/')) {
            return null;
        }

        $canonical = substr($path, strlen($base));

        return $canonical === '/' ? null : $canonical;
    }

    /**
     * Whether the canonical path is too long for the columns it is compared
     * against. See {@see MAX_PATH_LENGTH}: such a file cannot be referenced by
     * any gallery row, so it is a genuine finding rather than noise — but it
     * must be counted separately instead of entering the candidate table.
     */
    public function exceedsColumnLimit(string $canonical): bool
    {
        return strlen($canonical) > self::MAX_PATH_LENGTH;
    }

    private function collapseSlashes(string $path): string
    {
        return (string)preg_replace('#/+#', '/', $path);
    }
}
