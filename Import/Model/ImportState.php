<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model;

/**
 * Tracks whether a ReadyData import is currently running.
 *
 * Shared (singleton) state, set by {@see ImportService} for the whole
 * duration of an import. The observer-suppression plugins under Plugin/
 * consult it so the native product-save observers stand down only while the
 * importer owns the writes; outside an import they behave normally.
 *
 * A counter (not a bool) makes re-entrancy harmless: nested/overlapping
 * enter() calls only leave the "importing" state once every matching
 * leave() has run.
 */
class ImportState
{
    private int $depth = 0;

    public function enter(): void
    {
        $this->depth++;
    }

    public function leave(): void
    {
        if ($this->depth > 0) {
            $this->depth--;
        }
    }

    public function isImporting(): bool
    {
        return $this->depth > 0;
    }
}
