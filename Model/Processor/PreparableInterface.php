<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Processor;

use ReadyData\Import\Model\BatchContext;

/**
 * Optional capability for pipeline steps that must touch the network or the
 * filesystem before any row is written.
 *
 * ImportService calls prepare() on every enabled processor implementing this
 * interface, in getSortOrder() order, BEFORE the batch transaction is opened, so
 * remote I/O never happens while the batch holds write locks on the catalog
 * tables. This is a separate, opt-in interface rather than a method on
 * ProcessorInterface: that contract is the documented third-party extension
 * point, and every existing step would have to grow an empty implementation.
 *
 * A step stashes what it acquired on the BatchContext data bag and consumes it
 * again in process(); it must not keep per-batch state on itself, because
 * processors are shared DI instances reused across batches. prepare() must also
 * be idempotent: a batch that rolls back may be retried with the same payload,
 * and whatever prepare() wrote outside the database is not rolled back with it.
 *
 * Per-product problems are recorded with $context->addMessage()/fail(), as in
 * process(). Throwing fails the whole batch — but before any transaction exists,
 * so it is reported without a rollback.
 */
interface PreparableInterface
{
    /**
     * Acquire external resources for the batch. Runs OUTSIDE the transaction.
     */
    public function prepare(BatchContext $context): void;
}
