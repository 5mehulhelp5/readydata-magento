<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Model\Delivery;

use Magento\Framework\Serialize\Serializer\Json;
use ReadyData\Events\Logger\Logger;
use ReadyData\Events\Model\Capture\ExtensionRegistry;
use ReadyData\Events\Model\Subscription\SubscriptionMap;

/**
 * Runs each subscription's processors over the queued payloads, at send time.
 *
 * The dispatcher calls this after claiming a batch and before building the
 * envelope, which is the whole point: the read happens now, so the delivered
 * payload reflects present-tense state rather than whatever was true when the
 * event was captured. Two saves of one SKU that coalesced into one queue row
 * deliver one current picture, not a stale snapshot.
 */
class PayloadEnricher
{
    public function __construct(
        private readonly SubscriptionMap $subscriptions,
        private readonly ExtensionRegistry $extensions,
        private readonly Json $json,
        private readonly Logger $logger
    ) {
    }

    /**
     * Enriches a claimed batch in place, returning rows with rewritten payloads.
     *
     * Never throws and never drops a row. A processor that fails leaves the
     * event exactly as captured — thin, but deliverable, and the subscriber
     * re-reads anyway. Failing the batch because enrichment failed would turn a
     * cosmetic problem into lost delivery.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public function enrich(array $rows): array
    {
        foreach ($rows as $index => $row) {
            $eventCode = (string)($row['event_code'] ?? '');
            $classNames = $this->processorsFor($eventCode);
            if ($classNames === []) {
                continue;
            }

            $payload = $this->decode((string)($row['payload'] ?? '{}'));

            foreach ($this->extensions->processors($classNames) as $processor) {
                try {
                    $payload = $processor->process($eventCode, $payload);
                } catch (\Throwable $e) {
                    $this->logger->error(sprintf(
                        'Event processor %s failed for "%s"; delivering the payload as captured: %s',
                        $processor::class,
                        $eventCode,
                        $e->getMessage()
                    ));
                }
            }

            $rows[$index]['payload'] = $this->json->serialize($payload);
        }

        return $rows;
    }

    /** @return string[] */
    private function processorsFor(string $eventCode): array
    {
        $names = [];
        foreach ($this->subscriptions->forCode($eventCode) as $subscription) {
            foreach ($subscription->processors as $className) {
                $names[$className] = true;
            }
        }

        return array_keys($names);
    }

    /** @return array<string, mixed> */
    private function decode(string $payload): array
    {
        try {
            $decoded = $this->json->unserialize($payload);
        } catch (\Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
