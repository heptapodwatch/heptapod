<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Api;

use Dedoc\Scramble\Attributes\Group;
use Heptapod\Actions\SnoozeCronTask;
use Heptapod\Concerns\AuthorizesCronTasks;
use Heptapod\Concerns\ResolvesApiPrincipal;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Http\Resources\OhDear\CronTaskResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

#[Group('Cron tasks')]
final class SnoozeCronTaskController extends Controller
{
    use AuthorizesCronTasks;
    use ResolvesApiPrincipal;

    public function __invoke(Request $request, int $cronTask): CronTaskResource
    {
        [$task, $check] = $this->task($cronTask, 'snooze');
        Validator::make($request->all(), ['minutes' => ['required', function (string $attribute, mixed $value, \Closure $fail): void {
            if ((! is_int($value) && ! is_string($value)) || preg_match('/^[1-9][0-9]{0,5}$/D', (string) $value) !== 1 || (int) $value > 525600) {
                $fail(__('api.invalid_minutes'));
            }
        }]])->validate();
        $user = $this->principal($request);
        app(SnoozeCronTask::class)($check, $task->id, $user, $request->integer('minutes'));

        return new CronTaskResource($task);
    }
}
