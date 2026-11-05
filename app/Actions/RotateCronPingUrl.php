<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Contracts\CronTaskStore;
use Heptapod\Models\Check;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class RotateCronPingUrl
{
    public function __construct(private CronTaskStore $tasks) {}

    public function __invoke(User $actor, Check $check, int $taskId): void
    {
        DB::transaction(function () use ($actor, $check, $taskId): void {
            $task = $this->tasks->find($taskId);
            abort_unless($task->checkId === $check->id, 404);
            $this->tasks->rotate($taskId);
            activity('audit')->causedBy($actor)->performedOn($check)->event('cron.ping_url_rotated')
                ->withProperties(['cron_task_id' => $taskId])->log('cron.ping_url_rotated');
        });
    }
}
