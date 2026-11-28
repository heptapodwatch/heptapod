<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

interface RunPersistence
{
    /**
     * Stage bounded batches (1..1000 rows) in addon-owned tables keyed by runId AND claimToken.
     * Dataset names are owned/validated by the addon, never arbitrary SQL table names.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function stage(CheckContext $context, string $dataset, array $rows): void;

    /** Recorder calls inside its transaction, ONLY after locking/checking ownership. Absent staging is a valid no-op; repeat calls with the same physical run and token are idempotent. */
    public function finalize(RunReference $run, string $claimToken): void;

    /** Remove this attempt's unpublished artifacts without touching an accepted attempt. Absent staging and repeated calls with the same run ID and token are valid no-ops. */
    public function discard(int $runId, string $claimToken): void;

    /**
     * Tables raw retention prunes before it drops a run partition, batched by core (AddonHistoryRetention). Keep the last
     * accepted addon baseline snapshot through a baseline; stable component identities are never listed.
     *
     * @return list<HistoryPrune>
     */
    public function historyPrunes(): array;

    /** @return list<string> Tables whose rows prune() removes, including rows removed by foreign-key cascades; database growth projects them with the raw result retention. */
    public function prunedTables(): array;

    /**
     * Before a paused site is deleted, PurgeDeletedSite shrinks its history with this under the check's lock, one batch per
     * transaction: delete at most $limit finished rows of the check's history from the prunedTables(), children before
     * their parents, through an index on the check. Returns the rows deleted; 0 once none are left. A run still in flight
     * and small per-check rows (baselines, identities) stay for DeleteSite's cascade; rows of a table no foreign key ties to
     * the check have no cascade, so those all go here except what a live claim still stages. A check type whose addon
     * stores history implements this; a type without RunPersistence keeps its history in the core tables only.
     */
    public function purgeCheck(int $checkId, int $limit): int;
}
