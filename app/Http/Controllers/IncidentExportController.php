<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Contracts\Export\Column;
use Heptapod\Contracts\Export\ColumnType;
use Heptapod\Contracts\Export\Table;
use Heptapod\Enums\ExportFormat;
use Heptapod\Enums\IncidentCause;
use Heptapod\Export\ExportResponse;
use Heptapod\Http\ListPageSize;
use Heptapod\Http\Requests\IncidentsFilterRequest;
use Heptapod\Models\Incident;
use Heptapod\Models\User;
use Heptapod\Queries\IncidentsReader;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class IncidentExportController extends Controller
{
    /** GET incidents/export.{format}: the incidents the list shows for the same filters; `page`/`per_page` (This page) limit it to that page. */
    public function __invoke(IncidentsFilterRequest $request, ExportFormat $format, IncidentsReader $reader): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $query = $reader->filtered($user, $request);
        $present = static function (Collection $incidents) use ($reader): \Generator {
            /** @var Collection<int, Incident> $typed */
            $typed = $incidents;
            foreach ($reader->presentedRows($typed) as $index => $row) {
                $site = $typed[$index]->site ?? throw new \LogicException('Incident site missing.');
                $client = $site->client ?? throw new \LogicException('Incident site has no client.');
                yield [(string) $row->id, (string) __($row->resolved_at === null ? 'incidents.state.open' : 'Resolved'), $row->site_name,
                    $client->displayName(), (string) __('checks.'.$row->check->value.'.label'),
                    (string) __($row->cause === IncidentCause::Errored ? 'Check error' : 'Failed'),
                    $row->summary === null ? '' : (string) __($row->summary, $row->summary_params),
                    $row->started_at, $row->resolved_at ?? '', (string) $row->duration_seconds];
            }
        };
        if ($request->filled('page') || $request->filled('per_page')) {
            $perPage = $request->filled('per_page') ? $request->integer('per_page') : ListPageSize::Default;
            $page = max(1, $request->integer('page', 1));
            $builder = $query->getEloquentBuilder()->with('site.client');
            $builder->getQuery()->limit($perPage)->offset(($page - 1) * $perPage);
            $pageRows = $builder->get()->values();
            $total = $pageRows->count();
            $rows = $present($pageRows);
        } else {
            // Snapshot ordered incident IDs once up to the row limit so concurrent openings or resolutions cannot shift chunks.
            /** @var list<int> $ids */
            $ids = array_values(array_map(
                static fn (mixed $id): int => is_numeric($id) ? (int) $id : throw new \LogicException('Incident id must be numeric.'),
                (clone $query->getEloquentBuilder()->getQuery())->limit(Table::RowLimit + 1)->pluck('incidents.id')->all(),
            ));
            if (count($ids) > Table::RowLimit) {
                $total = $query->getEloquentBuilder()->getQuery()->count();
            } else {
                $total = count($ids);
            }
            $rows = (static function () use ($ids, $present): \Generator {
                foreach (array_chunk($ids, 500) as $chunkIds) {
                    $chunkQuery = Incident::query()->with(['site.client', 'check']);
                    $chunkQuery->getQuery()->whereIntegerInRaw('id', $chunkIds);
                    $byId = $chunkQuery->get()->keyBy('id');
                    $ordered = collect($chunkIds)
                        ->map(static fn (int $id): ?Incident => $byId->get($id))
                        ->filter(static fn (?Incident $incident): bool => $incident instanceof Incident)
                        ->values();
                    if ($ordered->isNotEmpty()) {
                        yield from $present($ordered);
                    }
                }
            })();
        }
        $number = ['ID', 'Duration (seconds)'];

        return ExportResponse::make(new Table(
            (string) __('Incidents'),
            [],
            array_map(static fn (string $label): Column => new Column((string) __($label), in_array($label, $number, true) ? ColumnType::Number : ColumnType::Text),
                ['ID', 'State', 'Site', 'Client', 'Check', 'Cause', 'Summary', 'Started', 'Resolved', 'Duration (seconds)']),
            $rows,
            Str::slug(__('Incidents').' '.now($user->resolvedTimezone())->format('Y-m-d'), language: app()->getLocale()),
            $total,
        ), $format, recoveryRoute: 'incidents.index', recoveryLabelKey: 'Back to Incidents');
    }
}
