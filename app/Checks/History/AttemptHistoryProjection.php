<?php

declare(strict_types=1);

namespace Heptapod\Checks\History;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class AttemptHistoryProjection
{
    public function backfill(): void
    {
        /** @var int|null $lastCheck */
        $lastCheck = DB::table('checks')->max('id');
        if ($lastCheck === null) {
            return;
        }
        foreach (DB::table('checks')->where('id', '<=', $lastCheck)->orderBy('id')->lazyById(100) as $check) {
            /** @var \stdClass&object{id: int} $check */
            /** @var int|null $lastAttempt */
            $lastAttempt = DB::table('check_run_attempts')->where('check_id', $check->id)->max('id');
            if ($lastAttempt === null) {
                continue;
            }
            $after = 0;
            do {
                $batch = DB::transaction(function () use ($check, $after, $lastAttempt): object {
                    // Same lock as the recorder: a batch cannot overwrite a concurrent finish or successor repair.
                    DB::table('checks')->where('id', $check->id)->lockForUpdate()->first();
                    // Take the run fence before ordered attempt locks, matching partition repair's lock order.
                    DB::statement('LOCK TABLE check_runs IN ACCESS SHARE MODE');
                    /** @var object{last_id: int|null, total: int} $batch */
                    $batch = DB::selectOne(<<<'SQL'
                        WITH batch AS MATERIALIZED (
                            SELECT id, check_id, check_run_id, finished_at, phase FROM check_run_attempts
                            WHERE check_id = ? AND id > ? AND id <= ? ORDER BY id LIMIT 1000 FOR UPDATE
                        ), states AS MATERIALIZED (
                            SELECT id, attempt_history_result_repeated(check_id, check_run_id, finished_at, phase) AS repeated
                            FROM batch
                        ), updated AS (
                            UPDATE check_run_attempts attempts SET history_result_repeated = states.repeated
                            FROM states WHERE attempts.id = states.id
                                AND attempts.history_result_repeated IS DISTINCT FROM states.repeated
                        )
                        SELECT max(id) AS last_id, count(*) AS total FROM batch
                        SQL, [$check->id, $after, $lastAttempt]);

                    return $batch;
                }, 3);
                $after = $batch->last_id ?? $lastAttempt;
            } while ($batch->total === 1000);
        }
    }

    /** @param list<int> $checkIds */
    public function repairAfterPruning(array $checkIds, CarbonImmutable $from, CarbonImmutable $until): void
    {
        // The caller holds the parent run table's exclusive lock across DROP and repair, not check row locks.
        $affected = '{'.implode(',', $checkIds).'}';
        DB::statement(<<<'SQL'
            UPDATE check_run_attempts SET history_result_repeated = NULL
            WHERE check_id = ANY(?::bigint[]) AND finished_at >= ? AND finished_at < ?
                AND history_result_repeated IS NOT NULL
            SQL, [$affected, $from->format('Y-m-d H:i:s.uP'), $until->format('Y-m-d H:i:s.uP')]);
        DB::statement(<<<'SQL'
            WITH successors AS MATERIALIZED (
                SELECT observed.check_id, observed.id, observed.ended_at
                FROM unnest(?::bigint[]) AS affected(check_id)
                CROSS JOIN LATERAL (
                    SELECT check_id, id, ended_at FROM check_runs
                    WHERE check_id = affected.check_id AND ended_at >= ?
                    ORDER BY ended_at, id LIMIT 1
                ) observed
            )
            UPDATE check_run_attempts attempts SET history_result_repeated = attempt_history_result_repeated(
                attempts.check_id, attempts.check_run_id, attempts.finished_at, attempts.phase)
            FROM successors WHERE attempts.check_id = successors.check_id AND attempts.check_run_id = successors.id
                AND attempts.finished_at = successors.ended_at AND attempts.phase IN ('done', 'failed')
            SQL, [$affected, $until->format('Y-m-d H:i:s.uP')]);
    }
}
