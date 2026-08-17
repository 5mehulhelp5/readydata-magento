<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Processor;

use ReadyData\Import\Model\BatchContext;

/**
 * Optional capability for pipeline steps that must write through code of their
 * own — a repository, a model — which opens its own database transaction.
 *
 * ImportService calls prepareUnderLocks() on every enabled processor
 * implementing this interface, in getSortOrder() order, AFTER the batch's locks
 * have been acquired and BEFORE its transaction is opened. That window is the
 * whole point of the interface, and it is narrow on purpose:
 *
 * - **inside the locks**, because the work is still a read-then-create whose
 *   miss-read and whose COMMIT both have to be covered — releasing in between
 *   is the race itself;
 * - **outside the transaction**, because Magento's adapter counts nested
 *   transactions instead of emitting savepoints. A nested rollBack() writes no
 *   SQL at all: it flags the connection and decrements. So a repository save
 *   that fails inside the batch transaction leaves its partial rows live, and
 *   the batch's own COMMIT then dies with "Partial rollback is not supported"
 *   instead of the real cause. Run outside, the same save resolves cleanly on
 *   its own and its failure can be reported against the product that caused it.
 *
 * This is NOT the interface for work that merely wants to happen early —
 * {@see PreparableInterface} is that one, and it runs earlier still, outside the
 * locks, which is where network and filesystem work belongs. Anything slow added
 * here is slow while the batch's whole lock set is held, so a competing import
 * pays for it. Keep this phase to the create it exists for.
 *
 * The contract otherwise matches process(): stash what was resolved on the
 * BatchContext data bag rather than on $this (processors are shared DI
 * instances), record per-product problems with $context->addMessage()/fail(),
 * and consult {@see \ReadyData\Import\Model\BatchContext::holdsLock()} before
 * creating, because the lock decision came from a read taken before the lock.
 *
 * Two things are NOT available yet. Entity IDs do not exist — EntityProcessor
 * writes them inside the transaction — so a step here cannot know which products
 * will actually land. And a throw fails the whole batch, but before any
 * transaction exists, so it is reported without a rollback.
 */
interface LockedPreparableInterface
{
    /**
     * Do the batch's transaction-incompatible creates. Runs under the batch's
     * locks, OUTSIDE its transaction.
     */
    public function prepareUnderLocks(BatchContext $context): void;
}
