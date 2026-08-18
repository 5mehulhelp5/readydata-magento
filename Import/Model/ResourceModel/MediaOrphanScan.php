<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\LocalizedException;
use ReadyData\Import\Logger\Logger;
use ReadyData\Import\Model\Cache\AttributeMetadataCache;
use ReadyData\Import\Model\Media\Cleanup\MediaPathNormalizer;

/**
 * The database half of the orphan-media report: two temporary tables and the
 * joins between them.
 *
 * Why tables and not per-path lookups. Neither
 * catalog_product_entity_media_gallery.value nor catalog_product_entity_varchar.value
 * carries an index, so every "is this path referenced" probe is a full table
 * scan — fine for one batch's removed_files, which is what
 * {@see \ReadyData\Import\Api\MediaReferenceCheckerInterface} is for, and
 * hopeless for half a million files. Loading the candidates into an indexed
 * table instead lets each reference source be read with a single scan, and
 * turns every reported number into an indexed join in both directions.
 *
 * Two schema decisions are load-bearing:
 *
 *  - The path columns are VARBINARY. Mysql::setDefaultCharsetAndCollation()
 *    injects the connection's default charset and collation into every varchar,
 *    char and text column it creates, and below MySQL 8.0.29 that default is
 *    utf8mb3 — joined against utf8mb4 core columns, MySQL either coerces the
 *    temp side, which is the only side with an index, or refuses with "illegal
 *    mix of collations". VARBINARY is not in that list, so nothing is injected.
 *    Byte-exact comparison is also the correct semantics on a Linux filesystem:
 *    a _ci collation would treat /a/b/Foo.JPG and /a/b/foo.jpg as one path.
 *  - The reference table's primary key leads with `path`, not `source`. A
 *    source-leading key cannot serve "ON r.path = c.path", which is every query
 *    below, and the anti-join would degrade to a full scan.
 *
 * Temporary tables are per-connection and ResourceConnection hands out one
 * connection for the life of the process, so they survive between calls here
 * and are invisible to anything else. They are dropped before being created —
 * core does the same, because a pooled connection can carry a previous run's
 * table.
 */
class MediaOrphanScan
{
    /**
     * Deliberately NOT run through getTableName(): createTemporaryTable()
     * quotes the name exactly as given, so prefixing one reference and not
     * another is what breaks. These are session-local and cannot collide.
     */
    private const T_CANDIDATE = 'readydata_media_scan_candidate';
    private const T_REFERENCE = 'readydata_media_scan_reference';

    private const T_GALLERY = 'catalog_product_entity_media_gallery';
    private const T_VALUE_TO_ENTITY = 'catalog_product_entity_media_gallery_value_to_entity';
    private const T_GALLERY_ASSET = 'media_gallery_asset';
    private const T_CONTENT_ASSET = 'media_content_asset';

    public const SOURCE_GALLERY = 1;
    public const SOURCE_ROLE = 2;
    public const SOURCE_CONTENT = 3;

    private const CHUNK = 1000;

    /** @var array<string, bool> memoised isTableExists() answers */
    private array $tableExists = [];

    /**
     * @param string[] $roleAttributeCodes image role attributes whose value is
     *        a reference; see etc/di.xml, and keep in step with the list given
     *        to MediaReferenceChecker
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly AttributeMetadataCache $attributeMetadataCache,
        private readonly MediaPathNormalizer $normalizer,
        private readonly Logger $logger,
        private readonly array $roleAttributeCodes = []
    ) {
    }

    /**
     * @throws LocalizedException when a reconnect has destroyed the tables
     */
    public function createTables(): void
    {
        $connection = $this->resourceConnection->getConnection();

        $candidate = $connection->newTable(self::T_CANDIDATE)
            ->addColumn('path', Table::TYPE_VARBINARY, self::pathLength(), [
                'nullable' => false,
                'primary' => true,
            ])
            ->addColumn('size', Table::TYPE_BIGINT, null, ['nullable' => false, 'unsigned' => true, 'default' => 0])
            ->addColumn('mtime', Table::TYPE_INTEGER, null, ['nullable' => false, 'unsigned' => true, 'default' => 0]);

        $reference = $connection->newTable(self::T_REFERENCE)
            ->addColumn('path', Table::TYPE_VARBINARY, self::pathLength(), ['nullable' => false, 'primary' => true])
            ->addColumn('source', Table::TYPE_SMALLINT, null, [
                'nullable' => false,
                'unsigned' => true,
                'primary' => true,
            ]);

        $connection->dropTemporaryTable(self::T_CANDIDATE);
        $connection->dropTemporaryTable(self::T_REFERENCE);
        $connection->createTemporaryTable($candidate);
        $connection->createTemporaryTable($reference);
    }

    /**
     * Best-effort teardown for a finally block: a failure here must not mask
     * whatever sent us into it, and the tables die with the connection anyway.
     */
    public function dropTables(): void
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $connection->dropTemporaryTable(self::T_REFERENCE);
            $connection->dropTemporaryTable(self::T_CANDIDATE);
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('Could not drop the media scan temporary tables: %s', $e->getMessage())
            );
        }
    }

    /**
     * @param array<int, array{path: string, size: int, mtime: int}> $rows
     * @throws LocalizedException
     */
    public function addCandidates(array $rows): void
    {
        if (!$rows) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            // insertOnDuplicate rather than insertMultiple: the same canonical
            // path cannot legitimately appear twice, but a retried walk or a
            // symlink the guard did not catch must not abort the scan on a
            // duplicate key.
            $this->guardConnection(
                fn () => $connection->insertOnDuplicate(self::T_CANDIDATE, $chunk, ['size', 'mtime'])
            );
        }
    }

    /**
     * Read every reference source into the reference table.
     *
     * MUST run after the candidates are loaded, never before. References then
     * only grow relative to the disk snapshot, so a concurrent import skews
     * results toward "referenced"; the other order would report a file written
     * and committed mid-scan as an orphan, which is the direction that does
     * harm.
     *
     * @return array<int, int> source => rows inserted
     * @throws LocalizedException
     */
    public function loadReferences(): array
    {
        return [
            self::SOURCE_GALLERY => $this->loadGalleryReferences(),
            self::SOURCE_ROLE => $this->loadRoleReferences(),
            self::SOURCE_CONTENT => $this->loadContentReferences(),
        ];
    }

    /**
     * Bound gallery rows. The join onto _value_to_entity is the invariant this
     * whole feature rests on: a product delete cascades that table away but
     * leaves the main gallery row, so counting rows by path alone — which is
     * what core's Gallery::countImageUses() does — reports a deleted product's
     * leftovers as live uses and makes the file permanently un-collectable.
     * Same rule as ProductMediaGallery::findReferencedFiles().
     */
    private function loadGalleryReferences(): int
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->distinct()
            ->from(['g' => $this->resourceConnection->getTableName(self::T_GALLERY)], [])
            ->join(
                ['b' => $this->resourceConnection->getTableName(self::T_VALUE_TO_ENTITY)],
                'b.value_id = g.value_id',
                []
            )
            ->columns(['path' => 'g.value', 'source' => new \Zend_Db_Expr((string)self::SOURCE_GALLERY)])
            ->where('g.value IS NOT NULL')
            ->where("g.value != ''");

        return $this->insertIgnoreFromSelect($select);
    }

    /**
     * Image role attributes, in EVERY store scope — a role set on one store
     * view only is still a reference, and a default-scope filter would report
     * the file as unused.
     */
    private function loadRoleReferences(): int
    {
        $inserted = 0;
        foreach ($this->roleAttributeIdsByBackendType() as $backendType => $attributeIds) {
            $connection = $this->resourceConnection->getConnection();
            $select = $connection->select()
                ->distinct()
                ->from(
                    ['v' => $this->resourceConnection->getTableName('catalog_product_entity_' . $backendType)],
                    []
                )
                ->columns(['path' => 'v.value', 'source' => new \Zend_Db_Expr((string)self::SOURCE_ROLE)])
                ->where('v.attribute_id IN (?)', $attributeIds)
                ->where('v.value IS NOT NULL')
                ->where("v.value != ''");

            $inserted += $this->insertIgnoreFromSelect($select);
        }

        return $inserted;
    }

    /**
     * Media-gallery content links: CMS pages, blocks and descriptions that
     * reference an asset.
     *
     * Expect ZERO on a stock store. Magento_MediaGalleryCatalog's directory.xml
     * excludes /^catalog\/product/ from gallery synchronisation, so no product
     * image ever gets an asset row and nothing can link to one. The pass is
     * still run because it is one indexed insert and it is correct on a store
     * that removed the exclusion or carries a pre-existing asset table — but a
     * zero here means "excluded by design", not "sync is broken", and the
     * caller must present it that way. See countAssetRowsUnderBasePath().
     *
     * The prefix is stripped by the configured base path's real length rather
     * than a literal, so a store whose base path is not catalog/product does
     * not silently produce paths cut in the wrong place.
     */
    private function loadContentReferences(): int
    {
        if (!$this->hasTable(self::T_GALLERY_ASSET) || !$this->hasTable(self::T_CONTENT_ASSET)) {
            return 0;
        }

        $connection = $this->resourceConnection->getConnection();
        $offset = $this->normalizer->basePathLength() + 1;
        $select = $connection->select()
            ->distinct()
            ->from(['a' => $this->resourceConnection->getTableName(self::T_GALLERY_ASSET)], [])
            ->join(
                ['c' => $this->resourceConnection->getTableName(self::T_CONTENT_ASSET)],
                'c.asset_id = a.id',
                []
            )
            ->columns([
                'path' => new \Zend_Db_Expr(sprintf('SUBSTRING(a.path, %d)', $offset)),
                'source' => new \Zend_Db_Expr((string)self::SOURCE_CONTENT),
            ])
            ->where('a.path LIKE ?', $this->normalizer->basePath() . '/%');

        return $this->insertIgnoreFromSelect($select);
    }

    /**
     * How many candidates each source accounts for.
     *
     * Overlap, deliberately — not "rows this pass eliminated". Sequential
     * elimination would make every count after the first depend on the order
     * the passes ran in, and since role values are almost always a subset of
     * gallery paths, the role source would report near zero on every healthy
     * store. That is the opposite of a safety instrument.
     *
     * @return array<int, int> source => candidates referenced
     */
    public function countReferencedCandidates(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(['r' => self::T_REFERENCE], ['source', 'total' => new \Zend_Db_Expr('COUNT(*)')])
            ->join(['c' => self::T_CANDIDATE], 'c.path = r.path', [])
            ->group('r.source');

        return $this->fetchCountsBySource($select);
    }

    /**
     * References whose file is NOT on disk, per source. The trust guard.
     *
     * If path normalisation is wrong — the base path stripped at the wrong
     * offset, a stray leading slash, a collation surprise — then nothing
     * matches, this number is essentially the whole reference table, and every
     * other figure in the report is garbage that looks authoritative. Below
     * that threshold it is simply the missing-image count, which is worth
     * knowing in its own right.
     *
     * @return array<int, int> source => references with no candidate
     */
    public function countMissingReferences(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(['r' => self::T_REFERENCE], ['source', 'total' => new \Zend_Db_Expr('COUNT(*)')])
            ->where(
                'NOT EXISTS (?)',
                new \Zend_Db_Expr(
                    sprintf('SELECT 1 FROM %s c WHERE c.path = r.path', $connection->quoteIdentifier(self::T_CANDIDATE))
                )
            )
            ->group('r.source');

        return $this->fetchCountsBySource($select);
    }

    /**
     * Unreferenced candidates grouped into age buckets, computed in SQL so the
     * orphan set is never materialised in PHP.
     *
     * @param array<string, int> $boundaries bucket label => inclusive lower mtime
     *        bound, ordered newest first
     * @return array<string, array{files: int, bytes: int}> label => totals,
     *         in the order the boundaries were given, plus 'unknown' and the
     *         oldest catch-all
     */
    public function aggregateOrphansByAge(array $boundaries, string $oldestLabel, string $unknownLabel): array
    {
        $connection = $this->resourceConnection->getConnection();

        $case = 'CASE';
        // mtime 0 means stat() gave us nothing; it must not be read as 1970 and
        // land in the oldest bucket, which is the one an operator would act on.
        $case .= sprintf(' WHEN c.mtime = 0 THEN %s', $connection->quote($unknownLabel));
        foreach ($boundaries as $label => $since) {
            $case .= sprintf(' WHEN c.mtime >= %d THEN %s', $since, $connection->quote($label));
        }
        $case .= sprintf(' ELSE %s END', $connection->quote($oldestLabel));

        $select = $connection->select()
            ->from(
                ['c' => self::T_CANDIDATE],
                [
                    'bucket' => new \Zend_Db_Expr($case),
                    'files' => new \Zend_Db_Expr('COUNT(*)'),
                    'bytes' => new \Zend_Db_Expr('COALESCE(SUM(c.size), 0)'),
                ]
            )
            ->where(
                'NOT EXISTS (?)',
                new \Zend_Db_Expr(
                    sprintf('SELECT 1 FROM %s r WHERE r.path = c.path', $connection->quoteIdentifier(self::T_REFERENCE))
                )
            )
            ->group(new \Zend_Db_Expr($case));

        $found = [];
        foreach ($connection->fetchAll($select) as $row) {
            $found[(string)$row['bucket']] = [
                'files' => (int)$row['files'],
                'bytes' => (int)$row['bytes'],
            ];
        }

        $buckets = [];
        foreach ([...array_keys($boundaries), $oldestLabel, $unknownLabel] as $label) {
            $buckets[$label] = $found[$label] ?? ['files' => 0, 'bytes' => 0];
        }

        return $buckets;
    }

    /**
     * One page of unreferenced paths, keyed off the primary key rather than an
     * OFFSET so the cost does not grow with the page number — and so the whole
     * list is never fetched at once, which is the point of doing this in SQL.
     *
     * @return string[]
     */
    public function fetchOrphanPage(string $afterPath, int $limit): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(['c' => self::T_CANDIDATE], ['path'])
            ->where(
                'NOT EXISTS (?)',
                new \Zend_Db_Expr(
                    sprintf('SELECT 1 FROM %s r WHERE r.path = c.path', $connection->quoteIdentifier(self::T_REFERENCE))
                )
            )
            ->order('c.path ASC')
            ->limit($limit);

        if ($afterPath !== '') {
            $select->where('c.path > ?', $afterPath);
        }

        return array_map('strval', $connection->fetchCol($select));
    }

    /**
     * Gallery rows with no product binding — what core leaves behind on a
     * product delete, since its Gallery\DeleteHandler is not wired into the
     * entity manager's delete actions. Reported, never touched: they are not
     * this module's to reap.
     */
    public function countUnboundGalleryRows(): int
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(['g' => $this->resourceConnection->getTableName(self::T_GALLERY)], [
                'total' => new \Zend_Db_Expr('COUNT(*)'),
            ])
            ->joinLeft(
                ['b' => $this->resourceConnection->getTableName(self::T_VALUE_TO_ENTITY)],
                'b.value_id = g.value_id',
                []
            )
            ->where('b.value_id IS NULL');

        return (int)$connection->fetchOne($select);
    }

    /**
     * Asset rows under the product media path. Context for the content
     * source's zero: none here means the media gallery excludes catalog/product
     * (the stock configuration), not that synchronisation has failed.
     */
    public function countAssetRowsUnderBasePath(): int
    {
        if (!$this->hasTable(self::T_GALLERY_ASSET)) {
            return 0;
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName(self::T_GALLERY_ASSET), [
                'total' => new \Zend_Db_Expr('COUNT(*)'),
            ])
            ->where('path LIKE ?', $this->normalizer->basePath() . '/%');

        return (int)$connection->fetchOne($select);
    }

    /**
     * INSERT IGNORE, so a path already recorded for the same source is dropped
     * on the primary key rather than aborting the pass.
     *
     * @throws LocalizedException
     */
    private function insertIgnoreFromSelect(Select $select): int
    {
        $connection = $this->resourceConnection->getConnection();
        $sql = sprintf(
            'INSERT IGNORE INTO %s (path, source) %s',
            $connection->quoteIdentifier(self::T_REFERENCE),
            $select->assemble()
        );

        return $this->guardConnection(static fn (): int => $connection->query($sql)->rowCount());
    }

    /**
     * @param Select $select selecting `source` and `total`
     * @return array<int, int>
     */
    private function fetchCountsBySource(Select $select): array
    {
        $counts = [self::SOURCE_GALLERY => 0, self::SOURCE_ROLE => 0, self::SOURCE_CONTENT => 0];
        foreach ($this->resourceConnection->getConnection()->fetchAll($select) as $row) {
            $counts[(int)$row['source']] = (int)$row['total'];
        }

        return $counts;
    }

    /**
     * Turn "the temporary table vanished" into something an operator can act on.
     *
     * Mysql::performQuery() silently reconnects and retries on a dropped
     * connection (server timeout, a proxy, MySQL going away), and a reconnect
     * destroys every TEMPORARY table on that session. The retried statement
     * then fails with 1146 "table doesn't exist", which is not in the retry set
     * and would surface as a bare SQL error about a table nobody has heard of.
     *
     * @template T
     * @param callable(): T $run
     * @return T
     * @throws LocalizedException
     */
    private function guardConnection(callable $run)
    {
        try {
            return $run();
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), self::T_CANDIDATE)
                || str_contains($e->getMessage(), self::T_REFERENCE)
            ) {
                throw new LocalizedException(
                    __(
                        'The scan lost its temporary tables, which happens when the database connection is'
                        . ' re-established mid-run (a server timeout or a proxy). Re-run the command.'
                    ),
                    // LocalizedException takes an \Exception, not a \Throwable:
                    // handing it an \Error would fail on the type instead of
                    // reporting the problem we caught it to report.
                    $e instanceof \Exception ? $e : null
                );
            }

            throw $e;
        }
    }

    private function hasTable(string $table): bool
    {
        if (!isset($this->tableExists[$table])) {
            $this->tableExists[$table] = $this->resourceConnection->getConnection()->isTableExists(
                $this->resourceConnection->getTableName($table)
            );
        }

        return $this->tableExists[$table];
    }

    /**
     * Both `value` columns compared against are varchar(255); matching that
     * exactly is what makes an over-long path impossible rather than truncated.
     */
    private static function pathLength(): int
    {
        // 255 is also a hard boundary in the adapter, not just a column width:
        // _getColumnDefinition() renders a VARBINARY of 255 or less as
        // varbinary(N) and anything larger as BLOB, which cannot carry a
        // primary key without a prefix length. Raising this silently turns both
        // tables into unindexed BLOB scans.
        return MediaPathNormalizer::MAX_PATH_LENGTH;
    }

    /**
     * Group the configured role codes by the backend type of the table their
     * values live in. A code this store never installed is dropped — a role
     * list naming a missing attribute is a misconfiguration, not a reason to
     * report every file as unreferenced. Same rule as MediaReferenceChecker.
     *
     * @return array<string, int[]> backend type => attribute ids
     */
    private function roleAttributeIdsByBackendType(): array
    {
        if (!$this->roleAttributeCodes) {
            return [];
        }

        $this->attributeMetadataCache->warm($this->roleAttributeCodes);

        $byType = [];
        foreach ($this->roleAttributeCodes as $code) {
            $attribute = $this->attributeMetadataCache->get((string)$code);
            if ($attribute === null || !in_array($attribute['backend_type'], EavValue::BACKEND_TYPES, true)) {
                continue;
            }
            $byType[$attribute['backend_type']][] = $attribute['attribute_id'];
        }

        return $byType;
    }
}
