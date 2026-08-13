<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Cache;

use ReadyData\Import\Model\ResourceModel\Category as CategoryResource;

/**
 * The level-1 root categories, read once per request, plus the one question
 * every writer has to ask about them: which root does this path's first segment
 * name?
 *
 * Magento enforces no uniqueness on root names, and two roots sharing one are
 * two different catalogs. That makes the question genuinely ambiguous, and the
 * answer differs by caller: a READ may settle it by taking the lowest entity ID,
 * a WRITE must not, and `root_category_id` — a pin — is what makes such a path
 * writable at all. What does NOT differ is the mechanics: which candidates a
 * name has, whether a pin is a root, and whether it is the root the name claims.
 * That lives here, once, and {@see resolve()} reports it as a neutral outcome so
 * each caller keeps its own vocabulary and its own wording.
 *
 * Every candidate ID per name is kept rather than collapsed: it is the only
 * evidence that a name is ambiguous, and the only way to check a pin against the
 * name it claims.
 */
class RootCategoryRegistry
{
    /** The first segment names exactly one root, or the pin agrees with it. */
    public const OUTCOME_OK = 'ok';
    /** Several roots carry that name and no pin said which. */
    public const OUTCOME_AMBIGUOUS = 'ambiguous';
    /** No root carries that name. */
    public const OUTCOME_UNKNOWN_NAME = 'unknown_name';
    /** The pinned ID is not a root category at all. */
    public const OUTCOME_PIN_NOT_ROOT = 'pin_not_root';
    /** The pinned ID is a root, but not the one the first segment names. */
    public const OUTCOME_PIN_NAME_MISMATCH = 'pin_name_mismatch';

    /**
     * @var array<string, int[]>|null store-0 root name => entity_id[], ascending
     */
    private ?array $idsByName = null;

    public function __construct(
        private readonly CategoryResource $categoryResource
    ) {
    }

    /**
     * Which root a path's first segment names, honouring a pin.
     *
     * A pin that contradicts the name is itself a refusal, whoever is asking:
     * following it would file the category in a catalog the path did not name,
     * and refusing the name would ignore the more specific of the caller's two
     * statements. Neither is a guess worth making on a subtree.
     *
     * `id` is filled in for OUTCOME_OK only, EXCEPT that an ambiguous name still
     * reports its candidates — a read that chooses to take the lowest has them
     * in hand without asking twice.
     *
     * @param bool $refuseAmbiguity what a write passes: several candidates are a
     *        refusal rather than a pick
     * @return array{id: ?int, outcome: string, candidates: int[], pinnedName: ?string}
     */
    public function resolve(string $firstSegment, ?int $pinnedRootId, bool $refuseAmbiguity): array
    {
        $candidates = $this->idsFor($firstSegment);

        if ($pinnedRootId !== null) {
            if (in_array($pinnedRootId, $candidates, true)) {
                return self::outcome(self::OUTCOME_OK, $pinnedRootId, $candidates, $firstSegment);
            }

            $pinnedName = $this->nameOf($pinnedRootId);

            return $pinnedName === null
                ? self::outcome(self::OUTCOME_PIN_NOT_ROOT, null, $candidates, null)
                : self::outcome(self::OUTCOME_PIN_NAME_MISMATCH, null, $candidates, $pinnedName);
        }

        if ($candidates === []) {
            return self::outcome(self::OUTCOME_UNKNOWN_NAME, null, [], null);
        }
        if ($refuseAmbiguity && count($candidates) > 1) {
            return self::outcome(self::OUTCOME_AMBIGUOUS, null, $candidates, null);
        }

        // The lowest ID, deterministically: ascending order comes from the query.
        return self::outcome(self::OUTCOME_OK, $candidates[0], $candidates, $firstSegment);
    }

    /**
     * @return int[] every root carrying this name, ascending
     */
    public function idsFor(string $name): array
    {
        return $this->getIdsByName()[$name] ?? [];
    }

    /**
     * The store-0 name of a root, or null when the ID is not a root at all.
     */
    public function nameOf(int $rootId): ?string
    {
        foreach ($this->getIdsByName() as $name => $ids) {
            if (in_array($rootId, $ids, true)) {
                return $name;
            }
        }

        return null;
    }

    public function isRoot(int $categoryId): bool
    {
        return $this->nameOf($categoryId) !== null;
    }

    /**
     * Drop the memo, for a caller that has just created, renamed, promoted,
     * demoted or deleted a root. Nothing here can detect that on its own, and a
     * stale root map fails a later path with "unknown root category" — so the
     * contract is explicit: whoever changes the level-1 layer says so.
     */
    public function forget(): void
    {
        $this->idsByName = null;
    }

    /**
     * @param int[] $candidates
     * @return array{id: ?int, outcome: string, candidates: int[], pinnedName: ?string}
     */
    private static function outcome(
        string $outcome,
        ?int $id,
        array $candidates,
        ?string $pinnedName
    ): array {
        return ['id' => $id, 'outcome' => $outcome, 'candidates' => $candidates, 'pinnedName' => $pinnedName];
    }

    /**
     * @return array<string, int[]>
     */
    private function getIdsByName(): array
    {
        return $this->idsByName ??= $this->categoryResource->getRootCategoryIds();
    }
}
