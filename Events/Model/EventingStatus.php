<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Model;

use Magento\Framework\DataObject\IdentityGeneratorInterface;
use Magento\Framework\Event\ConfigInterface as EventConfig;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;
use ReadyData\Events\Api\Data\EventDescriptionInterface;
use ReadyData\Events\Api\Data\EventDescriptionInterfaceFactory;
use ReadyData\Events\Api\Data\QueueStatusInterface;
use ReadyData\Events\Api\Data\QueueStatusInterfaceFactory;
use ReadyData\Events\Api\EventingStatusInterface;
use ReadyData\Events\Model\Delivery\Dispatcher;
use ReadyData\Events\Model\ResourceModel\Queue;
use ReadyData\Events\Model\Subscriber\SubscriberRepository;
use ReadyData\Events\Model\Subscription\SubscriptionRepository;
use ReadyData\Events\Observer\CaptureObserver;

class EventingStatus implements EventingStatusInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly Catalogue $catalogue,
        private readonly Queue $queue,
        private readonly SubscriberRepository $subscribers,
        private readonly SubscriptionRepository $subscriptions,
        private readonly EventConfig $eventConfig,
        private readonly QueueStatusInterfaceFactory $statusFactory,
        private readonly EventDescriptionInterfaceFactory $descriptionFactory,
        private readonly EventSchema $eventSchema,
        private readonly Dispatcher $dispatcher,
        private readonly IdentityGeneratorInterface $identityGenerator,
        private readonly Json $json
    ) {
    }

    public function getStatus(): QueueStatusInterface
    {
        $counts = $this->queue->statusCounts();
        $subscriber = $this->subscribers->getActive();

        return $this->statusFactory->create()
            ->setEnabled($this->config->isEnabled())
            ->setHooked($this->isHooked())
            ->setInstanceId($this->config->getInstanceId())
            ->setCatalogueSize(count($this->catalogue->codes()))
            ->setSubscriberCode($subscriber?->code)
            ->setSubscriptionCount(count($this->subscriptions->getList()))
            ->setWaiting($counts[Queue::STATUS_WAITING] ?? 0)
            ->setInProgress($counts[Queue::STATUS_IN_PROGRESS] ?? 0)
            ->setSent($counts[Queue::STATUS_SENT] ?? 0)
            ->setFailed($counts[Queue::STATUS_FAILED] ?? 0)
            ->setDeadLettered($counts[Queue::STATUS_DEAD_LETTER] ?? 0)
            ->setOldestWaitingAt($this->queue->getOldestWaitingAt());
    }

    /** @return string[] */
    public function getSupported(): array
    {
        return $this->catalogue->codes();
    }

    public function getSupportedDetail(string $code): EventDescriptionInterface
    {
        $described = $this->eventSchema->describe($code);

        if ($described === null) {
            throw new NoSuchEntityException(__(
                'Event code "%1" is not in this store\'s catalogue. GET supported lists what is.',
                $code
            ));
        }

        return $this->descriptionFactory->create()
            ->setCode($described['code'])
            ->setKind($described['kind'])
            ->setHooked($described['hooked'])
            ->setEntity($described['entity'])
            ->setDerivedFrom($described['derived_from'])
            ->setFields($described['fields'])
            ->setSample($this->json->serialize($described['sample']));
    }

    /**
     * Proves the whole path except capture: envelope, signature, headers,
     * network, and the subscriber's willingness to answer 2xx.
     *
     * Sent directly rather than queued, so the answer is synchronous. Somebody
     * running this is asking "is my endpoint reachable right now", and a reply
     * of "check back after the next cron run" does not answer that.
     */
    public function test(): string
    {
        $subscriber = $this->subscribers->getActive();
        if ($subscriber === null) {
            throw new LocalizedException(__('No subscriber is registered, so there is nowhere to send a test.'));
        }

        $row = [
            'queue_id' => 0,
            'event_id' => $this->identityGenerator->generateId(),
            'event_code' => 'readydata.eventing.test',
            'subscriber_id' => $subscriber->id,
            'payload' => $this->json->serialize([
                'message' => 'ReadyData eventing connectivity test',
                'instance_id' => $this->config->getInstanceId(),
            ]),
            'created_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        ];

        $this->dispatcher->send($subscriber, [$row]);

        return sprintf('Test event delivered to "%s" at %s.', $subscriber->code, $subscriber->endpointUrl);
    }

    /**
     * Whether the generated registrations are actually in the merged event
     * config.
     *
     * An upgrade that skipped generation, or a compile that ran against an older
     * catalogue, leaves a module that is installed, enabled and configured and
     * emits nothing at all. That failure is invisible from every other angle, so
     * the health endpoint answers it directly.
     */
    private function isHooked(): bool
    {
        $names = $this->catalogue->eventNames();
        if ($names === []) {
            return false;
        }

        foreach ($this->eventConfig->getObservers($names[0]) as $observer) {
            if (($observer['instance'] ?? null) === CaptureObserver::class) {
                return true;
            }
        }

        return false;
    }
}
