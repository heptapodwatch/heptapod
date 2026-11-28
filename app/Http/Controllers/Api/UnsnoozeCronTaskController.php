<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Api;

use Dedoc\Scramble\Attributes\Group;
use Heptapod\Actions\AuthorizeSiteWrite;
use Heptapod\Actions\UnsnoozeCronTask;
use Heptapod\Concerns\AuthorizesCronTasks;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Http\Resources\OhDear\CronTaskResource;
use Heptapod\Models\User;
use Illuminate\Container\Attributes\CurrentUser;

#[Group('Cron tasks')]
final class UnsnoozeCronTaskController extends Controller
{
    use AuthorizesCronTasks;

    public function __invoke(#[CurrentUser] User $user, int $cronTask, AuthorizeSiteWrite $authorize): CronTaskResource
    {
        [$task, $check] = $this->task($cronTask, 'snooze');
        $authorize($user, 'snooze', static fn () => app(UnsnoozeCronTask::class)($check, $task->id, $user), $check);

        return new CronTaskResource($task);
    }
}
