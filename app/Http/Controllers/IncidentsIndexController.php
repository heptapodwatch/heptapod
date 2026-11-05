<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Data\Ui\IncidentFilterData;
use Heptapod\Data\Ui\IncidentsIndexData;
use Heptapod\Data\Ui\IncidentsIndexPageData;
use Heptapod\Data\Ui\IncidentSummaryData;
use Heptapod\Data\Ui\PaginationData;
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
        $page = null;
        $read = static function () use ($reader, $user, $request, &$page): IncidentsIndexData {
            return $page ??= $reader->index($user, $request, false);
        };

        return Inertia::render('incidents/index', new IncidentsIndexPageData(
            incidents: Lazy::closure(static fn (): array => $read()->incidents),
            pagination: Lazy::closure(static fn (): PaginationData => $read()->pagination),
            filters: Lazy::closure(static fn (): IncidentFilterData => $read()->filters),
            clients: Lazy::closure(static fn (): array => $sites->clients($user)),
            summary: Lazy::closure(static fn (): IncidentSummaryData => $reader->summary($user)),
        ));
    }
}
