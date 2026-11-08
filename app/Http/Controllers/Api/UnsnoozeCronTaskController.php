<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Api;

use Dedoc\Scramble\Attributes\Group;
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

    public function __invoke(Request $request, int $cronTask): CronTaskResource
    {
        [$task, $check] = $this->task($cronTask, 'snooze');
        $user = $this->principal($request);
        app(UnsnoozeCronTask::class)($check, $task->id, $user);

        return new CronTaskResource($task);
    }
}
