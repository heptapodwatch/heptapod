<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Contracts\PaginationData;
use Heptapod\Data\Ui\IncidentsIndexPageData;
use Heptapod\Data\Ui\IncidentSummaryData;
use Heptapod\Http\ListPageSize;
use Heptapod\Http\Requests\IncidentsFilterRequest;
use Heptapod\Models\User;
use Heptapod\Queries\IncidentsReader;
use Heptapod\Queries\SitesReader;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\Lazy;

final class IncidentsIndexController extends Controller
{
    public function __invoke(IncidentsFilterRequest $request, IncidentsReader $reader, SitesReader $sites): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $perPage = ListPageSize::resolve($request, 'incidents');
        $page = null;
        $read = static function () use ($reader, $user, $request, $perPage, &$page): array {
            return $page ??= $reader->index($user, $request, $perPage);
        };

        return Inertia::render('incidents/index', new IncidentsIndexPageData(
            incidents: Lazy::closure(static fn (): array => $read()[0]),
            pagination: Lazy::closure(static fn (): PaginationData => $read()[1]),
            filters: Lazy::closure(static fn (): array => $request->listFilters()->data()),
            clients: Lazy::closure(static fn (): array => $sites->clients($user)),
            summary: Lazy::closure(static fn (): IncidentSummaryData => $reader->summary($user)),
        ));
    }
}
