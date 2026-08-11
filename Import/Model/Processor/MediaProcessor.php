<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Processor;

use ReadyData\Import\Api\Data\MediaEntryInterface;
use ReadyData\Import\Logger\Logger;
use ReadyData\Import\Model\BatchContext;
use ReadyData\Import\Model\Cache\AttributeMetadataCache;
use ReadyData\Import\Model\Config;
use ReadyData\Import\Model\Media\FileResolver;
use ReadyData\Import\Model\ResourceModel\EavValue;
use ReadyData\Import\Model\ResourceModel\ProductEntity;
use ReadyData\Import\Model\ResourceModel\ProductMediaGallery;

/**
 * Product images and videos: the media gallery tables plus the image role
 * attributes.
 *
 * Semantics are REPLACE: a present "media" array makes the gallery exactly that
 * ordered set (an empty array removes every entry), while null/omitted leaves the
 * gallery untouched. Entries are matched against the STORED FILE PATH, so a
 * re-import of an unchanged gallery writes nothing at all and existing entries
 * keep their rows — and with them their video records and any per-store data the
 * admin added.
 *
 * File acquisition happens in prepare(), before the batch transaction is opened
 * (see PreparableInterface): downloads must never run while the batch holds write
 * locks. prepare() publishes its outcome on the context data bag; process() only
 * reads it, so all per-product reporting lives in one place.
 *
 * Safety valve (mirrors CategoryLinkProcessor): when any entry of a product fails
 * to resolve, that product is applied additively — inserts and metadata updates
 * happen, removals are withheld — so one bad URL cannot empty a valid gallery.
 * Unlike LinkProcessor the valve is per product, because a gallery is one ordered
 * set with no independent sub-dimension to isolate.
 *
 * Everything is written at the DEFAULT scope (store_id = 0): store_view_code does
 * not affect media, so media belongs on one store pass only. The one exception is
 * a role attribute that ALREADY has a store-scoped row — it is kept in sync so a
 * store view cannot go on pointing at a file this import removed — but no new
 * store-scoped row is ever created (core's rule in
 * Gallery\CreateHandler::getStoreIdForUpdate()).
 *
 * Deliberately NOT written: store-scoped labels and positions; the media_gallery
 * attribute's own EAV row (it is backend_type "static" / frontend_input "gallery"
 * — the gallery tables ARE its backend, and EavValueProcessor already skips
 * static attributes); the image_label/small_image_label/thumbnail_label
 * companions; and the main gallery row's own "disabled" flag, which is always
 * written as 0 because core hides such rows from the admin grid as well, leaving
 * no way to undo it. Per-entry "disabled" goes to the _value row.
 *
 * Publishes to the context data bag:
 *  - "media_resolved_files": array<string reference, array{file: string|null,
 *    message: string|null}> produced by prepare(), consumed by process().
 *  - "media_changes_by_sku": what this batch changed per product, consumed by
 *    ImportEventDispatcher for its media-changed event.
 *  - "media_retained_files": every file the batch leaves attached to a product
 *    it touched, so the dispatcher can tell a genuine detachment from a file
 *    that merely moved between products.
 */
class MediaProcessor implements ProcessorInterface, PreparableInterface
{
    public const CONTEXT_RESOLVED_FILES = 'media_resolved_files';

    /**
     * Per-SKU gallery delta, for SKUs whose gallery actually changed:
     * array<string sku, array{entity_id: int, created: string[], updated: string[],
     * removed: string[], roles: array<string, string|null>, partial: bool}>.
     * "created" are files whose gallery row this batch inserted, "updated" kept
     * files whose metadata or video record changed, "removed" files whose rows
     * were deleted, "roles" the role attributes this batch wrote (code => new
     * default-scope file, or null for a role cleared as stale). Products with a
     * media block that resolved to no change at all are absent, which is the
     * point: a re-import must not tell a CDN to reprocess everything.
     */
    public const CONTEXT_CHANGES = 'media_changes_by_sku';

    /**
     * Flat, deduplicated list of every file still attached to one of the
     * products this batch touched, once it is done. A file in one product's
     * "removed" and in here has only moved, and no consumer should treat it as
     * gone. Says nothing about products the batch did not touch.
     */
    public const CONTEXT_RETAINED_FILES = 'media_retained_files';

    public const MEDIA_GALLERY_CODE = 'media_gallery';

    /**
     * Role attributes in scope. All four are varchar / frontend_input
     * "media_image" and store-scoped.
     *
     * Public because ProductMediaHydrator reads the same set back for the
     * dispatched product events; two hand-synced copies drift.
     */
    public const ROLE_CODES = ['image', 'small_image', 'thumbnail', 'swatch_image'];

    /**
     * Roles pointed at the first enabled entry when the payload declares none.
     * swatch_image is excluded: it drives swatch rendering and merchants set it
     * deliberately.
     */
    private const AUTO_ASSIGN_ROLES = ['image', 'small_image', 'thumbnail'];

    /**
     * What the admin stores for a role a merchant cleared; read as "no role".
     */
    private const NO_SELECTION = 'no_selection';

    /**
     * Provider derived from the video URL host when the payload omits it. Core
     * only does this in JavaScript, so the server has to do its own.
     *
     * @var array<string, string[]>
     */
    private const VIDEO_PROVIDERS = [
        'youtube' => ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be'],
        'vimeo' => ['vimeo.com', 'www.vimeo.com', 'player.vimeo.com'],
    ];

    public function __construct(
        private readonly ProductMediaGallery $productMediaGallery,
        private readonly ProductEntity $productEntity,
        private readonly AttributeMetadataCache $attributeMetadataCache,
        private readonly EavValue $eavValue,
        private readonly FileResolver $fileResolver,
        private readonly Config $config,
        private readonly Logger $logger
    ) {
    }

    /**
     * Acquire every distinct file the batch references, outside the transaction.
     */
    public function prepare(BatchContext $context): void
    {
        $references = [];
        foreach ($context->getValidProducts() as $product) {
            foreach ($product->getMedia() ?? [] as $entry) {
                $reference = trim($entry->getFile());
                if ($reference !== '') {
                    $references[$reference] = true;
                }
            }
        }

        if (!$references) {
            return;
        }

        $context->set(self::CONTEXT_RESOLVED_FILES, $this->fileResolver->resolve(array_keys($references)));
    }

    public function process(BatchContext $context): void
    {
        $linkIds = $context->get(EntityProcessor::CONTEXT_LINK_IDS, []);

        // 1. Collect the products carrying a media block, with their link IDs.
        $sources = [];
        foreach ($context->getValidProducts() as $sku => $product) {
            $entries = $product->getMedia();
            if ($entries === null) {
                continue;
            }
            if (!isset($linkIds[$sku]) || $context->getEntityId($sku) === null) {
                // EntityProcessor should have resolved these; defensive skip.
                continue;
            }
            $sources[$sku] = ['link_id' => (int)$linkIds[$sku], 'entries' => $entries];
        }

        if (!$sources) {
            return;
        }

        // 2. Attribute metadata for the gallery and the role attributes.
        $this->attributeMetadataCache->warm(array_merge([self::MEDIA_GALLERY_CODE], self::ROLE_CODES));
        $galleryMeta = $this->attributeMetadataCache->get(self::MEDIA_GALLERY_CODE);
        if ($galleryMeta === null) {
            foreach (array_keys($sources) as $sku) {
                $context->addMessage($sku, 'The media_gallery attribute is missing; media was not imported.');
            }
            $this->logger->error('The media_gallery product attribute does not exist; media import skipped.');

            return;
        }
        $roleAttributeIds = $this->collectRoleAttributeIds();

        // 3. One bulk read of the products' current galleries.
        $current = $this->productMediaGallery->getGallery(
            array_column($sources, 'link_id'),
            $galleryMeta['attribute_id']
        );

        $resolved = $context->get(self::CONTEXT_RESOLVED_FILES, []);
        $toRemove = [];
        $toInsert = [];
        $insertMeta = [];
        $valueRows = [];
        $videoRows = [];
        $videoDeletes = [];
        $galleryStates = [];
        $rolePlans = [];
        $changes = [];

        // 4. Diff each product's desired set against its stored gallery.
        foreach ($sources as $sku => $source) {
            $linkId = $source['link_id'];
            [$desired, $partial] = $this->buildDesired($context, (string)$sku, $source['entries'], $resolved);

            $currentByFile = [];
            $unmatchable = [];
            foreach ($current[$linkId] ?? [] as $row) {
                // Rows come back lowest value_id first, so the first row per path
                // wins. A repeated or NULL path is legacy junk no payload entry
                // can ever claim, so it belongs in the removal set.
                if ($row['file'] === '' || isset($currentByFile[$row['file']])) {
                    $unmatchable[] = $row;
                    continue;
                }
                $currentByFile[$row['file']] = $row;
            }

            $keptFiles = [];
            $updatedFiles = [];
            foreach ($desired as $file => $entry) {
                $existing = $currentByFile[$file] ?? null;
                if ($existing === null) {
                    $key = $sku . "\0" . $file;
                    $toInsert[$key] = [
                        'attribute_id' => $galleryMeta['attribute_id'],
                        'value' => $file,
                        'media_type' => $entry['media_type'],
                        'disabled' => 0,
                    ];
                    $insertMeta[$key] = ['sku' => (string)$sku, 'link_id' => $linkId, 'entry' => $entry];
                    continue;
                }

                $keptFiles[$file] = $existing['value_id'];

                if (!$existing['has_value_row']
                    || $existing['label'] !== $entry['label']
                    || $existing['position'] !== $entry['position']
                    || $existing['value_disabled'] !== $entry['disabled']
                ) {
                    $valueRows[] = [
                        'value_id' => $existing['value_id'],
                        'link_id' => $linkId,
                        'label' => $entry['label'],
                        'position' => $entry['position'],
                        'disabled' => $entry['disabled'],
                    ];
                    $updatedFiles[$file] = true;
                }
                if ($existing['media_type'] !== $entry['media_type'] || $existing['gallery_disabled'] !== 0) {
                    $galleryStates[$entry['media_type'] . '|0'][] = $existing['value_id'];
                    $updatedFiles[$file] = true;
                }
                if ($this->planVideo($existing['value_id'], $entry, $existing['video'], $videoRows, $videoDeletes)) {
                    $updatedFiles[$file] = true;
                }
            }

            $goneByFile = array_diff_key($currentByFile, $keptFiles);
            $removable = array_merge(array_values($goneByFile), $unmatchable);
            if ($partial && $removable) {
                $context->addMessage(
                    $sku,
                    'Media gallery applied additively: some entries could not be resolved,'
                    . ' so no existing gallery entries were removed.'
                );
            } elseif ($removable) {
                foreach ($removable as $row) {
                    $toRemove[] = ['value_id' => $row['value_id'], 'link_id' => $linkId];
                }
            }

            // Legacy junk (NULL or repeated paths) is removed but not reported:
            // its path is either nothing or still held by the row that won.
            $removedFiles = $partial ? [] : array_keys($goneByFile);

            $rolePlans[$sku] = [
                'link_id' => $linkId,
                'desired' => $desired,
                'kept_files' => $keptFiles,
                'removed_files' => $removedFiles,
            ];
            $changes[$sku] = [
                'entity_id' => (int)$context->getEntityId($sku),
                // Filled in by step 7, once the inserts have their value_ids.
                'created' => [],
                'updated' => array_keys($updatedFiles),
                'removed' => $removedFiles,
                // Filled in by step 9, which is where roles are decided.
                'roles' => [],
                'partial' => $partial,
            ];
        }

        // 5. Removals first, so step 6's value_id watermark stays as tight as possible.
        $this->productMediaGallery->removeEntries($toRemove);

        // 6. Insert the new gallery rows and learn their generated value_ids. An
        //    untrusted read-back throws: the rows are already written and cannot
        //    be identified, so the batch must roll back rather than commit them
        //    unbound. See ProductMediaGallery::insertGalleryRows().
        $valueIds = $this->productMediaGallery->insertGalleryRows($toInsert);

        // 7. Bind the new rows, then write the per-entry data of new and existing
        //    entries together so each table is touched once per batch.
        $bindRows = [];
        foreach ($valueIds as $key => $valueId) {
            $meta = $insertMeta[$key];
            $entry = $meta['entry'];
            $bindRows[] = ['value_id' => $valueId, 'link_id' => $meta['link_id']];
            $valueRows[] = [
                'value_id' => $valueId,
                'link_id' => $meta['link_id'],
                'label' => $entry['label'],
                'position' => $entry['position'],
                'disabled' => $entry['disabled'],
            ];
            $this->planVideo($valueId, $entry, null, $videoRows, $videoDeletes);
            $rolePlans[$meta['sku']]['kept_files'][$entry['file']] = $valueId;
            $changes[$meta['sku']]['created'][] = $entry['file'];
        }

        $this->productMediaGallery->bindToEntities($bindRows);
        $this->productMediaGallery->saveValues($valueRows);
        $this->productMediaGallery->saveVideos($videoRows);
        $this->productMediaGallery->deleteVideos($videoDeletes);

        // 8. Normalise media_type/disabled on the rows that were kept.
        $this->productMediaGallery->updateGalleryRows($galleryStates);

        // 9. Role attributes. A role can move between two files that are both
        //    already in the gallery, so this is a dimension of its own: without
        //    it a base-image swap would be a change nothing reported.
        foreach ($this->writeRoles($context, $rolePlans, $roleAttributeIds) as $sku => $roles) {
            $changes[$sku]['roles'] = $roles;
        }

        // 10. Publish the delta for the media-changed event, dropping the
        //     products this batch left exactly as they were.
        $changes = array_filter(
            $changes,
            static fn (array $change): bool => $change['created']
                || $change['updated']
                || $change['removed']
                || $change['roles']
        );
        if ($changes) {
            $context->set(self::CONTEXT_CHANGES, $changes);
            // Step 7 folded the new entries into kept_files, so this is every
            // file the batch leaves attached, not just the pre-existing ones.
            // array_values() before the spread: $rolePlans is keyed by SKU, and
            // spreading string keys makes them named arguments.
            $context->set(self::CONTEXT_RETAINED_FILES, array_values(array_unique(array_merge(
                ...array_values(array_map(
                    static fn (array $plan): array => array_keys($plan['kept_files']),
                    $rolePlans
                ))
            ))));
        }
    }

    /**
     * Build a product's desired gallery: stored file path => entry data, in the
     * order the entries resolved.
     *
     * @param MediaEntryInterface[] $entries
     * @param array<string, array{file: string|null, message: string|null}> $resolved
     * @return array{0: array<string, array{file: string, media_type: string, label: string|null,
     *         position: int, disabled: int, roles: string[], video: array<string, string|null>|null}>,
     *         1: bool} the desired set and the partial flag
     */
    private function buildDesired(BatchContext $context, string $sku, array $entries, array $resolved): array
    {
        $desired = [];
        $partial = false;
        $ordinal = 0;

        foreach ($entries as $entry) {
            $reference = trim($entry->getFile());
            if ($reference === '') {
                $context->addMessage($sku, 'Media entry with an empty file skipped.');
                $partial = true;
                continue;
            }

            $file = $resolved[$reference]['file'] ?? null;
            if ($file === null) {
                $context->addMessage(
                    $sku,
                    $resolved[$reference]['message']
                        ?? sprintf('Media file "%s" could not be resolved; skipped.', $reference)
                );
                $partial = true;
                continue;
            }

            if (isset($desired[$file])) {
                // A feed that echoes the same file twice must not be frozen out
                // of ever removing anything, so this is deliberately not partial
                // (the same reasoning as LinkProcessor's self-link case).
                $context->addMessage($sku, sprintf('Duplicate media file "%s" skipped.', $reference));
                continue;
            }

            $mediaType = $this->resolveMediaType($context, $sku, $entry);
            $video = null;
            if ($mediaType === ProductMediaGallery::MEDIA_TYPE_EXTERNAL_VIDEO) {
                $video = $this->buildVideo($context, $sku, $entry);
                if ($video === null) {
                    $partial = true;
                    continue;
                }
            }

            $position = $entry->getPosition();
            $desired[$file] = [
                'file' => $file,
                'media_type' => $mediaType,
                'label' => $entry->getLabel(),
                // The column is unsigned; a negative value would abort the batch.
                'position' => $position !== null ? max(0, $position) : $ordinal,
                'disabled' => $entry->getDisabled() === true ? 1 : 0,
                'roles' => array_values(array_filter(array_map('trim', $entry->getRoles() ?? []))),
                'video' => $video,
            ];
            $ordinal++;
        }

        return [$desired, $partial];
    }

    /**
     * "external-video" when the entry declares a video, "image" otherwise. A
     * video on a store without Magento_ProductVideo degrades to a plain image:
     * the preview file is a perfectly valid gallery entry, so that must not trip
     * the safety valve.
     */
    private function resolveMediaType(BatchContext $context, string $sku, MediaEntryInterface $entry): string
    {
        $declared = $entry->getMediaType();
        if ($declared !== null
            && !in_array(
                $declared,
                [ProductMediaGallery::MEDIA_TYPE_IMAGE, ProductMediaGallery::MEDIA_TYPE_EXTERNAL_VIDEO],
                true
            )
        ) {
            $context->addMessage($sku, sprintf('Unknown media type "%s" treated as an image.', $declared));
            $declared = null;
        }

        $isVideo = $declared === ProductMediaGallery::MEDIA_TYPE_EXTERNAL_VIDEO
            || ($declared === null && $entry->getVideoUrl() !== null);
        if (!$isVideo) {
            return ProductMediaGallery::MEDIA_TYPE_IMAGE;
        }

        if (!$this->productMediaGallery->hasVideoTable()) {
            $context->addMessage(
                $sku,
                sprintf(
                    'Magento_ProductVideo is not installed; "%s" was imported as a plain image.',
                    trim($entry->getFile())
                )
            );

            return ProductMediaGallery::MEDIA_TYPE_IMAGE;
        }

        return ProductMediaGallery::MEDIA_TYPE_EXTERNAL_VIDEO;
    }

    /**
     * @return array<string, string|null>|null null when the entry cannot be a video
     */
    private function buildVideo(BatchContext $context, string $sku, MediaEntryInterface $entry): ?array
    {
        $url = trim((string)$entry->getVideoUrl());
        if ($url === '') {
            $context->addMessage(
                $sku,
                sprintf('Video entry "%s" has no video URL; skipped.', trim($entry->getFile()))
            );

            return null;
        }

        $provider = $entry->getVideoProvider() !== null
            ? mb_strtolower(trim($entry->getVideoProvider()))
            : $this->deriveProvider($url);
        if ($provider === null || $provider === '') {
            $context->addMessage(
                $sku,
                sprintf('Video URL "%s" has an unrecognised provider; entry skipped.', $url)
            );

            return null;
        }

        return [
            'provider' => $provider,
            'url' => $url,
            'title' => $entry->getVideoTitle(),
            'description' => $entry->getVideoDescription(),
            'metadata' => $entry->getVideoMetadata(),
        ];
    }

    private function deriveProvider(string $url): ?string
    {
        $host = mb_strtolower((string)parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return null;
        }
        foreach (self::VIDEO_PROVIDERS as $provider => $hosts) {
            if (in_array($host, $hosts, true)) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * Queue an entry's video record, or its removal when the entry stopped being
     * a video.
     *
     * @param array<string, mixed> $entry desired-set entry
     * @param array<string, string|null>|null $storedVideo
     * @param array<int, array<string, mixed>> $videoRows
     * @param int[] $videoDeletes
     * @return bool whether it queued a write or a delete, so the caller can
     *         report a video-only change as a change
     */
    private function planVideo(
        int $valueId,
        array $entry,
        ?array $storedVideo,
        array &$videoRows,
        array &$videoDeletes
    ): bool {
        if ($entry['video'] === null) {
            if ($storedVideo !== null) {
                $videoDeletes[] = $valueId;

                return true;
            }

            return false;
        }

        if ($storedVideo !== null) {
            $unchanged = true;
            foreach (ProductMediaGallery::VIDEO_FIELDS as $field) {
                if (($storedVideo[$field] ?? null) !== $entry['video'][$field]) {
                    $unchanged = false;
                    break;
                }
            }
            if ($unchanged) {
                return false;
            }
        }

        $videoRows[] = array_merge(['value_id' => $valueId], $entry['video']);

        return true;
    }

    /**
     * @return array<string, int> role code => attribute_id, for the roles this store has
     */
    private function collectRoleAttributeIds(): array
    {
        $ids = [];
        foreach (self::ROLE_CODES as $code) {
            $meta = $this->attributeMetadataCache->get($code);
            if ($meta !== null) {
                $ids[$code] = $meta['attribute_id'];
            }
        }

        return $ids;
    }

    /**
     * Point the role attributes at the files the payload nominated, clear roles
     * whose file this import removed, and optionally auto-assign the base roles.
     *
     * Written here rather than by EavValueProcessor (sort order 300) because a
     * role is a file path that only exists once the files have been resolved and
     * the gallery diff is known.
     *
     * @param array<string, array{link_id: int, desired: array<string, array<string, mixed>>,
     *         kept_files: array<string, int>, removed_files: string[]}> $plans
     * @param array<string, int> $roleAttributeIds
     * @return array<string, array<string, string|null>> sku => role code => the
     *         file the role now points at, or null where it was cleared, for
     *         the roles this actually wrote. Only the roles it touched appear,
     *         so an empty entry means "the roles were already right".
     */
    private function writeRoles(BatchContext $context, array $plans, array $roleAttributeIds): array
    {
        if (!$roleAttributeIds) {
            return [];
        }

        $linkField = $this->productEntity->getLinkField();
        $stored = $this->eavValue->getValuesForStores(
            'varchar',
            array_values($roleAttributeIds),
            array_column($plans, 'link_id')
        );

        $rows = [];
        $deleteKeys = [];
        $written = [];

        foreach ($plans as $sku => $plan) {
            $written[$sku] = [];
            $linkId = $plan['link_id'];
            $storedByAttribute = $stored[$linkId] ?? [];
            $assignments = $this->collectRoleAssignments($context, (string)$sku, $plan, $roleAttributeIds);

            foreach ($roleAttributeIds as $code => $attributeId) {
                $storedScopes = $storedByAttribute[$attributeId] ?? [];
                $assignment = $assignments[$code] ?? null;
                $target = $assignment['file'] ?? null;
                // Never named $stored: that holds the whole batch's value map,
                // and shadowing it here silently emptied the map for every
                // product after the first one in the batch.
                $storedDefault = $storedScopes[0] ?? null;
                // A stored role whose file this import removes is stale: it must
                // be repointed or cleared, and it is not a choice worth keeping.
                $isStale = in_array($storedDefault, $plan['removed_files'], true);

                // An auto-assigned role never overrides a live value a merchant chose.
                if ($assignment !== null
                    && $assignment['auto']
                    && !$isStale
                    && ($storedDefault ?? self::NO_SELECTION) !== self::NO_SELECTION
                ) {
                    continue;
                }

                if ($target === null) {
                    // Otherwise a media block leaves the roles alone, as it
                    // leaves every other attribute alone.
                    if (!$isStale) {
                        continue;
                    }
                    // Core deletes the role's rows in every scope rather than
                    // writing a sentinel; a store view left behind would ask the
                    // storefront for a file that no longer exists.
                    foreach (array_keys($storedScopes) as $storeId) {
                        $deleteKeys[] = [
                            'link_id' => $linkId,
                            'attribute_id' => $attributeId,
                            'store_id' => $storeId,
                        ];
                        $written[$sku][$code] = null;
                    }
                    continue;
                }

                // Always the default scope, plus any store view that already
                // overrides this role — but never a new store-scoped row.
                $storeIds = [0];
                foreach (array_keys($storedScopes) as $storeId) {
                    if ($storeId !== 0) {
                        $storeIds[] = $storeId;
                    }
                }
                foreach ($storeIds as $storeId) {
                    if (($storedScopes[$storeId] ?? null) === $target) {
                        continue;
                    }
                    $rows[] = [
                        $linkField => $linkId,
                        'attribute_id' => $attributeId,
                        'store_id' => $storeId,
                        'value' => $target,
                    ];
                    // Reported even when only a store view moved and the default
                    // scope already matched: a row was still written, and a
                    // consumer asking "did this import touch the base image"
                    // deserves a yes.
                    $written[$sku][$code] = $target;
                }
            }
        }

        $this->eavValue->upsert('varchar', $rows);
        $this->eavValue->delete('varchar', $deleteKeys);

        return $written;
    }

    /**
     * Resolve which file each role should point at for one product.
     *
     * @param array{desired: array<string, array<string, mixed>>, kept_files: array<string, int>,
     *         removed_files: string[]} $plan
     * @param array<string, int> $roleAttributeIds
     * @return array<string, array{file: string, auto: bool}> role code => target
     */
    private function collectRoleAssignments(
        BatchContext $context,
        string $sku,
        array $plan,
        array $roleAttributeIds
    ): array {
        $assignments = [];
        $declaredAny = false;

        foreach ($plan['desired'] as $file => $entry) {
            foreach ($entry['roles'] as $code) {
                $declaredAny = true;
                if (!isset($roleAttributeIds[$code])) {
                    $context->addMessage(
                        $sku,
                        in_array($code, self::ROLE_CODES, true)
                            ? sprintf('Media role "%s" does not exist in this store; skipped.', $code)
                            : sprintf('Unknown media role "%s" skipped.', $code)
                    );
                    continue;
                }
                if (isset($assignments[$code])) {
                    $context->addMessage(
                        $sku,
                        sprintf(
                            'Media role "%s" is claimed by more than one entry; the first occurrence wins.',
                            $code
                        )
                    );
                    continue;
                }
                // A role can only point at an entry that survived the diff.
                if (isset($plan['kept_files'][$file])) {
                    $assignments[$code] = ['file' => (string)$file, 'auto' => false];
                }
            }
        }

        if ($declaredAny || !$this->config->isMediaAutoAssignRoles()) {
            return $assignments;
        }

        // No roles declared anywhere: nominate the first enabled image for the
        // base roles. writeRoles() then applies it only where the product has no
        // role of its own yet, so a merchant's choice is never overwritten.
        $first = null;
        foreach ($plan['desired'] as $file => $entry) {
            if ($entry['disabled'] === 0
                && $entry['media_type'] === ProductMediaGallery::MEDIA_TYPE_IMAGE
                && isset($plan['kept_files'][$file])
            ) {
                $first = (string)$file;
                break;
            }
        }
        if ($first === null) {
            return $assignments;
        }

        foreach (self::AUTO_ASSIGN_ROLES as $code) {
            if (isset($roleAttributeIds[$code])) {
                $assignments[$code] = ['file' => $first, 'auto' => true];
            }
        }

        return $assignments;
    }

    public function isEnabled(): bool
    {
        return $this->config->isMediaEnabled();
    }

    public function getSortOrder(): int
    {
        return 710;
    }
}
