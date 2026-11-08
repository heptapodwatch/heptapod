<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Actions\MoveSiteToClient;
use Heptapod\Models\Client;
use Heptapod\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/** The up-front check of the archive dialog: which of the client's sites cannot move to another client right now. */
final class ClientMoveBlockersController extends Controller
{
    public function __invoke(Client $client): JsonResponse
    {
        Gate::authorize('archive', $client);
        $sites = Site::query();
        $sites->getQuery()->where('client_id', $client->id)->orderBy('id');
        $sites = $sites->get(['id', 'url', 'friendly_name']);
        /** @var list<int> $siteIds */
        $siteIds = $sites->modelKeys();
        $blockers = MoveSiteToClient::blockers($siteIds);

        return response()->json(['sites' => $sites->filter(static fn (Site $site): bool => in_array($site->id, $blockers, true))
            ->map(static fn (Site $site): array => ['id' => $site->id, 'name' => $site->displayName()])->values()->all()]);
    }
}
