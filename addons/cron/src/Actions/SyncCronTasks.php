<?php

declare(strict_types=1);

namespace Heptapod\Addons\Cron\Actions;

use Heptapod\Addons\Cron\CronCheck;
use Heptapod\Addons\Cron\CronDefinitionRevision;
use Heptapod\Addons\Cron\CronDefinitions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final readonly class SyncCronTasks
{
    public function __construct(private CronDefinitions $definitions, private CreateCronTask $create, private UpdateCronTask $update, private DeleteCronTask $delete, private CronDefinitionRevision $revision) {}

    /** @param list<array<string, mixed>> $definitions
     * @return list<CronCheck>
     */
    public function __invoke(int $checkId, array $definitions): array
    {
        Validator::make(['cron_checks' => $definitions], [
            'cron_checks' => ['present', 'array', 'list', 'max:1000'],
            'cron_checks.*.name' => ['required', 'string', 'distinct:strict'],
        ])->validate();
        foreach ($definitions as $definition) {
            $this->definitions->validate($definition);
        }

        return DB::transaction(function () use ($checkId, $definitions): array {
            $this->revision->lock($checkId);
            $query = CronCheck::query()->where('check_id', $checkId);
            $query->getQuery()->orderBy('id')->lockForUpdate();
            $existing = $query->get();
            $tasks = [];
            foreach ($definitions as $definition) {
                $task = $existing->firstWhere('name', '===', $definition['name']);
                $tasks[] = $task === null ? ($this->create)($checkId, $definition) : ($this->update)($task, $definition);
            }
            $ids = array_map(static fn (CronCheck $task): int => $task->id, $tasks);
            foreach ($existing as $task) {
                if (! in_array($task->id, $ids, true)) {
                    ($this->delete)($task);
                }
            }

            return $tasks;
        });
    }
}
