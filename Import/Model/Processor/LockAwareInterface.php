<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Processor;

use ReadyData\Import\Model\BatchContext;

/**
 * Opt-in for a pipeline step that can perform an **unkeyed read-then-create**
 * and therefore needs one of {@see \ReadyData\Import\Model\ImportLocks}.
 *
 * The step declares what it needs for THIS batch, before the locks are taken.
 * Declaring lives here — next to the create it guards — rather than in the
 * orchestrator, for two reasons:
 *
 * - a predicate written anywhere else drifts from the code it protects, and the
 *   symptom of drift is a duplicate row rather than a test failure;
 * - it makes the lock set extensible in the same way the pipeline is: a
 *   third-party step that creates something unkeyed declares its own name here
 *   instead of the orchestrator having to know about it.
 *
 * **Answer precisely.** The point of asking per batch is that a batch which
 * creates nothing should take nothing and run concurrently with everything
 * else — that is the common case for a steady-state feed, and measurement put
 * the saving at roughly 300 ms of hold and 570 ms of somebody else's wait per
 * batch on the category lock alone. So probe: read what already exists and
 * answer for what is actually missing, rather than for what the payload could
 * in principle reach. {@see requiredLocks()} runs OUTSIDE the locks and outside
 * the transaction, so a read here is cheap, and the caches it warms are the
 * ones the step needs later anyway.
 *
 * **The probe can go stale**, because it necessarily reads before the lock:
 * something can be deleted between the probe and the transaction, turning a
 * resolve into a create the batch never reserved a lock for. A step MUST NOT
 * create in that case — that is the race the lock exists for. It consults
 * {@see BatchContext::holdsLock()} at write time and degrades instead: report
 * the product, skip the create, let the retry (whose probe now sees the gap)
 * take the lock and do it.
 */
interface LockAwareInterface
{
    /**
     * Which locks this step could actually reach for this batch.
     *
     * Called after {@see PreparableInterface::prepare()} and before any lock is
     * taken, on a live connection, with the context in the state
     * {@see ProcessorInterface::process()} will see. Order does not matter; the
     * orchestrator sorts the union into
     * {@see \ReadyData\Import\Model\ImportLocks::inAcquisitionOrder()}.
     *
     * @return string[] lock names from {@see \ReadyData\Import\Model\ImportLocks},
     *         empty when this batch cannot create anything unkeyed
     */
    public function requiredLocks(BatchContext $context): array;
}
