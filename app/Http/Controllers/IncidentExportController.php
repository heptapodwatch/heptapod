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
            $total = $query->getEloquentBuilder()->getQuery()->count();
            $rows = (static function () use ($query, $present): \Generator {
                // One presented batch per chunk keeps the export's queries per chunk, not per row; the writer stops at the row limit.
                foreach ($query->getEloquentBuilder()->with('site.client')->lazy(500)->chunk(500) as $chunk) {
                    yield from $present($chunk->values()->collect());
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
        ), $format);
    }
}
