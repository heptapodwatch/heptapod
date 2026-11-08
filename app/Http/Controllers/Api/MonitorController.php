<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Api;

use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Group;
use Heptapod\Actions\CreateSite;
use Heptapod\Actions\DeleteSite;
use Heptapod\Actions\UpdateSite;
use Heptapod\Checks\NoResultFreshness;
use Heptapod\Concerns\ResolvesApiPrincipal;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Http\Requests\Api\PaginationRequest;
use Heptapod\Http\Resources\OhDear\MonitorResource;
use Heptapod\Http\Resources\OhDear\PaginatedCollection;
use Heptapod\Models\Client;
use Heptapod\Models\Site;
use Heptapod\Policies\SitePolicy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

#[Group('Monitors')]
final class MonitorController extends Controller
{
    use ResolvesApiPrincipal;

    public function index(PaginationRequest $request, SitePolicy $policy, NoResultFreshness $noResultFreshness): JsonResponse
    {
        $user = $this->principal($request);
        Validator::make($request->all(), ['filter' => ['sometimes', 'array:team_id'], 'filter.team_id' => ['sometimes', 'integer', 'in:1']])->validate();
        $query = $policy->visibleSites($user)->with(['checks' => static function (Relation $checks): void {
            $checks->getQuery()->getQuery()->orderBy('id');
        }, 'checks.snoozes', 'tags', 'client']);
        $query->getQuery()->orderBy('id');
        $paginator = $query->paginate($request->pageSize(), ['*'], $request->pageParameter(), $request->pageNumber())->appends($request->except($request->pageParameter() === 'page' ? 'page' : 'page.number'));

        $checks = new Collection($paginator->getCollection()->flatMap(static fn (Site $site) => $site->checks)->all());
        $times = MonitorResource::responseTimes($paginator->getCollection());
        $freshness = $noResultFreshness->snapshot($checks, CarbonImmutable::now('UTC'));
        $paginator->setCollection($paginator->getCollection()->map(fn (Site $site): MonitorResource => new MonitorResource($site, $times, $freshness)));

        return new PaginatedCollection($paginator, MonitorResource::class)->response($request);
    }

    public function show(Site $monitor): MonitorResource
    {
        Gate::authorize('view', $monitor);

        return new MonitorResource($monitor->load(['checks' => static function (Relation $checks): void {
            $checks->getQuery()->getQuery()->orderBy('id');
        }, 'checks.snoozes', 'tags', 'client']));
    }

    public function store(Request $request, CreateSite $create): JsonResponse
    {
        Validator::make($request->all(), ['team_id' => ['required', 'integer', 'in:1'], 'type' => ['required', 'in:http']])->validate();
        $clientId = Client::query()->where('is_internal', true)->firstOrFail()->id;
        Gate::authorize('create', [Site::class, $clientId]);
        /** @var array<string, mixed> $attributes */
        $attributes = $request->all();
        $site = $create($clientId, $attributes);

        return new MonitorResource($site->load(['checks' => static function (Relation $checks): void {
            $checks->getQuery()->getQuery()->orderBy('id');
        }, 'checks.snoozes', 'tags', 'client']))->response()->setStatusCode(201);
    }

    public function update(Request $request, Site $monitor, UpdateSite $update): MonitorResource
    {
        Gate::authorize('update', $monitor);
        /** @var array<string, mixed> $attributes */
        $attributes = $request->all();
        $site = $update($monitor, $attributes);

        return new MonitorResource($site->load(['checks' => static function (Relation $checks): void {
            $checks->getQuery()->getQuery()->orderBy('id');
        }, 'checks.snoozes', 'tags', 'client']));
    }

    public function destroy(Site $monitor, DeleteSite $delete): Response
    {
        Gate::authorize('delete', $monitor);
        $delete($monitor);

        return response()->noContent();
    }
}
