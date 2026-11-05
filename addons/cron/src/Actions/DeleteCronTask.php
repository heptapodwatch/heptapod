<?php

declare(strict_types=1);

namespace Heptapod\Addons\Cron\Actions;

use Heptapod\Addons\Cron\CronCheck;
use Heptapod\Addons\Cron\CronDefinitionRevision;
use Illuminate\Support\Facades\DB;

final readonly class DeleteCronTask
{
    public function __construct(private CronDefinitionRevision $revision) {}

    public function __invoke(CronCheck $task): void
    {
        DB::transaction(function () use ($task): void {
            $this->revision->advance($task->check_id);
            $task->refresh();

            $task->delete();
        });
    }
}
