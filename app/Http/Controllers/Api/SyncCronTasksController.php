<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Api;

use Dedoc\Scramble\Attributes\Group;
use Heptapod\Actions\AuthorizeSiteWrite;
use Heptapod\Actions\SyncCronTasks;
use Heptapod\Concerns\AuthorizesCronTasks;
use Heptapod\Concerns\ResolvesApiPrincipal;
use Heptapod\Contracts\CronTaskData;
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
    use ResolvesApiPrincipal;

    public function __invoke(Request $request, Site $monitor, AuthorizeSiteWrite $authorize): JsonResponse
    {
        $this->store();
        $tasks = $authorize($this->principal($request), 'update', static function () use ($request, $monitor): array {
            /** @var array{cron_checks: list<array<string, mixed>>} $values */
            $values = Validator::make($request->all(), ['cron_checks' => ['present', 'array', 'list', 'max:1000'], 'cron_checks.*' => ['required', 'array']])->validate();

            /** @var list<CronTaskData> */
            return app(SyncCronTasks::class)($monitor, $values['cron_checks']);
        }, $monitor);

        return response()->json(CronTaskResource::collection($tasks)->resolve());
    }
}
