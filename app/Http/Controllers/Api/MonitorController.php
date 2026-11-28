<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Api;

use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Group;
use Heptapod\Actions\AuthorizeSiteWrite;
use Heptapod\Actions\CreateSite;
use Heptapod\Actions\RequestSiteDeletion;
use Heptapod\Actions\UpdateSite;
use Heptapod\Actions\UpdateSiteSettings;
use Heptapod\Checks\NoResultFreshness;
use Heptapod\Contracts\Http\PaginatedCollection;
use Heptapod\Contracts\Http\PaginationRequest;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Http\Resources\OhDear\MonitorResource;
use Heptapod\Models\Client;
use Heptapod\Models\Site;
use Heptapod\Models\SiteRepository;
use Heptapod\Models\User;
use Heptapod\Policies\SitePolicy;
use Heptapod\Support\RepositoryUrl;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

#[Group('Monitors')]
final class MonitorController extends Controller
{
    public function index(#[CurrentUser] User $user, PaginationRequest $request, SitePolicy $policy, NoResultFreshness $noResultFreshness): JsonResponse
    {
        Validator::make($request->all(), ['filter' => ['sometimes', 'array:team_id,repository_url'], 'filter.team_id' => ['sometimes', 'integer', 'in:1'],
            'filter.repository_url' => ['sometimes', 'string', 'max:'.RepositoryUrl::MaxLength, RepositoryUrl::rule()]])->validate();
        $query = $policy->visibleSites($user)->with(['checks' => static function (Relation $checks): void {
            $checks->getQuery()->getQuery()->orderBy('id');
        }, 'checks.snoozes', 'tags', 'client', 'repositories']);
        $repositoryUrl = $request->input('filter.repository_url');
        if (is_string($repositoryUrl)) {
            $linked = SiteRepository::query()->getQuery()->select('site_id')->where('url', RepositoryUrl::canonical($repositoryUrl));
            $query->getQuery()->whereIn('sites.id', $linked);
        }
        $query->getQuery()->orderBy('id');
        $paginator = $request->paginate($query);

        $checks = new Collection($paginator->getCollection()->flatMap(static fn (Site $site) => $site->checks)->all());
        $times = MonitorResource::responseTimes($paginator->getCollection());
        $freshness = $noResultFreshness->snapshot($checks, CarbonImmutable::now('UTC'));
        $servers = MonitorResource::servers($request, $paginator->getCollection());
        $paginator->setCollection($paginator->getCollection()->map(fn (Site $site): MonitorResource => new MonitorResource($site, $times, $freshness, $servers)));

        return new PaginatedCollection($paginator, MonitorResource::class)->response($request);
    }

    public function show(Site $monitor): MonitorResource
    {
        Gate::authorize('view', $monitor);

        return new MonitorResource($monitor->load(['checks' => static function (Relation $checks): void {
            $checks->getQuery()->getQuery()->orderBy('id');
        }, 'checks.snoozes', 'tags', 'client', 'repositories']));
    }

    public function store(#[CurrentUser] User $user, Request $request, CreateSite $create, SitePolicy $policy): JsonResponse
    {
        /** @var array{client_id?: int|null} $data */
        $data = Validator::make($request->all(), ['team_id' => ['required', 'integer', 'in:1'], 'type' => ['required', 'in:http'],
            // Omitted or null: No client. Any other value must be filled, since a blank string skips the non-implicit rules after it.
            'client_id' => [$request->input('client_id') === null ? 'nullable' : 'filled', 'integer:strict', $policy->visibleClientRule($user, includeArchived: true)]])->validate();
        $client = isset($data['client_id']) ? Client::query()->findOrFail($data['client_id']) : Client::query()->where('is_internal', true)->firstOrFail();
        // Before the policy, which refuses an archived client with a bare 403.
        if ($client->archived_at !== null) {
            throw ValidationException::withMessages(['client_id' => __('clients.archived_cannot_add_site')]);
        }
        Gate::authorize('create', [Site::class, $client->id]);
        /** @var array<string, mixed> $attributes */
        $attributes = $request->except('client_id');
        $site = $create($client->id, $attributes);

        return new MonitorResource($site->load(['checks' => static function (Relation $checks): void {
            $checks->getQuery()->getQuery()->orderBy('id');
        }, 'checks.snoozes', 'tags', 'client', 'repositories']))->response()->setStatusCode(201);
    }

    public function update(#[CurrentUser] User $user, Request $request, Site $monitor, UpdateSite $update, UpdateSiteSettings $move, AuthorizeSiteWrite $authorize, SitePolicy $policy): MonitorResource
    {
        /** @var array<string, mixed> $attributes */
        $attributes = $request->all();
        // Early and unlocked, so a hidden monitor answers 404 like a missing one even while the access lock is busy. A move-only
        // request out of an archived client is allowed as on the web; UpdateSiteSettings refuses other fields there.
        Gate::authorize(array_key_exists('client_id', $attributes) ? 'move' : 'update', $monitor);
        /** @var array{client_id?: int} $data */
        $data = Validator::make($attributes, ['client_id' => ['sometimes', 'required', 'integer:strict', $policy->visibleClientRule($user, includeArchived: true)]])->validate();
        if (isset($data['client_id'])) {
            // MoveSiteToClient moves the site first, then the other fields are saved, as in the web site settings.
            $move($user, $monitor, $attributes, [], $data['client_id'], refuseWhenBusy: true);
            $site = $monitor->refresh();
        } else {
            $site = $authorize($user, 'update', static fn (): Site => $update($monitor, $attributes), $monitor);
        }

        return new MonitorResource($site->load(['checks' => static function (Relation $checks): void {
            $checks->getQuery()->getQuery()->orderBy('id');
        }, 'checks.snoozes', 'tags', 'client', 'repositories']));
    }

    public function destroy(#[CurrentUser] User $user, Site $monitor, RequestSiteDeletion $delete, AuthorizeSiteWrite $authorize): Response
    {
        Gate::authorize('delete', $monitor);
        $authorize($user, 'delete', static fn () => $delete($monitor), $monitor);

        return response()->noContent();
    }
}
