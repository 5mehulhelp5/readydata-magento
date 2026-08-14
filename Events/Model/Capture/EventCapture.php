<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Model\Capture;

use ReadyData\Events\Logger\Logger;
use ReadyData\Events\Model\Config;
use ReadyData\Events\Model\Subscription\Subscription;
use ReadyData\Events\Model\Subscription\SubscriptionMap;
use ReadyData\Import\Model\ImportState;

/**
 * The capture path, shared by every generated observer and plugin.
 *
 * Ordered so the cheapest rejection comes first: a store with nothing subscribed
 * pays one flag read and one array lookup per dispatched catalogue event and
 * returns. Phase 0 measured that at ~0.79 us, against ~0.06 ms per request for
 * carrying the whole catalogue's registrations.
 *
 * Nothing in here is allowed to throw. Capture runs inside a merchant's save,
 * and an escaping exception would fail the save over a notification.
 */
class EventCapture
{
    /**
     * Per-request dedupe keys, so one entity saved several times in one request
     * queues once.
     *
     * Magento does this routinely — a payment module reacting to its own
     * notification, an observer that saves the entity it just observed — and the
     * reference App Builder integration guards the same shape with a static map
     * of processed orders. Without this, a store's every order reaches ReadyData
     * two or three times.
     *
     * @var array<string, true>
     */
    private array $seen = [];

    /** Counter for events carrying nothing to correlate on; see dedupeKey(). */
    private int $uncorrelated = 0;

    public function __construct(
        private readonly Config $config,
        private readonly SubscriptionMap $map,
        private readonly RuleEvaluator $rules,
        private readonly FieldExtractor $extractor,
        private readonly GateRegistry $gates,
        private readonly ExtensionRegistry $extensions,
        private readonly QueueBuffer $buffer,
        private readonly ImportState $importState,
        private readonly Logger $logger
    ) {
    }

    /**
     * @param array<string, mixed> $eventData
     */
    public function capture(string $eventCode, array $eventData): void
    {
        try {
            $this->doCapture($eventCode, $eventData);
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf('Event capture failed for "%s": %s', $eventCode, $e->getMessage()),
                ['exception' => $e]
            );
        }
    }

    /** @param array<string, mixed> $eventData */
    private function doCapture(string $eventCode, array $eventData): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        $subscriptions = $this->map->forCode($eventCode);
        if ($subscriptions === []) {
            return;
        }

        // Resolved once rather than per subscription: it is the same answer for
        // all of them and it is the guard that prevents an infinite loop.
        $importing = $this->importState->isImporting();

        // Resolved lazily and at most once. Most subscriptions are not
        // store-scoped, and finding a store id means probing several paths.
        $storeId = false;

        foreach ($subscriptions as $subscription) {
            if ($importing && $subscription->ignoreReadyDataOrigin) {
                continue;
            }

            if ($subscription->storeIds !== null) {
                if ($storeId === false) {
                    $storeId = $this->resolveStoreId($eventData);
                }
                if (!$subscription->matchesStore($storeId)) {
                    continue;
                }
            }

            if (!$this->rules->matches($subscription->rules, $eventData)) {
                continue;
            }

            if ($subscription->gateClass !== null) {
                $gate = $this->gates->get($subscription->gateClass);
                if ($gate === null || !$gate->shouldEmit($eventCode, $eventData)) {
                    continue;
                }
            }

            $payload = $this->extractor->extract(
                $eventData,
                $subscription->fields,
                $this->converterCallbacks($subscription)
            );

            $key = $this->dedupeKey($subscription, $eventCode, $eventData, $payload);
            if (isset($this->seen[$key])) {
                continue;
            }
            $this->seen[$key] = true;

            $this->buffer->add($eventCode, $subscription->subscriberId, $payload);
        }
    }

    /**
     * Resolves a subscription's converters into callables the extractor can
     * apply, dropping any that will not resolve.
     *
     * A converter that cannot be loaded drops its field rather than passing the
     * raw value through: converters exist to redact, so failing open would put
     * on the wire exactly what somebody configured it to withhold.
     *
     * @return array<string, callable(mixed, string): mixed>
     */
    private function converterCallbacks(Subscription $subscription): array
    {
        if ($subscription->converters === []) {
            return [];
        }

        $callbacks = [];
        foreach ($subscription->converters as $field => $className) {
            $converter = $this->extensions->converter($className);
            $callbacks[$field] = $converter !== null
                ? static fn(mixed $value, string $path): mixed => $converter->convert($value, $path)
                : static fn(): mixed => null;
        }

        return $callbacks;
    }

    /**
     * Flush whatever is buffered. The generated hooks do not call this — the
     * size cap and the shutdown handler do — but an import or a long-running
     * process can call it at a batch boundary to bound memory.
     */
    public function flush(): void
    {
        $this->buffer->flush();
    }

    /**
     * Identity for dedupe: the subscription's coalesce field if it names one,
     * otherwise whatever identifies the entity in the extracted payload.
     *
     * Falling back to the serialized payload rather than to "no dedupe" keeps
     * the guarantee meaningful for events that carry no recognisable id.
     *
     * @param array<string, mixed> $eventData
     * @param array<string, mixed> $payload
     */
    private function dedupeKey(
        Subscription $subscription,
        string $eventCode,
        array $eventData,
        array $payload
    ): string {
        if ($subscription->coalesceBy !== null) {
            $value = $this->extractor->resolve($eventData, $subscription->coalesceBy);
            if ($value !== null && !is_array($value)) {
                return $subscription->id . '|' . $eventCode . '|' . $value;
            }
        }

        foreach (['entity_id', 'sku', 'increment_id', 'id'] as $field) {
            if (isset($payload[$field]) && !is_array($payload[$field])) {
                return $subscription->id . '|' . $eventCode . '|' . $payload[$field];
            }
        }

        // No identifier to key on. Deduping by payload content would collapse
        // genuinely different entities whose configured fields all resolved to
        // nothing — one misconfigured subscription would silently drop every
        // event after the first. A counter makes the key unique instead, so a
        // bad field configuration costs duplicates rather than data.
        if ($payload === []) {
            return $subscription->id . '|' . $eventCode . '|#' . (++$this->uncorrelated);
        }

        return $subscription->id . '|' . $eventCode . '|' . md5(json_encode($payload) ?: '');
    }

    /**
     * Magento puts the store id in different places depending on the event, and
     * usually on the entity rather than at the top level of the event data.
     *
     * @param array<string, mixed> $eventData
     */
    private function resolveStoreId(array $eventData): ?int
    {
        foreach (['store_id', 'product.store_id', 'object.store_id', 'data_object.store_id',
                     'order.store_id', 'customer.store_id', 'category.store_id'] as $path) {
            $value = $this->extractor->resolve($eventData, $path);
            if ($value !== null && !is_array($value)) {
                return (int)$value;
            }
        }

        return null;
    }
}
