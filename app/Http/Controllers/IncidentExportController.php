<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Contracts\Export\Column;
use Heptapod\Contracts\Export\ColumnType;
use Heptapod\Contracts\Export\Table;
use Heptapod\Enums\ExportFormat;
use Heptapod\Enums\IncidentCause;
use Heptapod\Export\ExportResponse;
use Heptapod\Http\Requests\IncidentsFilterRequest;
use Heptapod\Models\User;
use Heptapod\Queries\IncidentsReader;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class IncidentExportController extends Controller
{
    /** GET incidents/export.{format}: the incidents the list shows for the same filters, across all its pages. */
    public function __invoke(IncidentsFilterRequest $request, ExportFormat $format, IncidentsReader $reader): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $query = $reader->filtered($user, $request);
        $total = $query->getEloquentBuilder()->getQuery()->count();
        $rows = (static function () use ($query, $reader): \Generator {
            // One presented batch per chunk keeps the export's queries per chunk, not per row; the writer stops at the row limit.
            foreach ($query->getEloquentBuilder()->with('site.client')->lazy(500)->chunk(500) as $chunk) {
                $incidents = $chunk->values()->collect();
                foreach ($reader->presentedRows($incidents) as $index => $row) {
                    $site = $incidents[$index]->site ?? throw new \LogicException('Incident site missing.');
                    $client = $site->client ?? throw new \LogicException('Incident site has no client.');
                    yield [(string) $row->id, (string) __($row->resolved_at === null ? 'incidents.state.open' : 'Resolved'), $row->site_name,
                        $client->displayName(), (string) __('checks.'.$row->check->value.'.label'),
                        (string) __($row->cause === IncidentCause::Errored ? 'Check error' : 'Failed'),
                        $row->summary === null ? '' : (string) __($row->summary, $row->summary_params),
                        $row->started_at, $row->resolved_at ?? '', (string) $row->duration_seconds];
                }
            }
        })();
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
