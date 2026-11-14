<?php

declare(strict_types=1);

namespace Heptapod\Addons\Cron;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\CronPingUrl;
use Heptapod\Contracts\RunPersistence;
use Heptapod\Contracts\RunReference;
use Heptapod\Contracts\StaleEvaluation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final readonly class CronPersistence implements RunPersistence
{
    public function __construct(private CronDefinitionRevision $revision, private CronPingUrl $pingUrl) {}

    public function tasksDetail(int $checkId, bool $canViewCapabilities): CronTasksDetailData
    {
        $query = CronCheck::query()->where('check_id', $checkId);
        $query->getQuery()->orderBy('id')->limit(51);
        $tasks = $query->get();
        $visible = $tasks->take(50);
        $ids = $visible->pluck('id')->all();
        $results = DB::table('cron_run_tasks')->where('check_id', $checkId)->whereIn('cron_check_id', $ids)
            ->where('run_ended_at', '>=', CarbonImmutable::now('UTC')->subDays(30)->format('Y-m-d H:i:s.uP'))
            ->orderByDesc('run_ended_at')->orderByDesc('run_id')->orderByDesc('cron_check_id')->limit(1001)->get(['cron_check_id', 'result', 'run_ended_at']);
        $days = [];
        foreach ($results->take(1000) as $row) {
            /** @var \stdClass&object{cron_check_id: int, result: string, run_ended_at: string} $row */
            $day = CarbonImmutable::parse($row->run_ended_at)->utc()->toDateString();
            $days[$row->cron_check_id][$day] ??= ['day' => $day, 'result' => $row->result];
        }
        $rows = [];
        foreach ($visible as $task) {
            $duration = $task->latest_result === CronTaskResult::Finished && is_numeric($task->ping_meta['runtime'] ?? null)
                ? (float) $task->ping_meta['runtime'] : null;
            $rows[] = [
                'id' => $task->id, 'name' => $task->name, 'result' => $task->aggregate_result->value,
                'type' => $task->type, 'cron_expression' => $task->cron_expression, 'timezone' => $task->server_timezone,
                'frequency_minutes' => $task->frequency_in_minutes, 'grace_minutes' => $task->graceMinutes(),
                'last_ping_at' => $task->latest_ping_at?->toIso8601String(), 'last_duration_seconds' => $duration,
                'next_due_at' => $task->next_due_at->toIso8601String(),
                'ping_url' => $canViewCapabilities ? $this->pingUrl->for($task->uuid) : '********',
                'days' => array_values($days[$task->id] ?? []),
            ];
        }

        return new CronTasksDetailData($rows, $tasks->count() > 50, $results->count() > 1000);
    }

    /** @param list<array<string, mixed>> $rows */
    public function stage(CheckContext $context, string $dataset, array $rows): void
    {
        if ($dataset !== 'task_results' || count($rows) < 1 || count($rows) > 1000) {
            throw new \InvalidArgumentException('Cron persistence requires task_results batches of 1..1000 rows.');
        }
        /** @var array{rows: list<array{cron_check_id: int, result: string, ping_revision: int, failure_revision: int, failure_message: string|null, late_by_seconds: int, failure_started_at?: string|null}>} $validated */
        $validated = Validator::make(['rows' => $rows], [
            'rows' => ['required', 'array', 'list'],
            'rows.*' => ['required', 'array:cron_check_id,result,ping_revision,failure_revision,failure_message,late_by_seconds,failure_started_at'],
            'rows.*.cron_check_id' => ['required', 'integer:strict', 'min:1', 'distinct:strict'],
            'rows.*.result' => ['required', 'in:succeeded,warning,failed'],
            'rows.*.ping_revision' => ['required', 'integer:strict', 'min:0'],
            'rows.*.failure_revision' => ['required', 'integer:strict', 'min:0'],
            'rows.*.failure_message' => ['present', 'nullable', 'string', 'max:10000'],
            'rows.*.late_by_seconds' => ['required', 'integer:strict', 'min:0'],
            'rows.*.failure_started_at' => ['sometimes', 'nullable', 'date'],
        ])->validate();
        DB::transaction(function () use ($context, $validated): void {
            DB::table('checks')->where('id', $context->checkId)->lockForUpdate()->firstOrFail();
            if ($context->acceptanceRevision !== null && $this->revision->current($context->checkId) !== $context->acceptanceRevision) {
                throw new StaleEvaluation('Cron definitions changed during evaluation.');
            }
            $ids = array_column($validated['rows'], 'cron_check_id');
            if (DB::table('cron_checks')->where('check_id', $context->checkId)->whereIn('id', $ids)->count() !== count($ids)) {
                throw new \InvalidArgumentException('Cron dataset includes a task outside this check.');
            }

            DB::table('cron_run_tasks')->insert(array_map(static fn (array $row): array => [
                ...$row,
                'failure_started_at' => $row['failure_started_at'] ?? null,
                'run_id' => $context->runId,
                'claim_token' => $context->claimToken,
                'check_id' => $context->checkId,
            ], $validated['rows']));
        });
    }

    public function finalize(RunReference $run, string $claimToken): void
    {
        DB::transaction(function () use ($run, $claimToken): void {
            $rows = DB::table('cron_run_tasks')->where('run_id', $run->id)->where('claim_token', $claimToken)
                ->whereNull('run_ended_at')->orderBy('cron_check_id')->lockForUpdate()->get();
            foreach ($rows as $row) {
                /** @var \stdClass&object{cron_check_id: int, result: string, ping_revision: int, failure_revision: int, failure_started_at: string|null} $row */
                // Acceptance advances the evaluation cursor even if a starting ping changed the ingress revision.
                CronCheck::query()->where('id', $row->cron_check_id)->update([
                    'evaluated_result' => $row->result,
                    'evaluated_failure_at' => $row->failure_started_at,
                    'evaluated_revision' => $row->ping_revision,
                    'evaluated_failure_revision' => $row->failure_revision,
                ]);
                $publication = ['aggregate_result' => $row->result];
                if ($row->result === 'failed') {
                    $task = CronCheck::query()->findOrFail($row->cron_check_id);
                    if ($task->latest_result !== CronTaskResult::Failed) {
                        // A reported failure that a later success superseded is consumed
                        // without hiding that success; only a passed deadline is a miss.
                        if (CarbonImmutable::now()->lessThan($task->next_due_at->addMinutes($task->graceMinutes()))) {
                            continue;
                        }
                        $publication['latest_result'] = CronTaskResult::Missed->value;
                    }
                }
                // Do not overwrite a newer ping's mutable observation.
                CronCheck::query()->where('id', $row->cron_check_id)->where('ping_revision', $row->ping_revision)->update($publication);
            }
            DB::table('cron_run_tasks')->where('run_id', $run->id)->where('claim_token', $claimToken)->whereNull('run_ended_at')
                ->update(['run_ended_at' => $run->endedAt->format('Y-m-d H:i:s.uP')]);
        });
    }

    public function prunedTables(): array
    {
        return ['cron_run_tasks', 'cron_pings'];
    }

    public function prune(CarbonImmutable $before): void
    {
        $baselines = DB::table('cron_run_tasks')->selectRaw('DISTINCT ON (cron_check_id) run_id')
            ->whereNotNull('run_ended_at')->orderBy('cron_check_id')->orderByDesc('run_ended_at')->orderByDesc('run_id');
        DB::table('cron_run_tasks')->where('run_ended_at', '<', $before->format('Y-m-d H:i:s.uP'))->whereNotIn('run_id', $baselines)->delete();
        DB::table('cron_pings')->where('received_at', '<', $before->format('Y-m-d H:i:s.uP'))->delete();
    }

    public function discard(int $runId, string $claimToken): void
    {
        DB::table('cron_run_tasks')->where('run_id', $runId)->where('claim_token', $claimToken)->whereNull('run_ended_at')->delete();
    }
}
