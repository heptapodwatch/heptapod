<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The RunPersistence protocol for an addon table that stages one snapshot row per attempt: rows are keyed by run_id and
 * claim_token, carry check_id, and are accepted once finalize() sets run_ended_at. Table names are addon constants.
 */
final class StagedSnapshots
{
    /**
     * Writes this attempt's row inside its own transaction; an accepted row never changes.
     *
     * @param  array<string, mixed>  $values
     */
    public static function stage(string $table, CheckContext $context, array $values): void
    {
        DB::transaction(static function () use ($table, $context, $values): void {
            $attempt = ['run_id' => $context->runId, 'claim_token' => $context->claimToken];
            $existing = DB::table($table)->where($attempt)->lockForUpdate()->first(['run_ended_at']);
            if ($existing !== null && $existing->run_ended_at !== null) {
                throw new \LogicException("Cannot stage an accepted {$table} row.");
            }
            DB::table($table)->updateOrInsert($attempt, $values);
        });
    }

    /**
     * Accepts this attempt's row and passes the locked row and the stored run end to $publish in the same transaction.
     * Absent staging (an attempt without an observation) and a repeat for the same run are no-ops.
     *
     * @param  (\Closure(\stdClass, string): void)|null  $publish
     */
    public static function finalize(string $table, RunReference $run, string $claimToken, ?\Closure $publish = null): void
    {
        DB::transaction(static function () use ($table, $run, $claimToken, $publish): void {
            $query = DB::table($table)->where('run_id', $run->id)->where('claim_token', $claimToken);
            $row = $query->lockForUpdate()->first();
            if ($row === null) {
                return;
            }
            /** @var \stdClass&object{run_ended_at: string|null} $row */
            if ($row->run_ended_at !== null) {
                if (! CarbonImmutable::parse($row->run_ended_at)->eq($run->endedAt)) {
                    throw new \LogicException("The {$table} attempt was finalized for a different run timestamp.");
                }

                return;
            }
            $endedAt = $run->endedAt->format('Y-m-d H:i:s.uP');
            $query->update(['run_ended_at' => $endedAt]);
            if ($publish instanceof \Closure) {
                $publish($row, $endedAt);
            }
        });
    }

    public static function discard(string $table, int $runId, string $claimToken): void
    {
        DB::table($table)->where('run_id', $runId)->where('claim_token', $claimToken)->whereNull('run_ended_at')->delete();
    }

    /** Deletes one batch of the check's accepted rows, oldest first through the (check_id, run_ended_at, run_id) index, for purgeCheck(). */
    public static function purge(string $table, int $checkId, int $limit): int
    {
        return DB::delete("DELETE FROM {$table} WHERE ctid = ANY(ARRAY(SELECT ctid FROM {$table}
            WHERE check_id = ? AND run_ended_at IS NOT NULL ORDER BY run_ended_at, run_id LIMIT ?))", [$checkId, $limit]);
    }
}
