<?php

declare(strict_types=1);

namespace Heptapod\Addons\Cron;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\CronTaskData;
use Heptapod\Contracts\CronTaskStore;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class DatabaseCronTaskStore implements CronTaskStore
{
    public function __construct(private Actions\CreateCronTask $create, private Actions\UpdateCronTask $update, private Actions\DeleteCronTask $delete, private Actions\SyncCronTasks $sync) {}

    public function find(int $id): CronTaskData
    {
        return $this->data(CronCheck::query()->findOrFail($id));
    }

    public function list(int $checkId): array
    {
        return $this->listForChecks([$checkId]);
    }

    public function listForChecks(array $checkIds): array
    {
        $query = CronCheck::query();
        $query->getQuery()->whereIn('check_id', $checkIds)->orderBy('id');

        return array_values($query->get()->map(fn (CronCheck $task): CronTaskData => $this->data($task))->all());
    }

    public function create(int $checkId, array $values): CronTaskData
    {
        return $this->data(($this->create)($checkId, $values));
    }

    public function update(int $id, array $values): CronTaskData
    {
        $query = CronCheck::query()->whereKey($id);

        return $this->data(($this->update)($query->firstOrFail(), $values));
    }

    public function delete(int $id): void
    {
        $query = CronCheck::query()->whereKey($id);
        ($this->delete)($query->firstOrFail());
    }

    public function recordPing(string $uuid, string $phase, array $values): void
    {
        DB::transaction(function () use ($uuid, $phase, $values): void {
            $query = CronCheck::query()->where('uuid', $uuid);
            $query->getQuery()->lockForUpdate();
            $task = $query->firstOrFail();
            $at = CarbonImmutable::now();
            $task->setAttribute('latest_ping_at', $at);
            $task->ping_revision++;

            if ($phase === 'starting') {
                $task->setAttribute('started_at', $at);
            } elseif ($phase === 'failed' || (int) ($values['exit_code'] ?? 0) !== 0) {
                $task->latest_result = CronTaskResult::Failed;
                $task->aggregate_result = CheckResult::Failed;
                $task->failure_revision++;
                $task->setAttribute('ping_meta', $values);
            } else {
                $task->late_by_seconds = max(0, (int) $task->next_due_at->diffInSeconds($at, false));
                $task->latest_result = CronTaskResult::Finished;
                $task->aggregate_result = $at->greaterThan($task->next_due_at) && $at->lessThan($task->next_due_at->addMinutes($task->graceMinutes()))
                    ? CheckResult::Warning
                    : CheckResult::Succeeded;
                $task->setAttribute('finished_at', $at);
                // An unevaluated failure keeps its message so the next evaluation still reports it.
                $task->setAttribute('ping_meta', $task->failure_revision > $task->evaluated_failure_revision
                    ? [...$values, 'failure_message' => $task->ping_meta['failure_message'] ?? null] : $values);
                $task->next_due_at = $task->nextDueAt($at);
            }
            $task->save();
            DB::table('cron_pings')->insert([
                'cron_check_id' => $task->id,
                'phase' => $phase,
                'ping_revision' => $task->ping_revision,
                'result' => $task->latest_result->value,
                'meta' => json_encode($values, JSON_THROW_ON_ERROR),
                'received_at' => $at->format('Y-m-d H:i:s.uP'),
            ]);
        });
    }

    public function rotate(int $id): CronTaskData
    {
        return DB::transaction(function () use ($id): CronTaskData {
            $query = CronCheck::query()->whereKey($id);
            $query->getQuery()->lockForUpdate();
            $task = $query->firstOrFail();
            $task->uuid = (string) Str::uuid();
            $task->save();

            return $this->data($task);
        });
    }

    public function sync(int $checkId, array $definitions): array
    {
        return array_map($this->data(...), ($this->sync)($checkId, $definitions));
    }

    private function data(CronCheck $task): CronTaskData
    {
        /** @var array<string, mixed> $definition */
        $definition = Arr::only($task->getAttributes(), array_keys(CronDefinitions::schema()->fields));

        return new CronTaskData($task->id, $task->check_id, $task->uuid, $definition,
            $task->latest_result->value, $task->created_at, $task->latest_ping_at);
    }
}
