<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Contracts\PaginationData;
use Heptapod\Data\Ui\IncidentsIndexPageData;
use Heptapod\Data\Ui\IncidentSummaryData;
use Heptapod\Http\Requests\IncidentsFilterRequest;
use Heptapod\Models\User;
use Heptapod\Queries\IncidentsReader;
use Heptapod\Queries\SitesReader;
use Illuminate\Container\Attributes\CurrentUser;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\Lazy;

final class IncidentsIndexController extends Controller
{
    public function __invoke(#[CurrentUser] User $user, IncidentsFilterRequest $request, IncidentsReader $reader, SitesReader $sites): Response
    {
        $filters = $request->listFilters();
        $page = null;
        $read = static function () use ($reader, $user, $filters, &$page): array {
            return $page ??= $reader->index($user, $filters);
        };

        return Inertia::render('incidents/index', new IncidentsIndexPageData(
            incidents: Lazy::closure(static fn (): array => $read()[0]),
            incidents_pagination: Lazy::closure(static fn (): PaginationData => $read()[1]),
            filters: Lazy::closure(static fn (): array => $filters->data()),
            clients: Lazy::closure(static fn (): array => $sites->clients($user)),
            summary: Lazy::closure(static fn (): IncidentSummaryData => $reader->summary($user)),
        ));
    }
}
