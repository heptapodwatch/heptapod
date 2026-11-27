<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers\Http\Api;

use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Group;
use Heptapod\Addons\Servers\Actions\SnoozeServer;
use Heptapod\Addons\Servers\Server;
use Heptapod\Addons\Servers\ServerReader;
use Heptapod\Addons\Servers\ServerState;
use Heptapod\Contracts\Http\PaginatedCollection;
use Heptapod\Contracts\Http\PaginationRequest;
use Heptapod\Contracts\SiteDirectory;
use Heptapod\Contracts\SnoozeEnd;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Reads servers and snoozes them through the same readers, policy and action as the server pages. */
#[Group('Servers')]
final class ServersApiController
{
    public function index(PaginationRequest $request, ServerReader $reader): JsonResponse
    {
        Gate::authorize('viewAny', Server::class);
        /** @var array{filter?: array{state?: string}} $query */
        $query = Validator::make($request->all(), ['filter' => ['sometimes', 'array:state'],
            'filter.state' => ['sometimes', Rule::in([ServerReader::NeedsAttention, ...array_column(ServerState::cases(), 'value')])]])->validate();
        $page = $request->paginate($reader->filtered(CarbonImmutable::now(), ['state' => $query['filter']['state'] ?? null])->orderBy('id'));
        $page->setCollection(collect(array_map(ServerApi::item(...), $reader->rows($page->items()))));

        return PaginatedCollection::ofBodies($page);
    }

    public function show(Request $request, Server $server, ServerReader $reader, SiteDirectory $directory): JsonResponse
    {
        Gate::authorize('view', $server);
        $user = $request->user() ?? throw new \LogicException('The server API needs a signed-in user.');

        return response()->json(ServerApi::detail($reader->server(CarbonImmutable::now(), $server->id), $directory->visible($user, $reader->linkedSiteIds($server->id))));
    }

    public function issues(PaginationRequest $request, Server $server, ServerReader $reader): JsonResponse
    {
        Gate::authorize('view', $server);
        $page = $request->paginate($reader->issues($server->id));
        $page->setCollection(collect(array_map(ServerApi::issue(...), $page->items())));

        return PaginatedCollection::ofBodies($page);
    }

    public function snooze(Request $request, Server $server, SnoozeServer $snooze): JsonResponse
    {
        Gate::authorize('update', $server);
        $snooze($server, SnoozeEnd::from($request->all()));

        return $this->receipt($server);
    }

    public function unsnooze(Server $server, SnoozeServer $snooze): JsonResponse
    {
        Gate::authorize('update', $server);
        $snooze($server, null);

        return $this->receipt($server);
    }

    /** What a snooze changed and nothing else: a token with only servers:snooze reads no server details. */
    private function receipt(Server $server): JsonResponse
    {
        $until = $server->refresh()->snoozed_until;

        return response()->json(['id' => $server->id, 'snoozed_until' => $until?->isFuture() === true ? $until->utc()->toIso8601String() : null]);
    }
}
