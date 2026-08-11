<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Events\Model\Capture;

use Magento\Framework\ObjectManagerInterface;
use ReadyData\Events\Api\EventDataProcessorInterface;
use ReadyData\Events\Api\FieldConverterInterface;
use ReadyData\Events\Logger\Logger;

/**
 * Resolves processor and converter class names from subscription rows.
 *
 * ObjectManager directly, because the class names come from the database and
 * cannot be constructor dependencies. Instances are memoised per request: a
 * converter runs once per field per event, and a mass action produces hundreds.
 *
 * The two halves fail in opposite directions, on purpose:
 *
 *  - A **converter** that cannot be resolved **drops the field**. Converters
 *    redact, so failing open would put the raw value on the wire — exactly what
 *    the converter was configured to prevent.
 *  - A **processor** that cannot be resolved is **skipped**. Processors enrich,
 *    so failing closed would throw away a delivery that is merely thinner than
 *    intended, and the subscriber can still re-read.
 */
class ExtensionRegistry
{
    /** @var array<string, EventDataProcessorInterface|false> */
    private array $processors = [];

    /** @var array<string, FieldConverterInterface|false> */
    private array $converters = [];

    public function __construct(
        private readonly ObjectManagerInterface $objectManager,
        private readonly Logger $logger
    ) {
    }

    /**
     * @param string[] $classNames
     * @return EventDataProcessorInterface[] ordered by priority, lowest first
     */
    public function processors(array $classNames): array
    {
        $resolved = [];

        foreach ($classNames as $className) {
            $processor = $this->processor($className);
            if ($processor !== null) {
                $resolved[] = $processor;
            }
        }

        usort(
            $resolved,
            static fn(EventDataProcessorInterface $a, EventDataProcessorInterface $b): int
                => $a->getPriority() <=> $b->getPriority()
        );

        return $resolved;
    }

    public function converter(string $className): ?FieldConverterInterface
    {
        if (array_key_exists($className, $this->converters)) {
            return $this->converters[$className] ?: null;
        }

        $this->converters[$className] = false;
        $instance = $this->instantiate($className, FieldConverterInterface::class, 'converter');

        if ($instance instanceof FieldConverterInterface) {
            $this->converters[$className] = $instance;

            return $instance;
        }

        return null;
    }

    private function processor(string $className): ?EventDataProcessorInterface
    {
        if (array_key_exists($className, $this->processors)) {
            return $this->processors[$className] ?: null;
        }

        $this->processors[$className] = false;
        $instance = $this->instantiate($className, EventDataProcessorInterface::class, 'processor');

        if ($instance instanceof EventDataProcessorInterface) {
            $this->processors[$className] = $instance;

            return $instance;
        }

        return null;
    }

    private function instantiate(string $className, string $contract, string $label): ?object
    {
        if (!class_exists($className)) {
            $this->logger->error(sprintf('Event %s "%s" does not exist.', $label, $className));

            return null;
        }

        try {
            $instance = $this->objectManager->get($className);
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf('Event %s "%s" could not be instantiated: %s', $label, $className, $e->getMessage())
            );

            return null;
        }

        if (!$instance instanceof $contract) {
            $this->logger->error(
                sprintf('Event %s "%s" does not implement %s.', $label, $className, $contract)
            );

            return null;
        }

        return $instance;
    }
}
