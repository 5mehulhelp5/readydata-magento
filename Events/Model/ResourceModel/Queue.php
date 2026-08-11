<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Direct SQL over readydata_event_queue.
 *
 * No ORM: capture inserts hundreds of rows per request and the dispatcher works
 * in batches, so both paths want set-based statements rather than a model per row.
 */
class Queue
{
    public const STATUS_WAITING = 0;
    public const STATUS_SENT = 1;
    public const STATUS_FAILED = 2;
    public const STATUS_IN_PROGRESS = 3;
    /**
     * Retries exhausted. Adobe leaves these at "failed"; a distinct terminal
     * state makes "needs a human" a query rather than a judgement about whether
     * a failed row will be retried again.
     */
    public const STATUS_DEAD_LETTER = 4;

    public const TABLE = 'readydata_event_queue';

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function getConnection(): AdapterInterface
    {
        return $this->resource->getConnection();
    }

    public function getTable(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }

    /**
     * One multi-row INSERT for the whole buffer. A mass action saving 500
     * products costs one statement, not 500.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    public function insertMultiple(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        return (int)$this->getConnection()->insertMultiple($this->getTable(), $rows);
    }

    /** Events awaiting delivery or awaiting a retry — the depth the volume guard watches. */
    public function countPending(): int
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getTable(), ['count' => new \Zend_Db_Expr('COUNT(*)')])
            ->where('status IN (?)', [self::STATUS_WAITING, self::STATUS_FAILED]);

        return (int)$connection->fetchOne($select);
    }

    /** @return array<int, int> status => count */
    public function statusCounts(): array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getTable(), ['status', 'count' => new \Zend_Db_Expr('COUNT(*)')])
            ->group('status');

        $counts = [];
        foreach ($connection->fetchAll($select) as $row) {
            $counts[(int)$row['status']] = (int)$row['count'];
        }

        return $counts;
    }

    /**
     * When the oldest undelivered event was captured.
     *
     * Depth alone cannot distinguish a busy store from a broken cron; age can.
     */
    public function getOldestWaitingAt(): ?string
    {
        $connection = $this->getConnection();
        $value = $connection->fetchOne(
            $connection->select()
                ->from($this->getTable(), ['oldest' => new \Zend_Db_Expr('MIN(created_at)')])
                ->where('status IN (?)', [self::STATUS_WAITING, self::STATUS_FAILED])
        );

        return $value !== false && $value !== null ? (string)$value : null;
    }

    /**
     * Atomically take ownership of a batch.
     *
     * The UPDATE is the claim: it stamps a token and moves rows to "in progress"
     * in one statement, so two cron nodes running concurrently cannot both pick
     * up the same event and double-send it. Selecting first and updating after
     * would leave exactly that window open.
     */
    public function claimBatch(string $lockToken, int $limit, int $maxRetries): int
    {
        $connection = $this->getConnection();

        $sql = sprintf(
            'UPDATE %s SET status = %d, lock_token = %s'
            . ' WHERE status IN (%d, %d) AND retries < %d'
            . ' AND (next_attempt_at IS NULL OR next_attempt_at <= %s)'
            . ' ORDER BY queue_id ASC LIMIT %d',
            $connection->quoteIdentifier($this->getTable()),
            self::STATUS_IN_PROGRESS,
            $connection->quote($lockToken),
            self::STATUS_WAITING,
            self::STATUS_FAILED,
            $maxRetries,
            $connection->quote((new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')),
            $limit
        );

        return (int)$connection->query($sql)->rowCount();
    }

    /** @return array<int, array<string, mixed>> */
    public function fetchClaimed(string $lockToken): array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getTable())
            ->where('lock_token = ?', $lockToken)
            ->where('status = ?', self::STATUS_IN_PROGRESS)
            ->order('queue_id ASC');

        return $connection->fetchAll($select);
    }

    /** @param int[] $queueIds */
    public function markSent(array $queueIds): void
    {
        if ($queueIds === []) {
            return;
        }

        $this->getConnection()->update(
            $this->getTable(),
            [
                'status' => self::STATUS_SENT,
                'lock_token' => null,
                'info' => null,
                'sent_at' => new \Zend_Db_Expr('UTC_TIMESTAMP()'),
            ],
            ['queue_id IN (?)' => $queueIds]
        );
    }

    /**
     * Failure bumps the attempt counter and pushes the next attempt out, so a
     * failing endpoint is retried with increasing spacing rather than hammered
     * once a minute. Rows that have run out of attempts go to the dead-letter
     * state in the same statement.
     *
     * @param int[] $queueIds
     */
    public function markFailed(array $queueIds, string $info, int $backoffSeconds, int $maxRetries): void
    {
        if ($queueIds === []) {
            return;
        }

        $connection = $this->getConnection();

        $connection->update(
            $this->getTable(),
            [
                'status' => new \Zend_Db_Expr(
                    sprintf('IF(retries + 1 >= %d, %d, %d)', $maxRetries, self::STATUS_DEAD_LETTER, self::STATUS_FAILED)
                ),
                'retries' => new \Zend_Db_Expr('retries + 1'),
                'info' => mb_substr($info, 0, 2000),
                'lock_token' => null,
                'next_attempt_at' => new \Zend_Db_Expr(
                    sprintf('DATE_ADD(UTC_TIMESTAMP(), INTERVAL (retries + 1) * %d SECOND)', $backoffSeconds)
                ),
            ],
            ['queue_id IN (?)' => $queueIds]
        );
    }

    /**
     * Release rows a previous run left claimed.
     *
     * A dispatcher killed mid-flight leaves rows stuck at "in progress" with a
     * token nobody will ever finish, and without this they are never retried.
     */
    public function releaseStaleClaims(int $olderThanSeconds): int
    {
        $connection = $this->getConnection();

        return (int)$connection->update(
            $this->getTable(),
            ['status' => self::STATUS_FAILED, 'lock_token' => null, 'info' => 'Reclaimed after a dispatcher did not finish'],
            [
                'status = ?' => self::STATUS_IN_PROGRESS,
                'created_at < ?' => (new \DateTimeImmutable(sprintf('-%d seconds', $olderThanSeconds), new \DateTimeZone('UTC')))
                    ->format('Y-m-d H:i:s'),
            ]
        );
    }

    /**
     * Retention deletes settled events only. An event still waiting is never
     * deleted however old it is — age means delivery is broken, and deleting the
     * evidence would turn a visible backlog into silent data loss.
     */
    public function deleteSettledOlderThan(int $days): int
    {
        $connection = $this->getConnection();

        return (int)$connection->delete(
            $this->getTable(),
            [
                'status IN (?)' => [self::STATUS_SENT, self::STATUS_DEAD_LETTER],
                'created_at < ?' => (new \DateTimeImmutable(sprintf('-%d days', $days), new \DateTimeZone('UTC')))
                    ->format('Y-m-d H:i:s'),
            ]
        );
    }
}
