<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Data\Ui\ClientData;
use Heptapod\Data\Ui\ClientDetailPageData;
use Heptapod\Data\Ui\DashboardTilesData;
use Heptapod\Data\Ui\PaginationData;
use Heptapod\Models\User;
use Heptapod\Queries\ClientsReader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\Lazy;

final class ClientDetailController extends Controller
{
    public function __invoke(Request $request, int $client, ClientsReader $reader): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        Validator::make($request->all(), ['page' => ['sometimes', 'integer', 'min:1']])->validate();

        // Eager, so a missing or hidden client 404s first; the closure keeps nested partials (client.name) resolving the whole client.
        $clientData = $reader->client($user, $client);
        $sitePage = null;
        $getSites = static function () use ($reader, $user, $client, &$sitePage): array {
            return $sitePage ??= $reader->sitePage($user, $client);
        };

        return Inertia::render('clients/show', new ClientDetailPageData(
            client: Lazy::closure(static fn (): ClientData => $clientData),
            summary: Lazy::closure(static fn (): DashboardTilesData => $reader->summary($user, $client)),
            sites: Lazy::closure(static fn (): array => $getSites()['sites']),
            pagination: Lazy::closure(static fn (): PaginationData => $getSites()['pagination']),
            incidents: Lazy::closure(static fn (): array => $reader->incidents($user, $client)),
            can_manage: Lazy::closure(static fn (): bool => $user->hasAnyRole(['Owner', 'Admin'])),
        ));
    }
}
