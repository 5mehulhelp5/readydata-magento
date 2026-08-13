<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Event;

use Magento\Catalog\Model\Product;
use ReadyData\Import\Logger\Logger;
use ReadyData\Import\Model\Cache\AttributeMetadataCache;
use ReadyData\Import\Model\Processor\MediaProcessor;
use ReadyData\Import\Model\ResourceModel\EavValue;
use ReadyData\Import\Model\ResourceModel\ProductEntity;
use ReadyData\Import\Model\ResourceModel\ProductMediaGallery;

/**
 * Gives the lightweight products {@see ImportEventDispatcher} builds the media
 * data a really-loaded product carries, so third-party product-save observers
 * (image optimisers, CDN sync, search indexers) see a gallery instead of a
 * product that looks like it has no images at all.
 *
 * Two bulk queries for the whole batch, whatever its size. The gallery is read
 * from the database rather than assembled from the payload, so products whose
 * payload carried no `media` block are covered too.
 *
 * Only `media_gallery` and the image role attributes are set. That is enough:
 * `Product::getMediaGalleryEntries()` converts `media_gallery['images']` on
 * demand and derives each entry's `types` from the role values, and the video
 * converter builds the `video_content` extension attribute from the `video_*`
 * row keys — so building entry DTOs here would be per-product object churn for
 * no gain.
 *
 * @see \Magento\Catalog\Model\Product\Gallery\ReadHandler::addMediaDataToProduct() the shape mirrored here
 * @see \Magento\Catalog\Model\ResourceModel\Product\Gallery::createBatchBaseSelect() where core's row keys come from
 */
class ProductMediaHydrator
{
    private const MEDIA_GALLERY_CODE = MediaProcessor::MEDIA_GALLERY_CODE;

    private const ROLE_CODES = MediaProcessor::ROLE_CODES;

    /**
     * Free: they ride the same varchar read as the roles. Worth carrying so an
     * observer that saves the product does not read the object as "the merchant
     * cleared every image label".
     */
    private const ROLE_LABEL_CODES = ['image_label', 'small_image_label', 'thumbnail_label'];

    public function __construct(
        private readonly ProductMediaGallery $productMediaGallery,
        private readonly EavValue $eavValue,
        private readonly ProductEntity $productEntity,
        private readonly AttributeMetadataCache $attributeMetadataCache,
        private readonly Logger $logger
    ) {
    }

    /**
     * Never throws, on either call path. The dispatcher builds its products from
     * dispatchBeforeCommit() — inside the still-open batch transaction, when
     * "Also Dispatch catalog_product_save_after" is on — and from
     * dispatchAfterCommit(), once the batch has committed. Both run inside
     * ImportService::processBatch()'s try block, so an escaping exception would
     * either roll back a batch over a courtesy payload or, after the commit,
     * trigger a rollBack() of a transaction that is already gone and fail
     * products that are in fact persisted. Neither is worth a gallery.
     *
     * @param Product[] $productsByLinkId link field value => product
     */
    public function hydrate(array $productsByLinkId, int $storeId): void
    {
        if (!$productsByLinkId) {
            return;
        }

        try {
            $this->read($productsByLinkId, $storeId);
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf('Media hydration for the dispatched product events failed: %s', $e->getMessage()),
                ['exception' => $e]
            );
        }
    }

    /**
     * @param Product[] $productsByLinkId
     */
    private function read(array $productsByLinkId, int $storeId): void
    {
        $this->attributeMetadataCache->warm(
            array_merge([self::MEDIA_GALLERY_CODE], self::ROLE_CODES, self::ROLE_LABEL_CODES)
        );
        $galleryMeta = $this->attributeMetadataCache->get(self::MEDIA_GALLERY_CODE);
        if ($galleryMeta === null) {
            // MediaProcessor reports this per product already; here it only
            // means the dispatched objects stay as they were.
            $this->logger->error(
                'The media_gallery product attribute does not exist; dispatched products carry no gallery.'
            );

            return;
        }

        $linkIds = array_map('intval', array_keys($productsByLinkId));
        $linkField = $this->productEntity->getLinkField();
        $gallery = $this->productMediaGallery->getGallery($linkIds, $galleryMeta['attribute_id']);

        $codeByAttributeId = [];
        foreach (array_merge(self::ROLE_CODES, self::ROLE_LABEL_CODES) as $code) {
            $meta = $this->attributeMetadataCache->get($code);
            if ($meta !== null) {
                $codeByAttributeId[$meta['attribute_id']] = $code;
            }
        }
        $stored = $codeByAttributeId
            ? $this->eavValue->getValuesForStores('varchar', array_keys($codeByAttributeId), $linkIds)
            : [];

        foreach ($productsByLinkId as $linkId => $product) {
            $linkId = (int)$linkId;
            $this->applyGallery($product, $linkId, $linkField, $gallery[$linkId] ?? []);
            $this->applyRoles($product, $stored[$linkId] ?? [], $codeByAttributeId, $storeId);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows as returned by ProductMediaGallery::getGallery()
     */
    private function applyGallery(Product $product, int $linkId, string $linkField, array $rows): void
    {
        // Set unconditionally, exactly as core's read handler does: an empty
        // images array is what makes getMediaGalleryEntries() return [] rather
        // than null.
        $product->setData(self::MEDIA_GALLERY_CODE, [
            'images' => $this->buildImages($rows, $linkId, $linkField),
            'values' => [],
        ]);

        // After setData(), which is a no-op on a locked key. Locking makes
        // Gallery\CreateHandler bail before processDeletedImages()/
        // processNewAndExistingImages(), so an observer that saves the product
        // cannot create the store-scoped gallery_value rows this module
        // deliberately never writes. An observer that means to rewrite the
        // gallery has to unlockAttribute('media_gallery') first.
        $product->lockAttribute(self::MEDIA_GALLERY_CODE);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>> core-shaped rows keyed by value_id, position-sorted
     */
    private function buildImages(array $rows, int $linkId, string $linkField): array
    {
        $images = [];
        foreach ($rows as $row) {
            // Core's select carries a hard "gallery.disabled = 0", so such a row
            // is invisible to a loaded product. A NULL/empty path is legacy junk
            // that resolves to nothing but the media directory itself.
            if ($row['gallery_disabled'] !== 0 || $row['file'] === '') {
                continue;
            }

            $image = [
                'value_id' => $row['value_id'],
                'file' => $row['file'],
                'media_type' => $row['media_type'],
                $linkField => $linkId,
                'label' => $row['label'],
                'position' => $row['position'],
                // The per-entry flag from the value row, not the gallery row's.
                'disabled' => $row['value_disabled'],
                // The importer writes media at the default scope only, so the
                // effective values and their defaults are the same values.
                'label_default' => $row['label'],
                'position_default' => $row['position'],
                'disabled_default' => $row['value_disabled'],
            ];

            if ($row['media_type'] === ProductMediaGallery::MEDIA_TYPE_EXTERNAL_VIDEO && $row['video'] !== null) {
                $image['video_provider'] = $row['video']['provider'];
                $image['video_url'] = $row['video']['url'];
                $image['video_title'] = $row['video']['title'];
                $image['video_description'] = $row['video']['description'];
                $image['video_metadata'] = $row['video']['metadata'];
            }

            $images[] = $image;
        }

        return array_column($this->sortByPosition($images), null, 'value_id');
    }

    /**
     * Ascending position, NULL positions last — as core sorts before keying by
     * value_id. Rows arrive lowest value_id first, so ties stay deterministic.
     *
     * @param array<int, array<string, mixed>> $images
     * @return array<int, array<string, mixed>>
     * @see \Magento\Catalog\Model\Product\Gallery\ReadHandler::sortMediaEntriesByPosition()
     */
    private function sortByPosition(array $images): array
    {
        $withoutPosition = [];
        foreach ($images as $index => $image) {
            if ($image['position'] === null) {
                $withoutPosition[] = $image;
                unset($images[$index]);
            }
        }
        usort($images, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);

        return array_merge($images, $withoutPosition);
    }

    /**
     * The roles are the one part of the payload the pipeline may have overruled:
     * MediaProcessor writes them last and repoints every scope that already had
     * a value, so the stored value is the authoritative one. A role with no row
     * at all is left as the payload sent it — dropping data the caller supplied
     * would be the worse surprise.
     *
     * @param array<int, array<int, string>> $storedByAttribute attribute_id => store_id => value
     * @param array<int, string> $codeByAttributeId
     */
    private function applyRoles(
        Product $product,
        array $storedByAttribute,
        array $codeByAttributeId,
        int $storeId
    ): void {
        foreach ($storedByAttribute as $attributeId => $scopes) {
            $code = $codeByAttributeId[$attributeId] ?? null;
            if ($code === null) {
                continue;
            }
            // Core's EAV fallback: the store's own row, else the default one.
            $value = $scopes[$storeId] ?? $scopes[0] ?? null;
            if ($value !== null) {
                $product->setData($code, $value);
            }
        }
    }
}
