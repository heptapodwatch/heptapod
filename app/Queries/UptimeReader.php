<?php

declare(strict_types=1);

namespace Heptapod\Queries;

use Carbon\CarbonImmutable;
use Closure;
use Heptapod\Contracts\CheckKey;
use Heptapod\Enums\IncidentCause;
use Heptapod\Maintenance\MaintenanceTargets;
use Heptapod\Models\Check;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/** @phpstan-type Snapshot array{EloquentCollection<int, Check>, array<int, int|null>, array<int, list<array{int, int}>>, array<int, list<array{int, int}>>, array<int, list<array{int, int}>>, array<int, list<array{int, int}>>} */
final class UptimeReader
{
    /** @param list<int> $checkIds
     * @return array<int, array{int, int}>
     */
    public function durations(array $checkIds, CarbonImmutable $from, CarbonImmutable $until): array
    {
        return $this->windows($checkIds, $from, $until);
    }

    /** @param list<int> $checkIds
     * @return array<int, float|null>
     */
    public function percentages(array $checkIds, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $windows = $this->windows($checkIds, $from, $until);
        $percentages = [];
        foreach ($windows as $id => [$monitored, $downtime]) {
            $percentages[$id] = $monitored === 0 ? null : 100 * (1 - $downtime / $monitored);
        }

        return $percentages;
    }

    /** @param list<int> $checkIds */
    public function percentage(array $checkIds, CarbonImmutable $from, CarbonImmutable $until): ?float
    {
        return $this->percentagesForRanges($checkIds, ['period' => $from], $until)['period'];
    }

    /** @param list<int> $checkIds
     * @param  non-empty-array<string, CarbonImmutable>  $starts
     * @return array<string, float|null>
     */
    public function percentagesForRanges(array $checkIds, array $starts, CarbonImmutable $until): array
    {
        $earliest = $until;
        foreach ($starts as $from) {
            if ($from->lessThan($earliest)) {
                $earliest = $from;
            }
        }
        if ($earliest->greaterThanOrEqualTo($until)) {
            throw new \InvalidArgumentException('Uptime windows must have a positive duration.');
        }
        $snapshot = $checkIds === [] ? null : $this->snapshot($checkIds, $earliest, $until);
        $percentages = [];
        foreach ($starts as $range => $from) {
            $monitored = 0;
            $downtime = 0;
            foreach ($this->windows($checkIds, $from, $until, $snapshot) as [$time, $down]) {
                $monitored += $time;
                $downtime += $down;
            }
            $percentages[$range] = $monitored === 0 ? null : 100 * (1 - $downtime / $monitored);
        }

        return $percentages;
    }

    /** @param list<int> $checkIds
     * @param  list<array{CarbonImmutable, CarbonImmutable}>  $bounds  Chronological, non-overlapping windows.
     * @param  Closure(): void|null  $countWork
     * @return list<float|null>
     */
    public function series(array $checkIds, array $bounds, ?Closure $countWork = null): array
    {
        if ($bounds === []) {
            return [];
        }
        $timeBounds = [];
        $previousEnd = null;
        foreach ($bounds as [$from, $until]) {
            $countWork?->__invoke();
            $start = $from->getTimestampMs();
            $end = $until->getTimestampMs();
            if ($start >= $end || ($previousEnd !== null && $start < $previousEnd)) {
                throw new \InvalidArgumentException('Uptime windows must be chronological and non-overlapping.');
            }
            $timeBounds[] = [$start, $end];
            $previousEnd = $end;
        }
        [$checks, $createdAt, $sitePeriods, $checkPeriods, $maintenance, $incidents] = $this->snapshot($checkIds, $bounds[0][0], array_last($bounds)[1], $countWork);
        $first = $timeBounds[0][0];
        $last = array_last($timeBounds)[1];
        $monitored = array_fill(0, count($timeBounds), 0);
        $downtime = array_fill(0, count($timeBounds), 0);
        $fullChecks = array_fill(0, count($timeBounds), 0);
        foreach ($checks as $check) {
            $countWork?->__invoke();
            $checkId = $check->id;
            $siteId = $check->site_id;
            if (! array_key_exists($checkId, $createdAt)) {
                throw new \LogicException('Uptime check must have creation time.');
            }
            $start = $createdAt[$checkId];
            if ($start === null) {
                continue;
            }
            $lower = 0;
            $upper = count($timeBounds);
            while ($lower < $upper) {
                $countWork?->__invoke();
                $middle = intdiv($lower + $upper, 2);
                if ($timeBounds[$middle][1] <= $start) {
                    $lower = $middle + 1;
                } else {
                    $upper = $middle;
                }
            }
            if ($lower < count($timeBounds)) {
                [$from, $until] = $timeBounds[$lower];
                $monitored[$lower] += max(0, $until - max($from, $start));
                if ($lower + 1 < count($timeBounds)) {
                    $fullChecks[$lower + 1]++;
                }
            }
            $excluded = $this->clipped([
                ...($sitePeriods[$siteId] ?? []), ...($checkPeriods[$checkId] ?? []), ...($maintenance[$siteId] ?? []),
            ], max($start, $first), $last, $countWork);
            $down = $this->clipped($incidents[$checkId] ?? [], max($start, $first), $last, $countWork);
            $this->accumulateOverlaps($excluded, $timeBounds, $monitored, -1, $countWork);
            $this->accumulateOverlaps($down, $timeBounds, $downtime, 1, $countWork);
            $this->accumulateOverlaps($this->intersection($down, $excluded, $countWork), $timeBounds, $downtime, -1, $countWork);
        }
        $series = [];
        $runningChecks = 0;
        foreach ($timeBounds as $index => [$from, $until]) {
            $countWork?->__invoke();
            $runningChecks += $fullChecks[$index];
            $time = $monitored[$index] + $runningChecks * ($until - $from);
            $series[] = $time === 0 ? null : 100 * (1 - $downtime[$index] / $time);
        }

        return $series;
    }

    /** @param list<int> $checkIds
     * @param  Snapshot|null  $snapshot
     * @return array<int, array{int, int}>
     */
    private function windows(array $checkIds, CarbonImmutable $from, CarbonImmutable $until, ?array $snapshot = null): array
    {
        if ($from->greaterThanOrEqualTo($until)) {
            throw new \InvalidArgumentException('Uptime window must have a positive duration.');
        }
        if ($checkIds === []) {
            return [];
        }
        [$checks, $createdAt, $sitePeriods, $checkPeriods, $maintenance, $incidents] = $snapshot ?? $this->snapshot($checkIds, $from, $until);
        $fromMs = $from->getTimestampMs();
        $end = $until->getTimestampMs();
        $windows = [];
        foreach ($checks as $check) {
            if (! array_key_exists($check->id, $createdAt)) {
                throw new \LogicException('Uptime check must have creation time.');
            }
            $created = $createdAt[$check->id];
            if ($created === null) {
                $windows[$check->id] = [0, 0];

                continue;
            }
            $start = max($fromMs, $created);
            if ($start >= $end) {
                $windows[$check->id] = [0, 0];

                continue;
            }
            if (($sitePeriods[$check->site_id] ?? []) === [] && ($checkPeriods[$check->id] ?? []) === []
                && ($maintenance[$check->site_id] ?? []) === [] && ($incidents[$check->id] ?? []) === []) {
                $windows[$check->id] = [$end - $start, 0];

                continue;
            }
            $excluded = $this->clipped([
                ...($sitePeriods[$check->site_id] ?? []), ...($checkPeriods[$check->id] ?? []), ...($maintenance[$check->site_id] ?? []),
            ], $start, $end);
            $monitored = $end - $start - $this->length($excluded);
            $down = $this->clipped($incidents[$check->id] ?? [], $start, $end);
            $overlap = 0;
            foreach ($down as [$downStart, $downEnd]) {
                foreach ($excluded as [$excludedStart, $excludedEnd]) {
                    $overlap += max(0, min($downEnd, $excludedEnd) - max($downStart, $excludedStart));
                }
            }
            $windows[$check->id] = [$monitored, max(0, $this->length($down) - $overlap)];
        }

        return $windows;
    }

    /** @param list<int> $checkIds
     * @param  Closure(): void|null  $countWork
     * @return Snapshot
     */
    private function snapshot(array $checkIds, CarbonImmutable $from, CarbonImmutable $until, ?Closure $countWork = null): array
    {
        $query = Check::query()->whereKey($checkIds);
        $query->getQuery()->join('sites', 'sites.id', '=', 'checks.site_id')->where('checks.type', CheckKey::Uptime)->select('checks.*', 'sites.created_at AS site_created_at');
        $checks = $query->get();
        $createdAt = [];
        foreach ($checks as $check) {
            $countWork?->__invoke();
            $siteCreated = $check->getAttribute('site_created_at');
            if (! is_string($siteCreated)) {
                throw new \LogicException('Uptime site must have creation time.');
            }
            $checkCreated = $check->created_at ?? throw new \LogicException('Uptime check must have creation time.');
            // No measured result means no monitored window, even when the check already exists.
            $createdAt[$check->id] = $check->first_result_at === null ? null
                : max($this->milliseconds($siteCreated), $checkCreated->getTimestampMs(), $check->first_result_at->getTimestampMs());
        }
        $siteIds = $checks->pluck('site_id')->unique()->all();
        $periods = DB::table('unmonitored_periods')->whereIn('site_id', $siteIds)->where('started_at', '<', $until)
            ->where(function (Builder $query) use ($from): void {
                $query->whereNull('ended_at')->orWhere('ended_at', '>', $from);
            })->get();
        $maintenance = DB::table('maintenance_periods as m')->join('sites as s', static fn (JoinClause $join): JoinClause => $join->whereRaw(
            ...MaintenanceTargets::coversSiteSql('m', 's')))
            ->leftJoin('maintenance_site_exclusions as ex', static fn (JoinClause $join): JoinClause => $join
                ->on('ex.maintenance_period_id', '=', 'm.id')->on('ex.site_id', '=', 's.id'))
            ->whereIn('s.id', $siteIds)->where('m.starts_at', '<', $until)->where('m.ends_at', '>', $from)
            ->where(static fn (Builder $active): Builder => $active->whereNull('ex.left_at')->orWhere('ex.left_at', '>', $from))
            ->selectRaw('s.id as site_id, m.starts_at, least(m.ends_at, coalesce(ex.left_at, m.ends_at)) as ends_at')->get();
        $incidents = DB::table('incidents')->selectRaw('check_id, round(extract(epoch from first_failure_at) * 1000)::bigint AS started_ms, round(extract(epoch from resolved_at) * 1000)::bigint AS ended_ms')
            ->whereIn('check_id', $checkIds)->where('cause', IncidentCause::Failed->value)
            ->where('first_failure_at', '<', $until)->where(function (Builder $query) use ($from): void {
                $query->whereNull('resolved_at')->orWhere('resolved_at', '>', $from);
            })->get();

        $sitePeriods = [];
        $checkPeriods = [];
        foreach ($periods as $period) {
            $countWork?->__invoke();
            if (! is_int($period->site_id) || ($period->check_id !== null && ! is_int($period->check_id)) || ! is_string($period->started_at)
                || ($period->ended_at !== null && ! is_string($period->ended_at))) {
                throw new \LogicException('Unmonitored period has invalid interval data.');
            }
            $interval = [$this->milliseconds($period->started_at), $period->ended_at === null ? $until->getTimestampMs() : $this->milliseconds($period->ended_at)];
            if ($period->check_id === null) {
                $sitePeriods[$period->site_id][] = $interval;
            } else {
                $checkPeriods[$period->check_id][] = $interval;
            }
        }
        $maintenanceBySite = [];
        foreach ($maintenance as $period) {
            $countWork?->__invoke();
            if (! is_int($period->site_id) || ! is_string($period->starts_at) || ! is_string($period->ends_at)) {
                throw new \LogicException('Maintenance interval must contain a site and timestamps.');
            }
            $maintenanceBySite[$period->site_id][] = [CarbonImmutable::parse($period->starts_at)->getTimestampMs(),
                CarbonImmutable::parse($period->ends_at)->getTimestampMs()];
        }
        $incidentsByCheck = [];
        foreach ($incidents as $incident) {
            $countWork?->__invoke();
            if (! is_int($incident->check_id) || ! is_int($incident->started_ms)
                || ($incident->ended_ms !== null && ! is_int($incident->ended_ms))) {
                throw new \LogicException('Uptime incident has invalid interval data.');
            }
            $incidentsByCheck[$incident->check_id][] = [$incident->started_ms, $incident->ended_ms ?? $until->getTimestampMs()];
        }

        return [$checks, $createdAt, $sitePeriods, $checkPeriods, $maintenanceBySite, $incidentsByCheck];
    }

    private function milliseconds(string $timestamp): int
    {
        return CarbonImmutable::parse($timestamp)->getTimestampMs();
    }

    /** @param list<array{int, int}> $intervals
     * @param  Closure(): void|null  $countWork
     * @return list<array{int, int}>
     */
    private function clipped(array $intervals, int $start, int $end, ?Closure $countWork = null): array
    {
        $ranges = [];
        foreach ($intervals as [$begin, $finish]) {
            $countWork?->__invoke();
            if (max($begin, $start) < min($finish, $end)) {
                $ranges[] = [max($begin, $start), min($finish, $end)];
            }
        }
        usort($ranges, static function (array $a, array $b) use ($countWork): int {
            $countWork?->__invoke();

            return $a[0] <=> $b[0];
        });
        $merged = [];
        foreach ($ranges as [$begin, $finish]) {
            $countWork?->__invoke();
            $last = count($merged) - 1;
            if ($last >= 0 && $begin <= $merged[$last][1]) {
                $merged[$last][1] = max($finish, $merged[$last][1]);
            } else {
                $merged[] = [$begin, $finish];
            }
        }

        return $merged;
    }

    /** @param list<array{int, int}> $left
     * @param  list<array{int, int}>  $right
     * @param  Closure(): void|null  $countWork
     * @return list<array{int, int}>
     */
    private function intersection(array $left, array $right, ?Closure $countWork = null): array
    {
        $overlap = [];
        $i = 0;
        $j = 0;
        while ($i < count($left) && $j < count($right)) {
            $countWork?->__invoke();
            $begin = max($left[$i][0], $right[$j][0]);
            $end = min($left[$i][1], $right[$j][1]);
            if ($begin < $end) {
                $overlap[] = [$begin, $end];
            }
            if ($left[$i][1] <= $right[$j][1]) {
                $i++;
            } else {
                $j++;
            }
        }

        return $overlap;
    }

    /** @param list<array{int, int}> $ranges
     * @param  list<array{int, int}>  $bounds
     * @param  array<int, int>  $totals
     * @param  Closure(): void|null  $countWork
     *
     * @param-out array<int, int> $totals
     */
    private function accumulateOverlaps(array $ranges, array $bounds, array &$totals, int $sign, ?Closure $countWork = null): void
    {
        foreach ($ranges as [$begin, $end]) {
            $lower = 0;
            $upper = count($bounds);
            while ($lower < $upper) {
                $countWork?->__invoke();
                $middle = intdiv($lower + $upper, 2);
                if ($bounds[$middle][1] <= $begin) {
                    $lower = $middle + 1;
                } else {
                    $upper = $middle;
                }
            }
            for ($window = $lower; $window < count($bounds) && $bounds[$window][0] < $end; $window++) {
                $countWork?->__invoke();
                [$from, $until] = $bounds[$window];
                $totals[$window] += $sign * max(0, min($end, $until) - max($begin, $from));
            }
        }
    }

    /** @param list<array{int, int}> $intervals */
    private function length(array $intervals): int
    {
        return array_sum(array_map(static fn (array $range): int => $range[1] - $range[0], $intervals));
    }
}
