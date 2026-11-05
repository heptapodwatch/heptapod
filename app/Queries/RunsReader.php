<?php

declare(strict_types=1);

namespace Heptapod\Queries;

use Carbon\CarbonImmutable;
use Heptapod\Checks\CauseSummary;
use Heptapod\Checks\CheckRunPresentations;
use Heptapod\Contracts\RunPhase;
use Heptapod\Contracts\RunReference;
use Heptapod\Contracts\RunResult;
use Heptapod\Data\Ui\CheckRunStateData;
use Heptapod\Data\Ui\CurrentRunData;
use Heptapod\Data\Ui\FinishedRunData;
use Heptapod\Data\Ui\RunRowData;
use Heptapod\Data\Ui\RunsBoardData;
use Heptapod\Data\Ui\RunsFilterData;
use Heptapod\Data\Ui\RunsHistoryPageData;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\CheckRunAttempt;
use Heptapod\Models\User;
use Heptapod\Policies\SitePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final readonly class RunsReader
{
    /** Queued cards shown on the board; the rest is a count. */
    private const int BOARD_QUEUED = 20;

    /** Newest finished runs on the board, within the last day; older ones are in the history. */
    private const int BOARD_FINISHED = 20;

    public function __construct(private SitePolicy $policy, private CheckRunsReader $checkRuns, private CauseSummary $summaries, private CheckRunPresentations $presentations) {}

    public function history(User $user, ?int $checkId = null, ?int $before = null, ?int $clientId = null, ?string $type = null, ?RunPhase $phase = null, bool $showSameResult = true, ?CarbonImmutable $since = null): RunsHistoryPageData
    {
        $visible = $this->visibleChecks($user, $checkId, $clientId, $type)->get(['checks.id', 'checks.site_id']);
        $historyIds = array_values($visible->map(static fn (Check $check): int => $check->id)->all());
        $queuePositions = $this->queuePositions();
        $rowsQuery = CheckRunAttempt::query()->with('check.site', 'actor');
        $rowsQuery->getQuery()->whereIn('check_id', $historyIds);
        if ($checkId !== null) {
            $rowsQuery->where('check_id', $checkId);
        }
        if ($since instanceof CarbonImmutable) {
            $rowsQuery->where('requested_at', '>=', $since);
        }
        if ($before !== null) {
            // The cursor shares the period clock and breaks timestamp ties by physical attempt ID.
            // A retention-pruned cursor has no tuple, so its expired page contains no rows.
            $rowsQuery->getQuery()->whereRaw(<<<'SQL'
                (requested_at, id) < (
                    SELECT cursor.requested_at, cursor.id FROM check_run_attempts cursor WHERE cursor.id = ?
                )
                SQL, [$before]);
        }
        if ($phase instanceof RunPhase) {
            $rowsQuery->where('phase', $phase);
        }
        if (! $showSameResult) {
            $rowsQuery->getQuery()->whereRaw('history_result_repeated IS NOT TRUE');
        }
        $rowsQuery->getQuery()->orderByDesc('requested_at')->orderByDesc('id')->limit(51);
        $attempts = $rowsQuery->get();
        $cursor = $attempts->count() > 50 ? $attempts->get(49)?->id : null;
        $runQuery = CheckRun::query()->with('check.site');
        // Attempts carry the physical accepted-run identity, not a globally unique id.
        $runQuery->where(function (Builder $query) use ($attempts): void {
            $query->getQuery()->whereRaw('false');
            foreach ($attempts as $attempt) {
                if ($attempt->check_run_id !== null && $attempt->finished_at !== null) {
                    $reference = CheckRun::forRun(new RunReference($attempt->check_run_id, $attempt->finished_at));
                    $reference->getQuery()->where('check_id', $attempt->check_id);
                    $query->getQuery()->addNestedWhereQuery($reference->getQuery(), 'or');
                }
            }
        });
        $recordedRuns = $runQuery->get()->keyBy(static fn (CheckRun $run): string => $run->id.'|'.$run->ended_at->format('U.u').'|'.$run->check_id);
        $rows = [];
        foreach ($attempts->take(50) as $attempt) {
            $check = $attempt->check;
            $site = $check?->site;
            if ($check === null || $site === null) {
                throw new \LogicException('Run attempt must belong to an existing check and site.');
            }
            $recordedRun = $attempt->check_run_id === null || $attempt->finished_at === null ? null
                : $recordedRuns->get($attempt->check_run_id.'|'.$attempt->finished_at->format('U.u').'|'.$attempt->check_id);
            $summary = $recordedRun === null ? null : $this->summaries->project($check, $recordedRun);
            $attemptNote = str_starts_with($attempt->note ?? '', 'no_result:') ? 'no_result' : $attempt->note;
            $note = $attemptNote === null ? null : __('runs.note.'.($attemptNote === 'lease_replaced' && $attempt->started_at === null ? 'did_not_start' : $attemptNote));
            if ($recordedRun === null && $attempt->check_run_id !== null && $note === null) {
                $note = __('Details not available for this older run');
            }
            if ($note !== null && ! is_string($note)) {
                throw new \LogicException('Run note translation must be a string.');
            }
            $rows[] = new RunRowData($attempt->id, $check->id, $site->id, $site->client_id, $site->displayName(),
                $check->type, $attempt->queue, $this->checkRuns->state($check, $attempt, $queuePositions),
                $attempt->requested_at->toIso8601String(), $attempt->finished_at?->toIso8601String(),
                $attempt->started_at === null || $attempt->finished_at === null ? null : (int) $attempt->started_at->diffInMilliseconds($attempt->finished_at),
                $note, $summary['key'] ?? null, $summary['params'] ?? [], $recordedRun?->result, $site->iconUrl(), $site->icon_backing,
                $this->presentations->forRun($check, $recordedRun));
        }

        return new RunsHistoryPageData($rows, $cursor === null ? null : (string) $cursor);
    }

    public function board(User $user, RunsFilterData $filters): RunsBoardData
    {
        $now = CarbonImmutable::now('UTC');
        $visible = $this->visibleChecks($user, $filters->check, $filters->client, $filters->type?->value)->get();
        $historyIds = array_values($visible->map(static fn (Check $check): int => $check->id)->all());
        $checks = $visible->filter(static fn (Check $check): bool => $check->enabled && $check->site !== null && $check->site->paused_at === null && $check->site->marked_for_deletion_at === null)->keyBy('id');
        $activeQuery = CheckRunAttempt::query()->with('actor');
        $activeQuery->getQuery()->whereIn('check_id', $checks->keys()->all())->whereIn('phase', ['queued', 'running'])->orderBy('requested_at')->orderBy('id');
        $matchingActive = $activeQuery->get()->filter(static fn (CheckRunAttempt $attempt): bool => ! $filters->state instanceof RunPhase || $filters->state === $attempt->phase)->values();
        // The board shows the first queued attempts and every running one; worker pools bound the running set.
        // Select by the instance-wide position inside each pool, then by pool, so every pool's head is shown.
        $positions = $this->queuePositions();
        $queued = $matchingActive->filter(static fn (CheckRunAttempt $attempt): bool => $attempt->phase === RunPhase::Queued)
            ->sort(static fn (CheckRunAttempt $a, CheckRunAttempt $b): int => [$positions[$a->id] ?? PHP_INT_MAX, $a->queue, $a->id] <=> [$positions[$b->id] ?? PHP_INT_MAX, $b->queue, $b->id])
            ->values();
        // One item more than the limit is shown as a card instead of "and 1 more queued".
        $shownQueued = $queued->count() === self::BOARD_QUEUED + 1 ? $queued : $queued->take(self::BOARD_QUEUED);
        $finished = $this->boardFinished($historyIds, $filters->state, $now);
        // Active and finished rows are separate reads; an attempt that finished in between is shown as finished only.
        $finishedAttempts = array_flip(array_filter(array_map(static fn (FinishedRunData $row): ?int => $row->attempt_id, $finished), static fn (?int $id): bool => $id !== null));
        $activeRows = [];
        foreach ($shownQueued->concat($matchingActive->filter(static fn (CheckRunAttempt $attempt): bool => $attempt->phase === RunPhase::Running)) as $attempt) {
            if (isset($finishedAttempts[$attempt->id])) {
                continue;
            }
            $check = $checks->get($attempt->check_id) ?? throw new \LogicException('Active attempt must have a visible check.');
            $activeRows[] = $this->currentRow($check, $this->checkRuns->state($check, $attempt, $positions));
        }

        return new RunsBoardData($activeRows, $queued->count() - $shownQueued->count(), $finished);
    }

    /**
     * The newest finished runs of the last day for the Finished column, each with the attempt that recorded it,
     * so a card keeps its identity from Running to Finished.
     * Index-backed: per check at most BOARD_FINISHED rows from check_runs_check_ended, then a top-N merge;
     * the attempt is the exact match on its physical run reference (check_run_attempts_run_reference).
     * An imported run without an attempt keeps attempt_id null and a run key on the board.
     * Run numbers are unique only with check_id and ended_at (imported runs), so the physical tuple is kept
     * through hydration, which stays inside the visible checks and the selected window.
     *
     * @param  list<int>  $checkIds
     * @return list<FinishedRunData>
     */
    private function boardFinished(array $checkIds, ?RunPhase $phase, CarbonImmutable $now): array
    {
        $results = match ($phase) {
            null => [RunResult::Succeeded, RunResult::Warning, RunResult::Failed, RunResult::Errored],
            RunPhase::Done => [RunResult::Succeeded, RunResult::Warning],
            RunPhase::Failed => [RunResult::Failed, RunResult::Errored],
            default => [],
        };
        if ($checkIds === [] || $results === []) {
            return [];
        }
        $since = $now->subDay()->format('Y-m-d H:i:s.uP');
        $until = $now->format('Y-m-d H:i:s.uP');
        /** @var list<\stdClass&object{id: int, check_id: int, ended_at: string, attempt_id: int|null}> $newest */
        $newest = DB::select(<<<'SQL'
            SELECT newest.id, newest.check_id, newest.ended_at, attempt.id AS attempt_id
            FROM (
                SELECT run.id, run.check_id, run.ended_at
                FROM unnest(?::bigint[]) AS visible(check_id)
                CROSS JOIN LATERAL (
                    SELECT id, check_id, ended_at FROM check_runs
                    WHERE check_runs.check_id = visible.check_id AND ended_at >= ? AND ended_at <= ? AND result = ANY(?::text[])
                    ORDER BY ended_at DESC, id DESC LIMIT ?
                ) AS run
                ORDER BY run.ended_at DESC, run.id DESC LIMIT ?
            ) AS newest
            LEFT JOIN LATERAL (
                SELECT id FROM check_run_attempts
                WHERE check_run_attempts.check_id = newest.check_id AND check_run_attempts.check_run_id = newest.id
                  AND check_run_attempts.finished_at = newest.ended_at
                ORDER BY id DESC LIMIT 1
            ) AS attempt ON true
            ORDER BY newest.ended_at DESC, newest.id DESC
            SQL, ['{'.implode(',', $checkIds).'}', $since, $until, '{'.implode(',', array_map(static fn (RunResult $result): string => $result->value, $results)).'}', self::BOARD_FINISHED, self::BOARD_FINISHED]);
        if ($newest === []) {
            return [];
        }
        $attempts = [];
        $tuples = [];
        $bindings = [];
        foreach ($newest as $row) {
            $endedAt = CarbonImmutable::parse($row->ended_at);
            $attempts[$this->physicalRunKey($row->check_id, $row->id, $endedAt)] = $row->attempt_id;
            $tuples[] = '(?, ?, ?)';
            $bindings[] = $row->check_id;
            $bindings[] = $row->id;
            $bindings[] = $endedAt->format('Y-m-d H:i:s.uP');
        }
        $runQuery = CheckRun::query()->with('check.site')->where('ended_at', '>=', $since)->where('ended_at', '<=', $until);
        $runQuery->getQuery()->whereIn('check_id', $checkIds)
            ->whereRaw('(check_id, id, ended_at) IN ('.implode(', ', $tuples).')', $bindings)
            ->orderByDesc('ended_at')->orderByDesc('id');

        return $this->finishedRows($runQuery->get(), $attempts);
    }

    public function lateCount(User $user): int
    {
        $checkQuery = Check::query()->with('site');
        $checkQuery->getQuery()->whereIn('site_id', $this->policy->activeVisibleSiteIds($user))->where('enabled', true);
        $checks = $checkQuery->get();
        $attemptQuery = CheckRunAttempt::query()->with('actor');
        $attemptQuery->getQuery()->whereIn('check_id', $checks->pluck('id')->all())->whereIn('phase', [RunPhase::Queued, RunPhase::Running]);
        $attempts = $attemptQuery->get()->keyBy('check_id');

        return $checks->filter(fn (Check $check): bool => $this->checkRuns->state($check, $attempts->get($check->id))->phase === RunPhase::Late)->count();
    }

    /** @return Builder<Check> */
    private function visibleChecks(User $user, ?int $checkId, ?int $clientId, ?string $type): Builder
    {
        $query = Check::query()->with('site');
        $query->getQuery()->whereIn('site_id', $this->policy->visibleSiteIds($user));
        if ($checkId !== null) {
            $query->where('id', $checkId);
        }
        if ($clientId !== null) {
            $query->whereHas('site', static fn (Builder $site): Builder => $site->where('client_id', $clientId));
        }
        if ($type !== null) {
            $query->where('type', $type);
        }

        return $query;
    }

    /** A run's physical identity: run numbers alone repeat across checks for imported runs. */
    private function physicalRunKey(int $checkId, int $runId, CarbonImmutable $endedAt): string
    {
        return $checkId.'|'.$runId.'|'.$endedAt->format('U.u');
    }

    /** @param Collection<int, CheckRun> $runs
     * @param  array<string, int|null>  $attempts  the recording attempt per physical run key, where known
     * @return list<FinishedRunData>
     */
    private function finishedRows(Collection $runs, array $attempts = []): array
    {
        $rows = [];
        foreach ($runs as $run) {
            $check = $run->check ?? throw new \LogicException('Finished run must have a check.');
            $site = $check->site ?? throw new \LogicException('Finished run must have a site.');
            // Eager-loaded retained facts keep per-run presentation independent of newer results and row count.
            $summary = $this->summaries->project($check, $run);
            $rows[] = new FinishedRunData($run->id, $check->id, $site->id, $site->displayName(), $run->check_type,
                $run->result, $run->ended_at->format('Y-m-d\TH:i:s.uP'),
                $run->started_at === null ? null : (int) $run->started_at->diffInMilliseconds($run->ended_at),
                $summary['key'], $summary['params'], $this->presentations->forRun($check, $run), $site->iconUrl(), $site->icon_backing, $attempts[$this->physicalRunKey($run->check_id, $run->id, $run->ended_at)] ?? null);
        }

        return $rows;
    }

    /**
     * Instance-wide position of every queued attempt inside its queue (1 = next).
     * Queue order is instance-wide; restricted scopes do not disclose other actors or sites.
     *
     * @return array<int, int>
     */
    private function queuePositions(): array
    {
        $positions = [];
        $counts = [];
        $queuedQuery = CheckRunAttempt::query()->where('phase', RunPhase::Queued);
        $queuedQuery->getQuery()->orderBy('id');
        foreach ($queuedQuery->get(['id', 'queue']) as $queued) {
            $counts[$queued->queue] = ($counts[$queued->queue] ?? 0) + 1;
            $positions[$queued->id] = $counts[$queued->queue];
        }

        return $positions;
    }

    private function currentRow(Check $check, CheckRunStateData $state): CurrentRunData
    {
        $site = $check->site ?? throw new \LogicException('Current run must have a site.');

        return new CurrentRunData($check->id, $site->id, $site->client_id, $site->displayName(), $check->type, $state, $site->iconUrl(), $site->icon_backing);
    }
}
