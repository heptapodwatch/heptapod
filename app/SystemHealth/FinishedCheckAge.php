<?php

declare(strict_types=1);

namespace Heptapod\SystemHealth;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckRegistry;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** The instance-wide completion signal shared by system issues and the external scheduler heartbeat. */
final readonly class FinishedCheckAge
{
    public function __construct(private CheckRegistry $registry) {}

    /** Null means no completion is expected (maintenance, paused sites, disabled checks, or between scheduled runs). */
    public function seconds(CarbonImmutable $now): ?int
    {
        if (app()->isDownForMaintenance()) {
            return null;
        }
        // A claim advances next_run_at before a worker starts. Keep outstanding and expired leases eligible.
        $dueChecks = DB::table('checks')->join('sites', 'sites.id', '=', 'checks.site_id')
            ->whereNotIn('checks.type', $this->registry->unavailableKeys())
            ->where('checks.enabled', true)->whereNull('sites.paused_at')->whereNull('sites.marked_for_deletion_at')
            ->where(fn (Builder $query): Builder => $query->where('checks.next_run_at', '<=', $now)->orWhereNotNull('checks.lease_expires_at'));
        $finishedAttemptIdSql = <<<'SQL'
            COALESCE((SELECT finished.id FROM check_run_attempts finished
                      WHERE finished.check_id = checks.id AND finished.check_run_id = checks.latest_run_id
                        AND finished.finished_at = checks.latest_run_ended_at LIMIT 1), 0)
            SQL;
        // A completion or terminal close ends a work episode; replacing its lease does not.
        // Both ID lookups stay on this check's ordered range. CASE avoids a repeated global phase-index scan.
        // Retention/import can leave no completed attempt; the completion-time predicate still excludes older history.
        $dueSince = $dueChecks->min(DB::raw(<<<SQL
            COALESCE(
                (SELECT COALESCE(outstanding.due_at, outstanding.requested_at)
                 FROM check_run_attempts outstanding
                 WHERE outstanding.check_id = checks.id
                   AND CASE outstanding.phase
                       WHEN 'queued' THEN TRUE
                       WHEN 'running' THEN TRUE
                       WHEN 'skipped' THEN outstanding.note = 'lease_replaced'
                       ELSE FALSE END
                   AND outstanding.id > COALESCE(
                       (SELECT closed.id FROM check_run_attempts closed
                        WHERE closed.check_id = checks.id AND closed.id > {$finishedAttemptIdSql}
                          AND CASE closed.phase
                              WHEN 'cancelled' THEN TRUE
                              WHEN 'skipped' THEN closed.note <> 'lease_replaced'
                              ELSE FALSE END
                        ORDER BY closed.id DESC LIMIT 1), {$finishedAttemptIdSql})
                   AND (checks.latest_run_ended_at IS NULL OR COALESCE(outstanding.due_at, outstanding.requested_at) > checks.latest_run_ended_at)
                 ORDER BY outstanding.id LIMIT 1),
                LEAST(checks.next_run_at, checks.dispatched_at))
            SQL));
        if ($dueSince === null) {
            return null;
        }
        // The ended_at index bounds this read independently of retained run volume; unfinished attempts do not count.
        $latest = DB::table('check_runs')->orderByDesc('ended_at')->limit(1)->value('ended_at');
        if (! is_string($dueSince) || ($latest !== null && ! is_string($latest))) {
            throw new \UnexpectedValueException('Check completion and due times must be timestamps.');
        }
        $dueAt = CarbonImmutable::parse($dueSince);
        $lastFinished = $latest === null ? null : CarbonImmutable::parse($latest);
        // Sparse checks get a completion window from becoming due, even when their previous run finished a day ago.
        $reference = $lastFinished instanceof CarbonImmutable && $lastFinished > $dueAt ? $lastFinished : $dueAt;

        return max(0, (int) $reference->diffInSeconds($now));
    }
}
