<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Api;

use Dedoc\Scramble\Attributes\Group;
use Heptapod\Actions\AuthorizeSiteWrite;
use Heptapod\Actions\UnsnoozeCronTask;
use Heptapod\Concerns\AuthorizesCronTasks;
use Heptapod\Concerns\ResolvesApiPrincipal;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Http\Resources\OhDear\CronTaskResource;
use Illuminate\Http\Request;

#[Group('Cron tasks')]
final class UnsnoozeCronTaskController extends Controller
{
    use AuthorizesCronTasks;
    use ResolvesApiPrincipal;

    public function __invoke(Request $request, int $cronTask, AuthorizeSiteWrite $authorize): CronTaskResource
    {
        [$task, $check] = $this->task($cronTask);
        $user = $this->principal($request);
        $authorize($user, 'snooze', static fn () => app(UnsnoozeCronTask::class)($check, $task->id, $user), $check);

        return new CronTaskResource($task);
    }
}
