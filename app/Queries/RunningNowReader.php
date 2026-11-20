<?php

declare(strict_types=1);

namespace Heptapod\Queries;

use Carbon\CarbonImmutable;
use Heptapod\Checks\Execution\ExecutionBudget;
use Heptapod\Checks\Execution\RunOrigin;
use Heptapod\Contracts\RunReference;
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
    /** Runs sent to every page; the footer shows three, the site strip filters by site. */
    private const int LIMIT = 20;

    /** How long the viewer's own finished run stays in the shell with its result. */
    public const int OWN_RESULT_SECONDS = 60;

    public function __construct(private SitePolicy $policy, private CheckRunsReader $checkRuns) {}

    public function forUser(User $user): RunningNowData
    {
        $since = CarbonImmutable::now('UTC')->subSeconds(self::OWN_RESULT_SECONDS)->format('Y-m-d H:i:s.uP');
        $query = CheckRunAttempt::query()->with('check.site', 'actor');
        $query->getQuery()->select('check_run_attempts.*')->join('checks', 'checks.id', '=', 'check_run_attempts.check_id')
            ->where('check_run_attempts.origin', RunOrigin::Manual->value)
            ->whereIntegerInRaw('checks.site_id', $this->policy->visibleSiteIds($user))
            // One read: the active runs (a leftover of a replaced lease is not the check's current run; the cast matches
            // RunsReader::lateCount) and the viewer's own recorded results of the last minute, counted apart.
            ->whereRaw(<<<'SQL'
                ((check_run_attempts.phase IN ('queued', 'running') AND check_run_attempts.claim_token = checks.claim_token::text)
                    OR (check_run_attempts.actor_user_id = ? AND check_run_attempts.finished_at >= ?::timestamptz AND check_run_attempts.check_run_id IS NOT NULL))
                SQL, [$user->id, $since])
            ->selectRaw("count(*) FILTER (WHERE check_run_attempts.phase IN ('queued', 'running')) OVER () AS active_total")
            ->orderByRaw('check_run_attempts.finished_at IS NULL')
            ->orderByRaw('coalesce(check_run_attempts.actor_user_id = ?, false) DESC', [$user->id])
            ->orderBy('check_run_attempts.requested_at')->orderBy('check_run_attempts.id')
            ->limit(self::LIMIT);
        $attempts = $query->get()->all();
        $activeTotal = $attempts === [] ? 0 : $attempts[0]->getAttribute('active_total');
        if (! is_int($activeTotal)) {
            throw new \LogicException('The active run count must be an integer.');
        }

        return new RunningNowData(array_values(array_map(fn (CheckRunAttempt $attempt): RunningCheckRunData => $this->row($attempt, $user), $attempts)), $activeTotal,
            $user->hasAnyRole(['Owner', 'Admin']) ? $this->imports($user, $since) : [], $user->hasRole('Owner') ? $this->backup($user, $since) : null);
    }

    /**
     * Imports are Owner/Admin work (AuthorizesSettingsOperator). Never `results`: one row per monitor, too large for every page.
     * import_runs_active keeps the in-flight read off finished runs.
     *
     * @return list<RunningImportData>
     */
    private function imports(User $user, string $since): array
    {
        $query = ImportRun::query();
        $query->getQuery()->select(['id', 'user_id', 'status', 'created_at', 'started_at', 'finished_at'])
            ->where(static fn (QueryBuilder $active): QueryBuilder => $active->whereIn('status', [ImportRunStatus::Queued->value, ImportRunStatus::Running->value])
                ->orWhere(static fn (QueryBuilder $own): QueryBuilder => $own->where('user_id', $user->id)->where('finished_at', '>=', $since)
                    ->whereIn('status', [ImportRunStatus::Completed->value, ImportRunStatus::Incomplete->value, ImportRunStatus::Failed->value])))
            ->orderByRaw('finished_at IS NULL')->orderBy('created_at')->limit(5);

        return array_values(array_map(static fn (ImportRun $run): RunningImportData => new RunningImportData($run->id, $run->status, $run->user_id === $user->id,
            $run->created_at->toIso8601String(), $run->started_at?->toIso8601String(), $run->finished_at?->toIso8601String()), $query->get()->all()));
    }

    /** Backups are Owner work (RequestBackup); at most one is in flight. */
    private function backup(User $user, string $since): ?RunningBackupData
    {
        // A running backup past the retry window is stale; RequestBackup fails it on the next request, so it is not shown.
        $stale = CarbonImmutable::now('UTC')->subSeconds(ExecutionBudget::RetryAfter)->format('Y-m-d H:i:s.uP');
        /** @var (\stdClass&object{id: int, requested_by: int|null, state: string, requested_at: string, started_at: string|null, completed_at: string|null})|null $row */
        $row = DB::table('backup_runs')->where(static fn (QueryBuilder $query): QueryBuilder => $query->where('state', 'queued')
            ->orWhere(static fn (QueryBuilder $running): QueryBuilder => $running->where('state', 'running')->where('started_at', '>', $stale))
            ->orWhere(static fn (QueryBuilder $own): QueryBuilder => $own->where('requested_by', $user->id)->where('completed_at', '>=', $since)))
            ->orderByRaw('completed_at IS NULL DESC')->orderByDesc('id')->first(['id', 'requested_by', 'state', 'requested_at', 'started_at', 'completed_at']);
        $iso = static fn (?string $at): ?string => $at === null ? null : CarbonImmutable::parse($at)->toIso8601String();

        return $row === null ? null : new RunningBackupData($row->id, $row->state, $row->requested_by === $user->id,
            CarbonImmutable::parse($row->requested_at)->toIso8601String(), $iso($row->started_at), $iso($row->completed_at));
    }

    private function row(CheckRunAttempt $attempt, User $user): RunningCheckRunData
    {
        $check = $attempt->check ?? throw new \LogicException('A run must have a check.');
        $site = $check->site ?? throw new \LogicException('A run must have a site.');
        $result = $attempt->check_run_id !== null && $attempt->finished_at !== null
            ? CheckRun::forRun(new RunReference($attempt->check_run_id, $attempt->finished_at))->where('check_id', $check->id)->firstOrFail(['result'])->result
            : null;

        return new RunningCheckRunData($check->id, $site->id, $site->displayName(), $check->type, $this->checkRuns->state($check, $attempt),
            $attempt->actor_user_id === $user->id, $result, $attempt->finished_at?->format('Y-m-d\TH:i:s.uP'));
    }
}
