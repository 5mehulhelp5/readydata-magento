<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Processor;

use ReadyData\Import\Logger\Logger;
use ReadyData\Import\Model\BatchContext;

/**
 * Base class for pipeline steps that are planned but not implemented yet.
 * They are registered in di.xml and disabled here; implementing a feature
 * means writing execute() and returning true from isEnabled().
 */
abstract class AbstractPlaceholderProcessor implements ProcessorInterface
{
    public function __construct(
        protected readonly Logger $logger
    ) {
    }

    final public function process(BatchContext $context): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        $this->execute($context);
    }

    /**
     * The actual batch logic once the feature is implemented.
     */
    protected function execute(BatchContext $context): void
    {
        $this->logger->debug(sprintf('%s is enabled but has no implementation yet.', static::class));
    }

    public function isEnabled(): bool
    {
        return false;
    }
}
