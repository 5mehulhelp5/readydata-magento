<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Model\Capture;

use Magento\Framework\ObjectManagerInterface;
use ReadyData\Events\Api\EventGateInterface;
use ReadyData\Events\Logger\Logger;

/**
 * Resolves a subscription's gate class name to an instance.
 *
 * ObjectManager directly, because the class name comes from a database row and
 * cannot be a constructor dependency. Instances are memoised per request: a gate
 * runs once per matching event and a mass action produces hundreds.
 */
class GateRegistry
{
    /** @var array<string, EventGateInterface|false> */
    private array $gates = [];

    public function __construct(
        private readonly ObjectManagerInterface $objectManager,
        private readonly Logger $logger
    ) {
    }

    /**
     * A gate that cannot be resolved returns null, and the caller treats that as
     * "do not emit". Failing open would send events the gate existed to
     * withhold — a deleted or renamed gate class must not silently turn a
     * filtered subscription into an unfiltered one.
     */
    public function get(string $className): ?EventGateInterface
    {
        if (array_key_exists($className, $this->gates)) {
            return $this->gates[$className] ?: null;
        }

        $this->gates[$className] = false;

        if (!class_exists($className)) {
            $this->logger->error(sprintf('Event gate "%s" does not exist; events for this subscription are dropped.', $className));
            return null;
        }

        try {
            $gate = $this->objectManager->get($className);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Event gate "%s" could not be instantiated: %s', $className, $e->getMessage()));
            return null;
        }

        if (!$gate instanceof EventGateInterface) {
            $this->logger->error(sprintf('Event gate "%s" does not implement %s.', $className, EventGateInterface::class));
            return null;
        }

        $this->gates[$className] = $gate;

        return $gate;
    }
}
