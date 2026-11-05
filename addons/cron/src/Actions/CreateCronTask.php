<?php

declare(strict_types=1);

namespace Heptapod\Addons\Cron\Actions;

use Carbon\CarbonImmutable;
use Heptapod\Addons\Cron\CronCheck;
use Heptapod\Addons\Cron\CronDefinitionRevision;
use Heptapod\Addons\Cron\CronDefinitions;
use Heptapod\Addons\Cron\CronTaskResult;
use Heptapod\Contracts\CheckResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class CreateCronTask
{
    public function __construct(private CronDefinitions $definitions, private CronDefinitionRevision $revision) {}

    /** The caller authorizes and resolves the parent cron check before entering the addon.
     * @param  array<string, mixed>  $values
     */
    public function __invoke(int $checkId, array $values): CronCheck
    {
        return DB::transaction(function () use ($checkId, $values): CronCheck {
            $this->revision->advance($checkId);

            if ($checkId < 1) {
                throw new \InvalidArgumentException('A cron definition requires a positive parent check id.');
            }

            $validated = $this->definitions->validate($values);
            $task = new CronCheck($validated);
            $task->check_id = $checkId;
            $task->uuid = (string) Str::uuid();
            $task->latest_result = CronTaskResult::Pending;
            $task->aggregate_result = CheckResult::Pending;
            $task->next_due_at = $task->nextDueAt(CarbonImmutable::now());
            $task->save();

            return $task->refresh();
        });
    }
}
