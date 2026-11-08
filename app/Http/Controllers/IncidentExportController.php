<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Http\Requests\IncidentsFilterRequest;
use Heptapod\Models\User;
use Heptapod\Queries\IncidentsReader;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class IncidentExportController extends Controller
{
    private const int MAX_ROWS = 10000;

    public function __invoke(IncidentsFilterRequest $request, IncidentsReader $reader): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $query = $reader->filtered($user, $request);
        abort_if($query->getEloquentBuilder()->getQuery()->count() > self::MAX_ROWS, 413,
            __('Incident export exceeds :count rows. Narrow the filters.', ['count' => self::MAX_ROWS]));

        return response()->streamDownload(static function () use ($query, $reader): void {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                throw new \RuntimeException('Cannot open incident export stream.');
            }
            $headers = array_map(static function (string $heading): string {
                $translated = __($heading);
                if (! is_string($translated)) {
                    throw new \LogicException('Incident export header translation must be text.');
                }

                return $translated;
            }, ['ID', 'State', 'Site', 'Client', 'Check', 'Cause', 'Summary', 'Started', 'Resolved', 'Duration (seconds)']);
            fputcsv($output, $headers, ',', '"', '');
            $emitted = 0;
            $query->getEloquentBuilder()->with('site.client')->getQuery()->limit(self::MAX_ROWS);
            // One presented batch per chunk keeps the export's queries per chunk, not per row.
            foreach ($query->getEloquentBuilder()->lazy(500)->chunk(500) as $chunk) {
                $incidents = $chunk->values()->collect();
                foreach ($reader->presentedRows($incidents) as $index => $row) {
                    if ($emitted >= self::MAX_ROWS) {
                        break 2;
                    }
                    $emitted++;
                    $incident = $incidents[$index];
                    $site = $incident->site ?? throw new \LogicException('Incident site missing.');
                    $client = $site->client ?? throw new \LogicException('Incident site has no client.');
                    $summary = $row->summary === null ? '' : __($row->summary, $row->summary_params);
                    $state = $row->resolved_at === null ? __('incidents.state.open') : __('Resolved');
                    if (! is_string($summary) || ! is_string($state)) {
                        throw new \LogicException('Incident export translations must be text.');
                    }
                    $values = [$row->id, $state, $row->site_name,
                        $client->displayName(), $row->check->value, $row->cause->value, $summary,
                        $row->started_at, $row->resolved_at ?? '', $row->duration_seconds];
                    fputcsv($output, array_map(self::safeCell(...), $values), ',', '"', '');
                }
            }
            fclose($output);
        }, 'incidents.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private static function safeCell(string|int $value): string
    {
        $text = (string) $value;

        return preg_match('/^(?:[\x00-\x20]*[=+@-]|[\t\r])/u', $text) === 1 ? "'".$text : $text;
    }
}
