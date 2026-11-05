<?php

declare(strict_types=1);

namespace Heptapod\Addons\Cron\Actions;

use Carbon\CarbonImmutable;
use Heptapod\Addons\Cron\CronCheck;
use Heptapod\Addons\Cron\CronDefinitionRevision;
use Heptapod\Addons\Cron\CronDefinitions;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final readonly class UpdateCronTask
{
    public function __construct(private CronDefinitions $definitions, private CronDefinitionRevision $revision) {}

    /** @param array<string, mixed> $values */
    public function __invoke(CronCheck $task, array $values, ?int $ohDearId = null): CronCheck
    {
        return DB::transaction(function () use ($task, $values, $ohDearId): CronCheck {
            $this->revision->lock($task->check_id);
            $task->refresh();

            /** @var array<string, mixed> $previous */
            $previous = Arr::only($task->getAttributes(), array_keys(CronDefinitions::schema()->fields));
            if (isset($values['type']) && $values['type'] !== $task->type) {
                $previous['frequency_in_minutes'] = null;
                $previous['cron_expression'] = null;
            }
            $task->fill($this->definitions->validate([...$previous, ...$values]));
            if ($ohDearId !== null) {
                $task->setAttribute('oh_dear_id', $ohDearId);
            }
            if ($task->isDirty(['type', 'cron_expression', 'frequency_in_minutes', 'grace_time_in_minutes', 'server_timezone'])) {
                $task->next_due_at = $task->nextDueAt(CarbonImmutable::now());
            }
            if ($task->isDirty(['name', 'type', 'cron_expression', 'frequency_in_minutes', 'grace_time_in_minutes', 'server_timezone'])) {
                $this->revision->advance($task->check_id);
            }
            $task->save();

            return $task->refresh();
        });
    }
}
