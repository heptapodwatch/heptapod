<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\PaginationData;
use Heptapod\Data\Ui\IncidentFilterData;
use Heptapod\Data\Ui\IncidentsIndexData;
use Heptapod\Data\Ui\IncidentsIndexPageData;
use Heptapod\Data\Ui\IncidentSummaryData;
use Heptapod\Enums\IncidentCause;
use Heptapod\Http\ListPageSize;
use Heptapod\Http\Requests\IncidentsFilterRequest;
use Heptapod\Models\User;
use Heptapod\Queries\IncidentsReader;
use Heptapod\Queries\SitesReader;
use Illuminate\Support\Arr;
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
        $read = static function () use ($reader, $user, $request, $perPage, &$page): IncidentsIndexData {
            return $page ??= $reader->index($user, $request, $perPage, false);
        };

        /** @var array{status?: ?string, period?: ?string, cause?: ?string, check?: ?string, client_id?: int|string|null, started_after?: ?string, started_before?: ?string} $filters */
        $filters = $request->validated('filter', []);
        $clearUrl = static fn (string $key): string => route('incidents.index', ['filter' => Arr::except($filters, $key)], absolute: false);
        $timezone = $user->resolvedTimezone();
        $dateChip = static fn (string $key, ?string $value): ?array => $value !== null ? [
            'timestamp' => CarbonImmutable::parse($value, $timezone)->toIso8601String(),
            'whole_day' => preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1,
            'clear_url' => $clearUrl($key),
        ] : null;

        return Inertia::render('incidents/index', new IncidentsIndexPageData(
            incidents: Lazy::closure(static fn (): array => $read()->incidents),
            pagination: Lazy::closure(static fn (): PaginationData => $read()->pagination),
            filters: Lazy::closure(static fn (): IncidentFilterData => $read()->filters),
            clients: Lazy::closure(static fn (): array => $sites->clients($user)),
            summary: Lazy::closure(static fn (): IncidentSummaryData => $reader->summary($user)),
            filter_chips: [
                'cause' => isset($filters['cause']) ? [
                    'value' => IncidentCause::from($filters['cause']),
                    'clear_url' => $clearUrl('cause'),
                ] : null,
                'started_after' => $dateChip('started_after', $filters['started_after'] ?? null),
                'started_before' => $dateChip('started_before', $filters['started_before'] ?? null),
            ],
        ));
    }
}
