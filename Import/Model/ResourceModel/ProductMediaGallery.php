<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

/**
 * Direct reads/writes on the four product media gallery tables:
 * catalog_product_entity_media_gallery and its _value, _value_to_entity and
 * _value_video children.
 *
 * The gallery row itself carries NO product column — the binding lives in
 * _value_to_entity — while _value and _value_to_entity key on the entity LINK
 * FIELD (entity_id on CE, row_id on EE; resolve via ProductEntity::getLinkField()).
 * Core addresses the same columns through the metadata pool.
 *
 * Two schema details drive the write strategy:
 *
 *  - value_id is AUTO_INCREMENT and the row has no natural key, so a freshly
 *    inserted row cannot be re-selected by its own data the way
 *    catalog_product_link can. insertGalleryRows() therefore brackets the insert
 *    with the table's MAX(value_id) watermark and verifies what it reads back.
 *  - catalog_product_entity_media_gallery_value has its own AUTO_INCREMENT PK
 *    (record_id) and only a NON-UNIQUE index on (entity_id, value_id, store_id).
 *    insertOnDuplicate there would silently append duplicate rows instead of
 *    updating them, so saveValues() deletes the exact tuples first — which is
 *    also what core does (Gallery::deleteGalleryValueInStore +
 *    insertGalleryValueInStore).
 *
 * Gallery rows are never shared between products: one row per (product, file).
 * media_type, disabled and the whole video record have no product dimension, and
 * a shared row would let one product's removal cascade the image away from every
 * other product bound to it. The file on disk is shared; the rows are not.
 */
class ProductMediaGallery
{
    public const MEDIA_TYPE_IMAGE = 'image';
    public const MEDIA_TYPE_EXTERNAL_VIDEO = 'external-video';

    /**
     * Video columns, in the order they are compared and written.
     */
    public const VIDEO_FIELDS = ['provider', 'url', 'title', 'description', 'metadata'];

    private const T_GALLERY = 'catalog_product_entity_media_gallery';
    private const T_VALUE = 'catalog_product_entity_media_gallery_value';
    private const T_VALUE_TO_ENTITY = 'catalog_product_entity_media_gallery_value_to_entity';
    private const T_VIDEO = 'catalog_product_entity_media_gallery_value_video';
    private const CHUNK = 1000;

    /**
     * @var bool|null memoized for the request
     */
    private ?bool $videoTableExists = null;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly ProductEntity $productEntity
    ) {
    }

    /**
     * Whether Magento_ProductVideo's table is present. Soft dependency: on a
     * store without it, video entries degrade to plain images.
     */
    public function hasVideoTable(): bool
    {
        if ($this->videoTableExists === null) {
            $this->videoTableExists = $this->resourceConnection->getConnection()->isTableExists(
                $this->resourceConnection->getTableName(self::T_VIDEO)
            );
        }

        return $this->videoTableExists;
    }

    /**
     * Current gallery of the given products with their default-scope
     * (store_id = 0) label/position/disabled and video record.
     *
     * Ordered by value_id so the caller can resolve legacy data that repeats one
     * file path for the same product deterministically — lowest value_id wins.
     *
     * @param int[] $linkIds link field values
     * @return array<int, array<int, array{value_id: int, file: string, media_type: string,
     *         gallery_disabled: int, label: string|null, position: int|null,
     *         value_disabled: int, has_value_row: bool,
     *         video: array<string, string|null>|null}>> link id => rows
     */
    public function getGallery(array $linkIds, int $attributeId): array
    {
        if (!$linkIds) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $linkField = $this->productEntity->getLinkField();
        $quotedLinkField = $connection->quoteIdentifier($linkField);

        $select = $connection->select()
            ->from(
                ['b' => $this->resourceConnection->getTableName(self::T_VALUE_TO_ENTITY)],
                ['link_id' => $linkField]
            )
            ->join(
                ['g' => $this->resourceConnection->getTableName(self::T_GALLERY)],
                'g.value_id = b.value_id',
                ['value_id', 'value', 'media_type', 'gallery_disabled' => 'disabled']
            )
            ->joinLeft(
                ['v' => $this->resourceConnection->getTableName(self::T_VALUE)],
                sprintf('v.value_id = g.value_id AND v.%1$s = b.%1$s AND v.store_id = 0', $quotedLinkField),
                ['label', 'position', 'value_disabled' => 'disabled', 'record_id']
            )
            ->where('b.' . $quotedLinkField . ' IN (?)', $linkIds)
            ->where('g.attribute_id = ?', $attributeId)
            ->order('g.value_id ASC');

        if ($this->hasVideoTable()) {
            $select->joinLeft(
                ['vid' => $this->resourceConnection->getTableName(self::T_VIDEO)],
                'vid.value_id = g.value_id AND vid.store_id = 0',
                [
                    'video_present' => 'value_id',
                    'video_provider' => 'provider',
                    'video_url' => 'url',
                    'video_title' => 'title',
                    'video_description' => 'description',
                    'video_metadata' => 'metadata',
                ]
            );
        }

        $gallery = [];
        foreach ($connection->fetchAll($select) as $row) {
            $gallery[(int)$row['link_id']][] = [
                'value_id' => (int)$row['value_id'],
                // A NULL value is legacy junk no payload entry can ever match.
                'file' => (string)($row['value'] ?? ''),
                'media_type' => (string)($row['media_type'] ?? self::MEDIA_TYPE_IMAGE),
                'gallery_disabled' => (int)($row['gallery_disabled'] ?? 0),
                'label' => $row['label'] !== null ? (string)$row['label'] : null,
                'position' => $row['position'] !== null ? (int)$row['position'] : null,
                'value_disabled' => (int)($row['value_disabled'] ?? 0),
                'has_value_row' => isset($row['record_id']),
                'video' => isset($row['video_present']) ? [
                    'provider' => $row['video_provider'] !== null ? (string)$row['video_provider'] : null,
                    'url' => $row['video_url'] !== null ? (string)$row['video_url'] : null,
                    'title' => $row['video_title'] !== null ? (string)$row['video_title'] : null,
                    'description' => $row['video_description'] !== null ? (string)$row['video_description'] : null,
                    'metadata' => $row['video_metadata'] !== null ? (string)$row['video_metadata'] : null,
                ] : null,
            ];
        }

        return $gallery;
    }

    /**
     * Narrow a set of stored paths to those a product still has in its gallery.
     *
     * The INNER JOIN onto _value_to_entity is the whole point of this method, and
     * the one difference from core's Gallery::countImageUses(), which counts rows
     * in the main table by path alone. A product delete cascades the _value and
     * _value_to_entity rows away but leaves the main gallery row — its only FK is
     * on attribute_id — so counting unjoined rows reports a deleted product's
     * leftovers as live uses. Requiring the binding also needs no separate
     * liveness check: _value_to_entity's FK onto catalog_product_entity is
     * ON DELETE CASCADE, so a binding that exists names a product that exists.
     *
     * @param string[] $files stored paths ("/a/b/file.jpg")
     * @return string[] the subset still bound to a product, as a list
     */
    public function findReferencedFiles(array $files): array
    {
        if (!$files) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $referenced = [];

        foreach (array_chunk(array_values($files), self::CHUNK) as $chunk) {
            $select = $connection->select()
                ->distinct()
                ->from(['g' => $this->resourceConnection->getTableName(self::T_GALLERY)], ['value'])
                ->join(
                    ['b' => $this->resourceConnection->getTableName(self::T_VALUE_TO_ENTITY)],
                    'b.value_id = g.value_id',
                    []
                )
                ->where('g.value IN (?)', $chunk);

            foreach ($connection->fetchCol($select) as $value) {
                $referenced[] = (string)$value;
            }
        }

        return $referenced;
    }

    /**
     * Insert gallery rows and return their generated value_ids, keyed exactly as
     * $rows was.
     *
     * The row carries no product column, so — unlike ProductLink, where the
     * (link_type_id, product_id, linked_product_id) tuple can simply be re-read —
     * there is nothing to re-select a new row by. Instead the insert is bracketed
     * by the table's MAX(value_id): every row the statement creates gets a
     * greater value, rows within one INSERT are numbered in row order, and the
     * new rows are the only ones with no _value_to_entity binding yet. The stored
     * `value` of each row read back is then compared positionally against what
     * was sent, and ANY mismatch THROWS rather than returning a guess: writing a
     * bogus value_id into _value / _value_to_entity / _value_video would violate
     * their foreign keys and abort the whole batch anyway.
     *
     * Throwing rather than degrading to "no new entries" is deliberate. The
     * condition means we do not know which rows we just wrote, and those rows are
     * already in the table: the caller can neither bind them nor identify them to
     * clean them up, so carrying on would commit unbound orphan gallery rows that
     * every retry of the payload would duplicate. A throw hands the batch to
     * ImportService's rollBack(), which removes them atomically.
     *
     * @param array<int, array{attribute_id: int, value: string, media_type: string, disabled: int}> $rows
     * @return int[] generated value_ids keyed as $rows
     * @throws \RuntimeException when the read-back cannot be trusted
     */
    public function insertGalleryRows(array $rows): array
    {
        if (!$rows) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::T_GALLERY);
        $bindTable = $this->resourceConnection->getTableName(self::T_VALUE_TO_ENTITY);

        $watermark = (int)$connection->fetchOne(
            $connection->select()->from($table, new \Zend_Db_Expr('MAX(value_id)'))
        );

        $keys = array_keys($rows);
        $ordered = array_values($rows);
        foreach (array_chunk($ordered, self::CHUNK) as $chunk) {
            $connection->insertMultiple($table, $chunk);
        }

        $select = $connection->select()
            ->from(['g' => $table], ['value_id', 'value'])
            ->joinLeft(['b' => $bindTable], 'b.value_id = g.value_id', [])
            ->where('g.value_id > ?', $watermark)
            ->where('g.attribute_id = ?', (int)$ordered[0]['attribute_id'])
            ->where('b.value_id IS NULL')
            ->order('g.value_id ASC');

        $readBack = $connection->fetchAll($select);
        if (count($readBack) !== count($ordered)) {
            throw new \RuntimeException(sprintf(
                'Media gallery value_id read-back returned %d unbound rows for %d inserted;'
                . ' the generated ids cannot be trusted.',
                count($readBack),
                count($ordered)
            ));
        }

        $valueIds = [];
        foreach ($readBack as $index => $row) {
            if ((string)$row['value'] !== (string)$ordered[$index]['value']) {
                throw new \RuntimeException(sprintf(
                    'Media gallery value_id read-back is out of order at position %d ("%s" vs "%s");'
                    . ' the generated ids cannot be trusted.',
                    $index,
                    (string)$row['value'],
                    (string)$ordered[$index]['value']
                ));
            }
            $valueIds[$keys[$index]] = (int)$row['value_id'];
        }

        return $valueIds;
    }

    /**
     * Bind gallery rows to their product. The primary key is
     * (value_id, link field), so this is a genuine no-op upsert.
     *
     * @param array<int, array{value_id: int, link_id: int}> $rows
     */
    public function bindToEntities(array $rows): void
    {
        if (!$rows) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::T_VALUE_TO_ENTITY);
        $linkField = $this->productEntity->getLinkField();

        $bindRows = array_map(
            static fn (array $row): array => [
                'value_id' => $row['value_id'],
                $linkField => $row['link_id'],
            ],
            $rows
        );
        foreach (array_chunk($bindRows, self::CHUNK) as $chunk) {
            $connection->insertOnDuplicate($table, $chunk, ['value_id']);
        }
    }

    /**
     * Write the default-scope (store_id = 0) label/position/disabled rows.
     *
     * Delete-then-insert, not insertOnDuplicate: the table's unique key is its
     * own AUTO_INCREMENT record_id and (entity_id, value_id, store_id) is a plain
     * index, so an upsert would append a second row per image and show it twice
     * in the admin with conflicting labels.
     *
     * @param array<int, array{value_id: int, link_id: int, label: string|null,
     *         position: int|null, disabled: int}> $rows
     */
    public function saveValues(array $rows): void
    {
        if (!$rows) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::T_VALUE);
        $linkField = $this->productEntity->getLinkField();
        $quotedLinkField = $connection->quoteIdentifier($linkField);

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $tuples = array_map(
                static fn (array $row): string => sprintf('(%d,%d,0)', $row['value_id'], $row['link_id']),
                $chunk
            );
            $connection->delete(
                $table,
                sprintf('(value_id, %s, store_id) IN (%s)', $quotedLinkField, implode(',', $tuples))
            );

            $connection->insertMultiple(
                $table,
                array_map(
                    static fn (array $row): array => [
                        'value_id' => $row['value_id'],
                        'store_id' => 0,
                        $linkField => $row['link_id'],
                        'label' => $row['label'],
                        'position' => $row['position'],
                        'disabled' => $row['disabled'],
                    ],
                    $chunk
                )
            );
        }
    }

    /**
     * Update media_type/disabled on gallery rows that are being kept, one
     * statement per distinct state (cardinality is tiny). Deliberately not an
     * insertOnDuplicate with an explicit value_id: that would CREATE an orphan
     * gallery row if the id had vanished in the meantime.
     *
     * @param array<string, int[]> $idsByState "<media_type>|<disabled>" => value_ids
     */
    public function updateGalleryRows(array $idsByState): void
    {
        if (!$idsByState) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::T_GALLERY);
        foreach ($idsByState as $state => $valueIds) {
            if (!$valueIds) {
                continue;
            }
            [$mediaType, $disabled] = explode('|', (string)$state);
            foreach (array_chunk($valueIds, self::CHUNK) as $chunk) {
                $connection->update(
                    $table,
                    ['media_type' => $mediaType, 'disabled' => (int)$disabled],
                    ['value_id IN (?)' => $chunk]
                );
            }
        }
    }

    /**
     * Upsert the default-scope external-video rows. The primary key is
     * (value_id, store_id), so insertOnDuplicate is correct here.
     *
     * @param array<int, array{value_id: int, provider: string, url: string, title: string|null,
     *         description: string|null, metadata: string|null}> $rows
     */
    public function saveVideos(array $rows): void
    {
        if (!$rows || !$this->hasVideoTable()) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::T_VIDEO);
        $videoRows = array_map(
            static fn (array $row): array => [
                'value_id' => $row['value_id'],
                'store_id' => 0,
                'provider' => $row['provider'],
                'url' => $row['url'],
                'title' => $row['title'],
                'description' => $row['description'],
                'metadata' => $row['metadata'],
            ],
            $rows
        );
        foreach (array_chunk($videoRows, self::CHUNK) as $chunk) {
            $connection->insertOnDuplicate($table, $chunk, self::VIDEO_FIELDS);
        }
    }

    /**
     * Drop the video records of entries that stopped being videos. All stores:
     * the entry is no longer a video in any scope.
     *
     * @param int[] $valueIds
     */
    public function deleteVideos(array $valueIds): void
    {
        if (!$valueIds || !$this->hasVideoTable()) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::T_VIDEO);
        foreach (array_chunk($valueIds, self::CHUNK) as $chunk) {
            $connection->delete($table, ['value_id IN (?)' => $chunk]);
        }
    }

    /**
     * Remove entries from a product: unbind them, drop their value rows in every
     * store, then delete the gallery rows left unbound — which cascades any
     * remaining _value, _value_to_entity and _value_video rows via their FKs.
     *
     * The "left unbound" filter is what makes this safe against legacy data where
     * one value_id is bound to several products: such a row survives with its
     * other bindings instead of vanishing from the other products' galleries.
     *
     * @param array<int, array{value_id: int, link_id: int}> $tuples
     */
    public function removeEntries(array $tuples): void
    {
        if (!$tuples) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $bindTable = $this->resourceConnection->getTableName(self::T_VALUE_TO_ENTITY);
        $valueTable = $this->resourceConnection->getTableName(self::T_VALUE);
        $galleryTable = $this->resourceConnection->getTableName(self::T_GALLERY);
        $quotedLinkField = $connection->quoteIdentifier($this->productEntity->getLinkField());

        foreach (array_chunk($tuples, self::CHUNK) as $chunk) {
            $pairs = implode(
                ',',
                array_map(
                    static fn (array $tuple): string => sprintf('(%d,%d)', $tuple['value_id'], $tuple['link_id']),
                    $chunk
                )
            );
            $where = sprintf('(value_id, %s) IN (%s)', $quotedLinkField, $pairs);

            $connection->delete($bindTable, $where);
            $connection->delete($valueTable, $where);

            // Only rows nothing else points at may go; a row still bound to
            // another product keeps that product's gallery intact.
            $valueIds = array_map('intval', array_column($chunk, 'value_id'));
            $stillBound = array_map('intval', $connection->fetchCol(
                $connection->select()
                    ->from($bindTable, 'value_id')
                    ->where('value_id IN (?)', $valueIds)
            ));
            $orphans = array_values(array_diff($valueIds, $stillBound));
            if ($orphans) {
                $connection->delete($galleryTable, ['value_id IN (?)' => $orphans]);
            }
        }
    }
}
