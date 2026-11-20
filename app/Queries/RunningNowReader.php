<?php

declare(strict_types=1);

namespace Heptapod\Queries;

use Carbon\CarbonImmutable;
use Heptapod\Checks\Execution\ExecutionBudget;
use Heptapod\Contracts\RunResult;
use Heptapod\Data\Ui\RunningBackupData;
use Heptapod\Data\Ui\RunningCheckRunData;
use Heptapod\Data\Ui\RunningImportData;
use Heptapod\Data\Ui\RunningNowData;
use Heptapod\Enums\ImportRunStatus;
use Heptapod\Models\CheckRun;
use Heptapod\Models\CheckRunAttempt;
use Heptapod\Models\ImportRun;
use Heptapod\Models\User;
use Heptapod\Policies\SitePolicy;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Manual work in flight that the viewer may see: check runs a person started, Oh Dear imports and backups. Scheduled runs
 * never appear: they are the engine's heartbeat and live on Runs.
 * The read is bounded and backed by partial indexes over manual attempts only (check_run_attempts_manual_active,
 * check_run_attempts_manual_finished), so a backlog of scheduled runs adds no work.
 */
final readonly class RunningNowReader
{
    /** Runs sent to every page; the footer shows three and counts the rest. */
    private const int LIMIT = 20;

    /** How long the viewer's own finished run stays in the shell with its result. */
    public const int OWN_RESULT_SECONDS = 60;

    public function __construct(private SitePolicy $policy, private CheckRunsReader $checkRuns) {}

    public function forUser(User $user): RunningNowData
    {
        $since = CarbonImmutable::now('UTC')->subSeconds(self::OWN_RESULT_SECONDS)->format('Y-m-d H:i:s.uP');
        // The candidates come from the manual-only indexes first; visibility and the current claim are then checked per
        // candidate through checks' primary key, so the work follows manual runs, never the visible checks or the backlog.
        // A leftover of a replaced lease is not the check's current run (the cast matches RunsReader::lateCount).
        /** @var list<\stdClass&object{id: int, active_total: int}> $picked */
        $picked = DB::select(<<<'SQL'
            WITH candidate AS MATERIALIZED (
                SELECT id, check_id, claim_token, phase, actor_user_id, requested_at, finished_at FROM check_run_attempts
                WHERE origin = 'manual' AND (phase IN ('queued', 'running')
                    OR (actor_user_id = ? AND finished_at >= ?::timestamptz AND check_run_id IS NOT NULL))
            )
            SELECT candidate.id, count(*) FILTER (WHERE candidate.phase IN ('queued', 'running')) OVER () AS active_total
            FROM candidate
            -- LIMIT keeps the lateral lookup a primary-key probe per candidate instead of a scan of every visible check.
            CROSS JOIN LATERAL (SELECT site_id, claim_token FROM checks WHERE checks.id = candidate.check_id LIMIT 1) AS checked
            WHERE checked.site_id = ANY(?::bigint[])
                AND (candidate.phase NOT IN ('queued', 'running') OR candidate.claim_token = checked.claim_token::text)
            ORDER BY candidate.finished_at IS NULL, coalesce(candidate.actor_user_id = ?, false) DESC, candidate.requested_at, candidate.id
            LIMIT ?
            SQL, [$user->id, $since, '{'.implode(',', $this->policy->visibleSiteIds($user)).'}', $user->id, self::LIMIT]);
        $attempts = CheckRunAttempt::query()->with('check.site', 'actor')->findMany(array_map(static fn (\stdClass $row): int => $row->id, $picked))->keyBy('id');
        $results = $this->results(array_values($attempts->filter(static fn (CheckRunAttempt $attempt): bool => $attempt->finished_at !== null)->all()), $since);
        $rows = array_map(fn (\stdClass $row): RunningCheckRunData => $this->row($attempts->get($row->id)
            ?? throw new \LogicException('A picked run must load.'), $user, $results), $picked);

        return new RunningNowData($rows, $picked === [] ? 0 : $picked[0]->active_total,
            $user->hasAnyRole(['Owner', 'Admin']) ? $this->imports($user, $since) : [], $user->hasRole('Owner') ? $this->backup($user, $since) : null);
    }

    /**
     * The recorded results of the viewer's finished runs in one read, pruned to the result window's partitions.
     *
     * @param  list<CheckRunAttempt>  $finished
     * @return array<int, RunResult> by attempt id
     */
    private function results(array $finished, string $since): array
    {
        if ($finished === []) {
            return [];
        }
        $tuples = [];
        $bindings = [];
        foreach ($finished as $attempt) {
            $tuples[] = '(?, ?, ?)';
            array_push($bindings, $attempt->check_id, $attempt->check_run_id, $attempt->finished_at?->format('Y-m-d H:i:s.uP'));
        }
        $runs = CheckRun::query()->where('ended_at', '>=', $since);
        $runs->getQuery()->whereRaw('(check_id, id, ended_at) IN ('.implode(', ', $tuples).')', $bindings);
        $byRun = [];
        foreach ($runs->get(['check_id', 'id', 'ended_at', 'result']) as $run) {
            $byRun[$run->check_id.'|'.$run->id] = $run->result;
        }
        $results = [];
        foreach ($finished as $attempt) {
            $results[$attempt->id] = $byRun[$attempt->check_id.'|'.$attempt->check_run_id] ?? throw new \LogicException('A finished run must have its recorded result.');
        }

        return $results;
    }

    /**
     * Imports are Owner/Admin work (AuthorizesSettingsOperator). Never `results`: one row per monitor, too large for every page.
     * import_runs_active and import_runs_user_finished keep the read off the finished history.
     *
     * @return list<RunningImportData>
     */
    private function imports(User $user, string $since): array
    {
        $query = ImportRun::query();
        $query->getQuery()->select(['id', 'user_id', 'status', 'attempts', 'created_at', 'started_at', 'finished_at'])
            ->where(static fn (QueryBuilder $active): QueryBuilder => $active->whereIn('status', [ImportRunStatus::Queued->value, ImportRunStatus::Running->value])
                ->orWhere(static fn (QueryBuilder $own): QueryBuilder => $own->where('user_id', $user->id)->where('finished_at', '>=', $since)
                    ->whereIn('status', [ImportRunStatus::Completed->value, ImportRunStatus::Incomplete->value, ImportRunStatus::Failed->value])))
            ->orderByRaw('finished_at IS NULL')->orderBy('created_at')->limit(5);

        return array_values(array_map(static fn (ImportRun $run): RunningImportData => new RunningImportData($run->id, $run->attempts, $run->status, $run->user_id === $user->id,
            $run->created_at->toIso8601String(), $run->started_at?->toIso8601String(), $run->finished_at?->toIso8601String()), $query->get()->all()));
    }

    /** Backups are Owner work (RequestBackup); at most one is in flight. */
    private function backup(User $user, string $since): ?RunningBackupData
    {
        // A running backup past the retry window is stale; RequestBackup fails it on the next request, so it is not shown.
        $stale = CarbonImmutable::now('UTC')->subSeconds(ExecutionBudget::RetryAfter)->format('Y-m-d H:i:s.uP');
        $iso = static fn (?string $at): ?string => $at === null ? null : CarbonImmutable::parse($at)->toIso8601String();

        return DB::table('backup_runs')->where(static fn (QueryBuilder $query): QueryBuilder => $query->where('state', 'queued')
            ->orWhere(static fn (QueryBuilder $running): QueryBuilder => $running->where('state', 'running')->where('started_at', '>', $stale))
            ->orWhere(static fn (QueryBuilder $own): QueryBuilder => $own->where('requested_by', $user->id)->where('completed_at', '>=', $since)))
            ->orderByRaw('completed_at IS NULL DESC')->orderByDesc('id')->limit(1)
            ->get(['id', 'requested_by', 'state', 'requested_at', 'started_at', 'completed_at'])
            ->map(static function (\stdClass $row) use ($user, $iso): RunningBackupData {
                /** @var \stdClass&object{id: int, requested_by: int|null, state: string, requested_at: string, started_at: string|null, completed_at: string|null} $row */
                return new RunningBackupData($row->id, $row->state, $row->requested_by === $user->id,
                    CarbonImmutable::parse($row->requested_at)->toIso8601String(), $iso($row->started_at), $iso($row->completed_at));
            })->first();
    }

    /** @param array<int, RunResult> $results */
    private function row(CheckRunAttempt $attempt, User $user, array $results): RunningCheckRunData
    {
        $check = $attempt->check ?? throw new \LogicException('A run must have a check.');
        $site = $check->site ?? throw new \LogicException('A run must have a site.');

        // No queue position: counting it reads the whole queue, scheduled backlog included, on every page.
        return new RunningCheckRunData($check->id, $site->id, $site->displayName(), $check->type, $this->checkRuns->state($check, $attempt, withQueuePosition: false),
            $attempt->actor_user_id === $user->id, $results[$attempt->id] ?? null, $attempt->finished_at?->format('Y-m-d\TH:i:s.uP'));
    }
}
