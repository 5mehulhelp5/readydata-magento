<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Processor;

use ReadyData\Import\Model\BatchContext;

/**
 * A single step of the import pipeline. Processors receive the whole batch
 * and must operate in bulk: no per-product queries or model saves.
 *
 * Register implementations in the pipeline via di.xml
 * (ReadyData\Import\Model\ImportService, argument "processors").
 */
interface ProcessorInterface
{
    /**
     * Process the batch. Runs inside the batch transaction.
     *
     * Per-product problems must be reported via $context->fail()/addMessage()
     * so the rest of the batch survives; throwing rolls back the whole batch.
     *
     * @throws \Throwable on unrecoverable batch-level failure
     */
    public function process(BatchContext $context): void;

    /**
     * Disabled processors are skipped. Placeholder processors return false
     * until implemented.
     */
    public function isEnabled(): bool;

    /**
     * Pipeline position; lower runs first. Core processors use 100..700,
     * leave gaps for third-party insertion.
     */
    public function getSortOrder(): int;
}
