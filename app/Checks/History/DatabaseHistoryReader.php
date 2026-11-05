<?php

declare(strict_types=1);

namespace Heptapod\Checks\History;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\HistoryReader;
use Heptapod\Contracts\PreviousResult;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use OverflowException;

final class DatabaseHistoryReader implements HistoryReader
{
    public function read(int $siteId, CheckKey $type, CarbonImmutable $from, CarbonImmutable $until, int $limit, array $additionalWindows = []): array
    {
        if ($limit < 1 || $limit > 10000 || $from->greaterThan($until) || count($additionalWindows) > 7) {
            throw new InvalidArgumentException('History requires ordered windows, at most seven additional windows and a limit between 1 and 10000.');
        }
        foreach ($additionalWindows as $window) {
            if ($window['from']->greaterThan($window['until'])) {
                throw new InvalidArgumentException('History requires ordered additional windows.');
            }
        }

        // Each branch keeps both check identity and time bounds in the composite index scan.
        $windowQuery = fn (CarbonImmutable $start, CarbonImmutable $end, int $index): Builder => DB::table('check_runs')->select('check_runs.*')
            ->selectRaw('CAST(? AS integer) AS history_window', [$index])
            ->join('checks', 'checks.id', '=', 'check_runs.check_id')
            ->where('checks.site_id', $siteId)->where('checks.type', $type->value)->where('check_type', $type->value)
            ->whereNotNull('metrics')
            ->where('ended_at', '>=', $start->format('Y-m-d H:i:s.uP'))
            ->where('ended_at', '<', $end->format('Y-m-d H:i:s.uP'))
            ->orderBy('ended_at')->orderBy('check_runs.id')->limit($limit);
        $query = $windowQuery($from, $until, 0);
        foreach ($additionalWindows as $index => $window) {
            $query->unionAll($windowQuery($window['from'], $window['until'], $index + 1));
        }

        /** @var list<\stdClass&object{id: int, ended_at: string, result: string, started_at: ?string, summary: string, summary_params: string, metrics: string, history_window: int}> $rows */
        $rows = DB::query()->fromSub($query, 'history_observations')
            ->orderBy('ended_at')->orderBy('id')->get()->all();
        $counts = [];
        $observations = [];
        foreach ($rows as $row) {
            $counts[$row->history_window] = ($counts[$row->history_window] ?? 0) + 1;
            if ($counts[$row->history_window] === $limit) {
                throw new OverflowException('History window '.$row->history_window.' reached its '.$limit.' observation limit; refusing incomplete history.');
            }
            // Generic callers may overlap windows. Physical identity includes ended_at, not ID alone.
            $observations[$row->id.':'.$row->ended_at] = $row;
        }

        return array_map(fn (object $row): PreviousResult => PreviousResult::from([
            'run' => ['id' => $row->id, 'endedAt' => CarbonImmutable::parse($row->ended_at)],
            'result' => $row->result,
            'startedAt' => $row->started_at === null ? null : CarbonImmutable::parse($row->started_at),
            'summary' => $row->summary,
            'summaryParams' => json_decode($row->summary_params, true, flags: JSON_THROW_ON_ERROR),
            'metrics' => json_decode($row->metrics, true, flags: JSON_THROW_ON_ERROR),
        ]), array_values($observations));
    }
}
