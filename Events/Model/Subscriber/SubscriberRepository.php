<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Model\Subscriber;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Math\Random;
use Magento\Framework\Serialize\Serializer\Json;
use ReadyData\Events\Model\Subscription\SubscriptionMap;

/**
 * CRUD over readydata_event_subscriber.
 *
 * v1 permits exactly one row. The column and the endpoints stay multi-capable
 * because they cost nothing now and are what a second destination would need,
 * but the dispatcher, the state machine and the UI are all written for one — so
 * the constraint is enforced here rather than being an unwritten assumption
 * that a second row would quietly violate.
 */
class SubscriberRepository
{
    public const TABLE = 'readydata_event_subscriber';

    private const SECRET_BYTES = 32;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly EncryptorInterface $encryptor,
        private readonly Random $random,
        private readonly Json $json,
        private readonly SubscriptionMap $subscriptionMap
    ) {
    }

    public function getTable(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }

    /** The single registered subscriber, or null if nothing is registered yet. */
    public function getActive(): ?Subscriber
    {
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()->from($this->getTable())->order('subscriber_id ASC')->limit(1)
        );

        return $row ? $this->hydrate($row) : null;
    }

    public function getById(int $id): Subscriber
    {
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()->from($this->getTable())->where('subscriber_id = ?', $id)
        );

        if (!$row) {
            throw new NoSuchEntityException(__('No subscriber with id "%1".', $id));
        }

        return $this->hydrate($row);
    }

    public function getByCode(string $code): Subscriber
    {
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()->from($this->getTable())->where('code = ?', $code)
        );

        if (!$row) {
            throw new NoSuchEntityException(__('No subscriber with code "%1".', $code));
        }

        return $this->hydrate($row);
    }

    /**
     * Registers the destination and returns it with the generated secret in
     * clear — the only time it is ever readable. It is stored encrypted and
     * every later read returns the decrypted value only into a short-lived
     * object, so a caller that loses this response has to rotate rather than
     * recover.
     *
     * @param array<string, string> $headers
     */
    public function register(
        string $code,
        string $endpointUrl,
        ?string $secret = null,
        int $maxBatchSize = 100,
        array $headers = []
    ): Subscriber {
        $connection = $this->resource->getConnection();

        $existing = $this->getActive();
        if ($existing !== null && $existing->code !== $code) {
            throw new AlreadyExistsException(
                __(
                    'A subscriber ("%1") is already registered. This version delivers to exactly one destination; '
                    . 'remove the existing subscriber before registering a different one.',
                    $existing->code
                )
            );
        }

        $secret = $secret !== null && $secret !== ''
            ? $secret
            : $this->random->getRandomString(self::SECRET_BYTES);

        $data = [
            'code' => $code,
            'endpoint_url' => $endpointUrl,
            'secret' => $this->encryptor->encrypt($secret),
            'enabled' => 1,
            'max_batch_size' => max(1, $maxBatchSize),
            'headers' => $headers === [] ? null : $this->json->serialize($headers),
        ];

        if ($existing !== null) {
            $connection->update($this->getTable(), $data, ['subscriber_id = ?' => $existing->id]);
            $id = $existing->id;
        } else {
            $connection->insert($this->getTable(), $data);
            $id = (int)$connection->lastInsertId($this->getTable());
        }

        $this->subscriptionMap->invalidate();

        return new Subscriber($id, $code, $endpointUrl, $secret, true, max(1, $maxBatchSize), $headers);
    }

    public function setEnabled(string $code, bool $enabled): void
    {
        $connection = $this->resource->getConnection();
        $connection->update($this->getTable(), ['enabled' => $enabled ? 1 : 0], ['code = ?' => $code]);
        $this->subscriptionMap->invalidate();
    }

    /**
     * Deleting a destination cascades to its subscriptions by foreign key: a
     * subscription with nowhere to deliver is not configuration worth keeping,
     * and leaving orphans would let capture write rows nothing will ever send.
     */
    public function delete(string $code): void
    {
        $connection = $this->resource->getConnection();
        $connection->delete($this->getTable(), ['code = ?' => $code]);
        $this->subscriptionMap->invalidate();
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Subscriber
    {
        $headers = [];
        if (!empty($row['headers'])) {
            $decoded = $this->json->unserialize((string)$row['headers']);
            if (is_array($decoded)) {
                $headers = array_map('strval', $decoded);
            }
        }

        return new Subscriber(
            (int)$row['subscriber_id'],
            (string)$row['code'],
            (string)$row['endpoint_url'],
            (string)$this->encryptor->decrypt((string)$row['secret']),
            (bool)$row['enabled'],
            (int)$row['max_batch_size'],
            $headers
        );
    }
}
