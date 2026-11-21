<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Api;

use Dedoc\Scramble\Attributes\Group;
use Heptapod\Actions\AuthorizeSiteWrite;
use Heptapod\Actions\CreateCronTask;
use Heptapod\Actions\DeleteCronTask;
use Heptapod\Actions\UpdateCronTask;
use Heptapod\Concerns\AuthorizesCronTasks;
use Heptapod\Concerns\ResolvesApiPrincipal;
use Heptapod\Contracts\CronTaskData;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Http\Resources\OhDear\CronTaskResource;
use Heptapod\Models\Check;
use Heptapod\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

#[Group('Cron tasks')]
final class CronTaskController extends Controller
{
    use AuthorizesCronTasks;
    use ResolvesApiPrincipal;

    public function index(Site $monitor): JsonResponse
    {
        $check = $this->parent($monitor, 'view');

        return response()->json(['data' => CronTaskResource::collection($check instanceof Check ? $this->store()->list($check->id) : [])->resolve()]);
    }

    public function create(Request $request, Site $monitor, AuthorizeSiteWrite $authorize): JsonResponse
    {
        $this->parent($monitor, 'update');
        /** @var array<string, mixed> $values */
        $values = $request->all();
        $task = $authorize($this->principal($request), 'update', static fn (): CronTaskData => app(CreateCronTask::class)($monitor, $values), $monitor);

        return new CronTaskResource($task)->response()->setStatusCode(201);
    }

    public function update(Request $request, int $cronTask, AuthorizeSiteWrite $authorize): CronTaskResource
    {
        [$task, $check] = $this->task($cronTask, 'update');
        /** @var array<string, mixed> $values */
        $values = $request->all();

        return new CronTaskResource($authorize($this->principal($request), 'update',
            static fn (): CronTaskData => app(UpdateCronTask::class)($check, $task->id, $values), $check));
    }

    public function destroy(Request $request, int $cronTask, AuthorizeSiteWrite $authorize): Response
    {
        [$task, $check] = $this->task($cronTask, 'update');
        $authorize($this->principal($request), 'update', static fn () => app(DeleteCronTask::class)($check, $task->id), $check);

        return response()->noContent();
    }
}
