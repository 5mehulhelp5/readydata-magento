<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Model\Delivery;

use Magento\Framework\Serialize\Serializer\Json;
use ReadyData\Events\Model\Config;

/**
 * Builds the batched CloudEvents 1.0 request body.
 *
 * CloudEvents rather than a bespoke envelope because it costs nothing to emit
 * and means a subscriber that is not ReadyData already knows how to read it.
 *
 * Delivery is at-least-once: a 2xx lost on the wire is re-sent, so `id` is the
 * idempotency key and the receiver is required to dedupe on it.
 */
class EnvelopeBuilder
{
    private const SPEC_VERSION = '1.0';

    public function __construct(
        private readonly Config $config,
        private readonly Json $json
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $rows Queue rows
     * @return array<string, mixed>
     */
    public function build(array $rows): array
    {
        $instanceId = $this->config->getInstanceId();

        $events = [];
        foreach ($rows as $row) {
            $events[] = [
                'specversion' => self::SPEC_VERSION,
                'id' => (string)$row['event_id'],
                'source' => 'magento/' . $instanceId,
                'type' => (string)$row['event_code'],
                'time' => $this->formatTime($row['created_at'] ?? null),
                'datacontenttype' => 'application/json',
                'data' => $this->decodePayload((string)$row['payload']),
            ];
        }

        return [
            'instance_id' => $instanceId,
            'events' => $events,
        ];
    }

    /** @return array<string, mixed> */
    private function decodePayload(string $payload): array
    {
        try {
            $decoded = $this->json->unserialize($payload);
        } catch (\Throwable) {
            // A row we cannot decode still has to be delivered rather than
            // block the batch behind it; the receiver sees the problem instead.
            return ['_undecodable_payload' => $payload];
        }

        return is_array($decoded) ? $decoded : ['value' => $decoded];
    }

    /** RFC 3339 in UTC, which is what CloudEvents requires. */
    private function formatTime(mixed $createdAt): string
    {
        $timezone = new \DateTimeZone('UTC');

        try {
            $time = $createdAt === null
                ? new \DateTimeImmutable('now', $timezone)
                : new \DateTimeImmutable((string)$createdAt, $timezone);
        } catch (\Throwable) {
            $time = new \DateTimeImmutable('now', $timezone);
        }

        return $time->format('Y-m-d\TH:i:s\Z');
    }
}
