<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Processor;

use ReadyData\Import\Model\BatchContext;

/**
 * A step that has resources to release once the batch has finished, whichever
 * way it finished — but only once it has REACHED its transaction.
 *
 * A batch that fails earlier gets neither call. There are two such exits, both
 * after the unlocked acquisition phase has already downloaded files:
 * prepare() itself failing, and the batch being turned away because it cannot
 * take its locks. That is deliberate rather than overlooked — ImportService
 * documents the trade at processBatch(): acquisition is unlocked and first so a
 * competing import waits for one transaction rather than a feed's worth of
 * downloads, and the files a rejected request leaves behind are the ones its
 * retry re-uses, since FileResolver maps a URL to a deterministic path. Anything
 * a retry never comes back for is left to the orphan report.
 *
 * The fourth opt-in extension to {@see ProcessorInterface}, alongside
 * {@see PreparableInterface}, {@see LockAwareInterface} and
 * {@see LockedPreparableInterface}. Today only MediaProcessor implements it: a
 * file it downloaded or detached outlives the transaction either way, because a
 * filesystem has no rollback.
 *
 * Both methods run OUTSIDE the transaction and AFTER the locks are released.
 * That is the only moment either is safe — the commit or the rollback has
 * already decided what the database says, so a file deleted here cannot end up
 * contradicting it.
 *
 * NEITHER MAY THROW INTO THE IMPORT. By the time these run the batch's real work
 * is over: after a commit the products are saved and visible, and after a
 * rollback there is already an error being reported that must not be replaced by
 * a tidying-up failure. ImportService guards both calls, but an implementation
 * should handle its own failures and log them rather than rely on that.
 *
 * Deliberately NOT hung off the batch events. ImportEventDispatcher skips
 * everything after the commit when product events are switched off, which is a
 * setting about third-party observers; cleanup is this module's own business and
 * must not disappear because someone quietened the event layer.
 */
interface BatchCleanupInterface
{
    /**
     * The batch committed. Anything the database no longer references is now
     * genuinely unreferenced and may be released.
     */
    public function cleanUpAfterCommit(BatchContext $context): void;

    /**
     * The batch rolled back. Nothing it wrote survives, so anything it acquired
     * outside the transaction is now unreachable and may be released.
     */
    public function cleanUpAfterRollback(BatchContext $context): void;
}
