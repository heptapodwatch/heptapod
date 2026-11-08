<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Api;

use Dedoc\Scramble\Attributes\Group;
use Heptapod\Actions\SyncCronTasks;
use Heptapod\Concerns\AuthorizesCronTasks;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Http\Resources\OhDear\CronTaskResource;
use Heptapod\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

#[Group('Cron tasks')]
final class SyncCronTasksController extends Controller
{
    use AuthorizesCronTasks;

    public function __invoke(Request $request, Site $monitor): JsonResponse
    {
        $this->parent($monitor, 'update');
        /** @var array{cron_checks: list<array<string, mixed>>} $values */
        $values = Validator::make($request->all(), ['cron_checks' => ['present', 'array', 'list', 'max:1000'], 'cron_checks.*' => ['required', 'array']])->validate();
        $tasks = app(SyncCronTasks::class)($monitor, $values['cron_checks']);

        return response()->json(CronTaskResource::collection($tasks)->resolve());
    }
}
