<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Api;

use Dedoc\Scramble\Attributes\Group;
use Heptapod\Actions\AuthorizeSiteWrite;
use Heptapod\Actions\SnoozeCronTask;
use Heptapod\Concerns\AuthorizesCronTasks;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Http\Resources\OhDear\CronTaskResource;
use Heptapod\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

#[Group('Cron tasks')]
final class SnoozeCronTaskController extends Controller
{
    use AuthorizesCronTasks;

    public function __invoke(#[CurrentUser] User $user, Request $request, int $cronTask, AuthorizeSiteWrite $authorize): CronTaskResource
    {
        [$task, $check] = $this->task($cronTask, 'snooze');
        Validator::make($request->all(), ['minutes' => ['required', function (string $attribute, mixed $value, \Closure $fail): void {
            if ((! is_int($value) && ! is_string($value)) || preg_match('/^[1-9][0-9]{0,5}$/D', (string) $value) !== 1 || (int) $value > 525600) {
                $fail(__('api.invalid_minutes'));
            }
        }]])->validate();
        $minutes = $request->integer('minutes');
        $authorize($user, 'snooze', static fn () => app(SnoozeCronTask::class)($check, $task->id, $user, $minutes), $check);

        return new CronTaskResource($task);
    }
}
