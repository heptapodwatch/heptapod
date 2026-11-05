<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Carbon\CarbonImmutable;

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

    /** Maintenance calls in the partition-drop transaction. Prune artifacts ended before this exclusive bound, but NEVER the last accepted addon baseline snapshot or stable component identities. */
    public function prune(CarbonImmutable $before): void;
}
