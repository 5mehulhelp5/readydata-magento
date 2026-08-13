<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use ReadyData\Events\Api\Data\SubscriberInterface;
use ReadyData\Events\Api\Data\SubscriberInterfaceFactory;
use ReadyData\Events\Api\SubscriberManagementInterface;
use ReadyData\Events\Model\Subscriber\SubscriberRepository;

class SubscriberManagement implements SubscriberManagementInterface
{
    public function __construct(
        private readonly SubscriberRepository $repository,
        private readonly SubscriberInterfaceFactory $subscriberFactory
    ) {
    }

    public function get(): SubscriberInterface
    {
        $subscriber = $this->repository->getActive();
        if ($subscriber === null) {
            throw new NoSuchEntityException(__('No subscriber is registered.'));
        }

        // Secret deliberately omitted: it is readable exactly once, at registration.
        return $this->subscriberFactory->create()
            ->setCode($subscriber->code)
            ->setEndpointUrl($subscriber->endpointUrl)
            ->setEnabled($subscriber->enabled)
            ->setMaxBatchSize($subscriber->maxBatchSize);
    }

    public function register(SubscriberInterface $subscriber): SubscriberInterface
    {
        $code = trim((string)$subscriber->getCode());
        $endpoint = trim((string)$subscriber->getEndpointUrl());

        if ($code === '') {
            throw new LocalizedException(__('A subscriber code is required.'));
        }

        $this->assertDeliverableUrl($endpoint);

        $registered = $this->repository->register(
            $code,
            $endpoint,
            $subscriber->getSecret(),
            $subscriber->getMaxBatchSize() ?? 100
        );

        return $this->subscriberFactory->create()
            ->setCode($registered->code)
            ->setEndpointUrl($registered->endpointUrl)
            ->setSecret($registered->secret)
            ->setEnabled($registered->enabled)
            ->setMaxBatchSize($registered->maxBatchSize);
    }

    public function delete(string $code): bool
    {
        $this->repository->getByCode($code);
        $this->repository->delete($code);

        return true;
    }

    /**
     * Rejecting a malformed endpoint at registration turns a silent, permanent
     * delivery failure — discovered days later as a queue that only grows — into
     * an error at the moment somebody can still fix it.
     */
    private function assertDeliverableUrl(string $url): void
    {
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new LocalizedException(__('"%1" is not a valid endpoint URL.', $url));
        }

        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new LocalizedException(__('The endpoint URL must be http or https.'));
        }
    }
}
