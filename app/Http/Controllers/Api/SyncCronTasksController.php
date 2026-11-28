<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Api;

use Dedoc\Scramble\Attributes\Group;
use Heptapod\Actions\AuthorizeSiteWrite;
use Heptapod\Actions\SyncCronTasks;
use Heptapod\Concerns\AuthorizesCronTasks;
use Heptapod\Contracts\CronTaskData;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Http\Resources\OhDear\CronTaskResource;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

#[Group('Cron tasks')]
final class SyncCronTasksController extends Controller
{
    use AuthorizesCronTasks;

    public function __invoke(#[CurrentUser] User $user, Request $request, Site $monitor, AuthorizeSiteWrite $authorize): JsonResponse
    {
        $this->parent($monitor, 'update');
        /** @var array{cron_checks: list<array<string, mixed>>} $values */
        $values = Validator::make($request->all(), ['cron_checks' => ['present', 'array', 'list', 'max:1000'], 'cron_checks.*' => ['required', 'array']])->validate();
        /** @var list<CronTaskData> $tasks */
        $tasks = $authorize($user, 'update', static fn (): array => app(SyncCronTasks::class)($monitor, $values['cron_checks']), $monitor);

        return response()->json(CronTaskResource::collection($tasks)->resolve());
    }
}
