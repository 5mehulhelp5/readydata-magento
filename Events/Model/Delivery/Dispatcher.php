<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Model\Delivery;

use Magento\Framework\DataObject\IdentityGeneratorInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use ReadyData\Events\Logger\Logger;
use ReadyData\Events\Model\Config;
use ReadyData\Events\Model\ResourceModel\Queue;
use ReadyData\Events\Model\Subscriber\Subscriber;
use ReadyData\Events\Model\Subscriber\SubscriberRepository;

/**
 * Claims queued events and POSTs them to the subscriber.
 *
 * Claim-then-send, not select-then-send: the claim is a single UPDATE that
 * stamps a token, so two cron nodes cannot both pick up the same event. That
 * matters more than it looks on a clustered store, where the alternative is
 * every event delivered twice.
 */
class Dispatcher
{
    /** A claim older than this is assumed abandoned and returned to the queue. */
    private const STALE_CLAIM_SECONDS = 900;

    public function __construct(
        private readonly Queue $queue,
        private readonly SubscriberRepository $subscribers,
        private readonly EnvelopeBuilder $envelopeBuilder,
        private readonly Signer $signer,
        private readonly Config $config,
        private readonly Curl $curl,
        private readonly Json $json,
        private readonly IdentityGeneratorInterface $identityGenerator,
        private readonly Logger $logger
    ) {
    }

    /**
     * @return array{claimed: int, sent: int, failed: int, reclaimed: int}
     */
    public function dispatch(): array
    {
        $result = ['claimed' => 0, 'sent' => 0, 'failed' => 0, 'reclaimed' => 0];

        if (!$this->config->isEnabled()) {
            return $result;
        }

        $subscriber = $this->subscribers->getActive();
        if ($subscriber === null || !$subscriber->enabled) {
            return $result;
        }

        // A dispatcher killed mid-flight leaves rows claimed forever; without
        // this they are never retried and look identical to "nothing to send".
        $result['reclaimed'] = $this->queue->releaseStaleClaims(self::STALE_CLAIM_SECONDS);

        $lockToken = $this->identityGenerator->generateId();
        $limit = min($this->config->getDeliveryBatchSize(), $subscriber->maxBatchSize);

        $result['claimed'] = $this->queue->claimBatch($lockToken, $limit, $this->config->getMaxRetries());
        if ($result['claimed'] === 0) {
            return $result;
        }

        $rows = $this->queue->fetchClaimed($lockToken);
        if ($rows === []) {
            return $result;
        }

        $queueIds = array_map(static fn(array $row): int => (int)$row['queue_id'], $rows);

        try {
            $this->send($subscriber, $rows);
            $this->queue->markSent($queueIds);
            $result['sent'] = count($queueIds);
        } catch (\Throwable $e) {
            $this->queue->markFailed(
                $queueIds,
                $e->getMessage(),
                $this->config->getBackoffSeconds(),
                $this->config->getMaxRetries()
            );
            $result['failed'] = count($queueIds);
            $this->logger->error(
                sprintf('Delivery of %d event(s) to "%s" failed: %s', count($queueIds), $subscriber->code, $e->getMessage())
            );
        }

        return $result;
    }

    /**
     * Sends one batch. Throws on anything that is not a 2xx, because the caller
     * distinguishes success from failure by exception and a silent non-2xx would
     * mark undelivered events as sent.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    public function send(Subscriber $subscriber, array $rows): void
    {
        $body = $this->json->serialize($this->envelopeBuilder->build($rows));
        $deliveryId = $this->identityGenerator->generateId();

        $headers = array_merge($subscriber->headers, [
            'Content-Type' => 'application/json',
            'X-ReadyData-Instance' => $this->config->getInstanceId(),
            'X-ReadyData-Delivery-Id' => $deliveryId,
            Signer::HEADER => $this->signer->sign($body, $subscriber->secret),
        ]);

        $this->curl->setTimeout($this->config->getTimeout());
        foreach ($headers as $name => $value) {
            $this->curl->addHeader((string)$name, (string)$value);
        }

        $this->curl->post($subscriber->endpointUrl, $body);

        $status = $this->curl->getStatus();
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(sprintf(
                'HTTP %d from %s: %s',
                $status,
                $subscriber->endpointUrl,
                mb_substr(trim((string)$this->curl->getBody()), 0, 500)
            ));
        }
    }
}
