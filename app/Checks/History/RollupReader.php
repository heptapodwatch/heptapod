<?php

declare(strict_types=1);

namespace Heptapod\Checks\History;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Heptapod\Contracts\DailyRollupReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

/**
 * @phpstan-type SeriesBucket array{bucket_start: CarbonImmutable, succeeded_count: int, warning_count: int, failed_count: int, errored_count: int, completed_count: int, uptime_percentage: ?float, metric_sums: array<string, int|float>, metric_counts: array<string, int|float>, metric_averages: array<string, float>}
 */
final class RollupReader implements DailyRollupReader
{
    /** @return list<array{bucket_start: CarbonImmutable, metric_averages: array<string, float>}> */
    public function daily(int $checkId, CarbonImmutable $from, CarbonImmutable $until): array
    {
        return $this->read($checkId, 'daily', $from, $until);
    }

    /**
     * UTC bucket starts in [from, until); bounds must be aligned. Empty buckets have null uptime and no metrics.
     * Numeric object leaves use dotted paths (e.g. curl.total_time). p95 applies only to this bucket, never an arbitrary range.
     *
     * @return list<array{bucket_start: CarbonImmutable, succeeded_count: int, warning_count: int, failed_count: int,
     *     errored_count: int, completed_count: int, uptime_percentage: ?float, metric_sums: array<string, int|float>,
     *     metric_counts: array<string, int|float>, metric_averages: array<string, float>, metric_p95: array<string, int|float>}>
     */
    public function read(int $checkId, string $period, CarbonImmutable $from, CarbonImmutable $until): array
    {
        RetentionPolicy::fromConfig();
        if (! in_array($period, ['hourly', 'daily'], true) || $from->greaterThan($until)) {
            throw new InvalidArgumentException('Rollups require hourly or daily buckets and an ordered window.');
        }
        $from = $from->utc();
        $until = $until->utc();
        $unit = $period === 'hourly' ? 'hour' : 'day';
        $start = $period === 'hourly' ? $from->startOfHour() : $from->startOfDay();
        $end = $period === 'hourly' ? $until->startOfHour() : $until->startOfDay();
        $bucketCount = $period === 'hourly' ? $from->diffInHours($until) : $from->diffInDays($until);
        if ($start->notEqualTo($from) || $end->notEqualTo($until) || $bucketCount > 10000) {
            throw new InvalidArgumentException('Rollup bounds must be UTC-aligned and contain at most 10000 buckets.');
        }

        /** @var list<object{bucket_start: string, succeeded_count: int, warning_count: int, failed_count: int, errored_count: int, metric_sums: string, metric_counts: string, metric_p95: string}> $rows */
        $rows = DB::select(<<<SQL
            SELECT bucket AS bucket_start, coalesce(succeeded_count, 0) AS succeeded_count,
                   coalesce(warning_count, 0) AS warning_count, coalesce(failed_count, 0) AS failed_count,
                   coalesce(errored_count, 0) AS errored_count, coalesce(metric_sums, '{}'::jsonb) AS metric_sums,
                   coalesce(metric_counts, '{}'::jsonb) AS metric_counts, coalesce(metric_p95, '{}'::jsonb) AS metric_p95
            FROM generate_series(?::timestamptz, ?::timestamptz, interval '1 {$unit}', 'UTC') AS bucket
            LEFT JOIN check_run_rollups_{$period} ON check_id = ? AND bucket_start = bucket
            WHERE bucket < ?::timestamptz ORDER BY bucket
            SQL, [$from->format('Y-m-d H:i:s.uP'), $until->format('Y-m-d H:i:s.uP'), $checkId, $until->format('Y-m-d H:i:s.uP')]);

        $buckets = [];
        foreach ($rows as $row) {
            $completed = $row->succeeded_count + $row->warning_count + $row->failed_count + $row->errored_count;
            $sums = $this->numericObject($row->metric_sums);
            $counts = $this->numericObject($row->metric_counts);
            $averages = [];
            foreach ($sums as $name => $sum) {
                if (! isset($counts[$name]) || $counts[$name] <= 0) {
                    throw new RuntimeException('Rollup metric sum has no positive sample count: '.$name);
                }
                $averages[$name] = (float) ($sum / $counts[$name]);
            }
            $buckets[] = [
                'bucket_start' => CarbonImmutable::parse($row->bucket_start)->utc(),
                'succeeded_count' => $row->succeeded_count, 'warning_count' => $row->warning_count,
                'failed_count' => $row->failed_count, 'errored_count' => $row->errored_count,
                'completed_count' => $completed,
                'uptime_percentage' => $completed === 0 ? null : 100.0 * ($row->succeeded_count + $row->warning_count) / $completed,
                'metric_sums' => $sums, 'metric_counts' => $counts, 'metric_averages' => $averages,
                'metric_p95' => $this->numericObject($row->metric_p95),
            ];
        }

        return $buckets;
    }

    /**
     * Public series projection shared by API and UI. Local calendar boundaries are floored to UTC hours.
     * Hour buckets remain UTC-aligned; each stored hourly rollup belongs to exactly one calendar bucket.
     * Merge sums/counts, never averages of averages; timestamps identify the actual bucket starts.
     *
     * @return list<array{bucket_start: CarbonImmutable, succeeded_count: int, warning_count: int, failed_count: int, errored_count: int, completed_count: int, uptime_percentage: ?float, metric_sums: array<string, int|float>, metric_counts: array<string, int|float>, metric_averages: array<string, float>}>
     */
    public function series(int $checkId, string $period, CarbonImmutable $from, CarbonImmutable $until, string $timezone = 'UTC', string $startField = 'filter.start'): array
    {
        return $this->seriesForChecks([$checkId], $period, $from, $until, $timezone, $startField);
    }

    /**
     * Calendar aggregation across authorized checks; merge sums/counts, never averages or percentiles.
     *
     * @param  list<int>  $checkIds
     * @return list<SeriesBucket>
     */
    public function seriesForChecks(array $checkIds, string $period, CarbonImmutable $from, CarbonImmutable $until, string $timezone = 'UTC', string $startField = 'filter.start'): array
    {
        if (! in_array($period, ['hour', 'day', 'week', 'month'], true) || $from->greaterThanOrEqualTo($until) || $from->diffInHours($until) > 10000) {
            throw new InvalidArgumentException('Series require an ordered window of at most 10000 hours.');
        }
        if ($period === 'hour') {
            $timezone = 'UTC';
        }
        // The last instant of a UTC hour identifies the local bucket whose floored boundary owns it.
        $floor = static fn (CarbonImmutable $date): CarbonImmutable => match ($period) {
            'hour' => $date->utc()->startOfHour(),
            'day' => $date->utc()->endOfHour()->setTimezone($timezone)->startOfDay(),
            'week' => $date->utc()->endOfHour()->setTimezone($timezone)->startOfWeek(CarbonInterface::MONDAY),
            'month' => $date->utc()->endOfHour()->setTimezone($timezone)->startOfMonth(),
        };
        $from = $floor($from)->utc()->startOfHour();
        $end = $floor($until);
        $until = $until->equalTo($end->utc()->startOfHour()) ? $end : match ($period) {
            'hour' => $end->addHour(), 'day' => $end->addDay(), 'week' => $end->addWeek(), 'month' => $end->addMonth(),
        };
        $until = $until->utc()->startOfHour();
        $days = RetentionPolicy::fromConfig()->hourlyDays;
        if ($from->lessThan(CarbonImmutable::now('UTC')->subDays($days))) {
            throw ValidationException::withMessages([$startField => __('api.hourly_retention', ['days' => $days])]);
        }
        if ($checkIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($checkIds), '?'));
        /** @var list<object{bucket_start: string, succeeded_count: int, warning_count: int, failed_count: int, errored_count: int, metric_sums: string, metric_counts: string}> $rows */
        $rows = DB::select(<<<SQL
            WITH samples AS MATERIALIZED (
                SELECT *, date_trunc('hour', date_trunc(?,
                    (bucket_start + interval '1 hour' - interval '1 microsecond') AT TIME ZONE ?)
                    AT TIME ZONE ?, 'UTC') AS bucket
                FROM check_run_rollups_hourly WHERE check_id IN ({$placeholders}) AND bucket_start >= ? AND bucket_start < ?
            ), counts AS (
                SELECT bucket, sum(succeeded_count)::int AS succeeded_count, sum(warning_count)::int AS warning_count,
                    sum(failed_count)::int AS failed_count, sum(errored_count)::int AS errored_count FROM samples GROUP BY bucket
            ), metrics AS (
                SELECT bucket, sums.key AS name, sum(sums.value::double precision) AS sum,
                    sum((metric_counts->>sums.key)::double precision) AS count
                FROM samples CROSS JOIN LATERAL jsonb_each_text(metric_sums) sums GROUP BY bucket, sums.key
            ), objects AS (
                SELECT bucket, jsonb_object_agg(name, sum) AS sums, jsonb_object_agg(name, count) AS counts FROM metrics GROUP BY bucket
            ) SELECT bucket::text AS bucket_start, succeeded_count, warning_count, failed_count, errored_count,
                coalesce(sums, '{}'::jsonb)::text AS metric_sums, coalesce(objects.counts, '{}'::jsonb)::text AS metric_counts
            FROM counts LEFT JOIN objects USING (bucket) ORDER BY bucket
            SQL, [$period, $timezone, $timezone, ...$checkIds, $from->format('Y-m-d H:i:s.uP'), $until->format('Y-m-d H:i:s.uP')]);

        return $this->projectSeries($rows);
    }

    /**
     * Monday-aligned UTC weeks from daily rollups, from the first week with data until the week holding the day before
     * `until`. Averages merge sums and counts; daily p95s cannot be merged, so `metric_p95_max` is the slowest day's p95.
     *
     * @param  list<string>  $metrics
     * @return list<array{bucket_start: CarbonImmutable, metric_averages: array<string, float>, metric_p95_max: array<string, float>}>
     */
    public function weekly(int $checkId, array $metrics, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $from = $from->utc();
        $until = $until->utc();
        if ($from->notEqualTo($from->startOfWeek(CarbonInterface::MONDAY)) || $until->notEqualTo($until->startOfDay())
            || $from->greaterThanOrEqualTo($until) || $from->diffInDays($until) > 1110) {
            throw new InvalidArgumentException('Weekly rollups require a Monday start, a day-aligned end and at most 1110 days.');
        }
        /** @var list<object{week: string, metric: string, average: float|null, p95_max: float|null}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT (date_trunc('week', r.bucket_start AT TIME ZONE 'UTC') AT TIME ZONE 'UTC')::text AS week, m.key AS metric,
                sum(m.value::double precision) / nullif(sum((r.metric_counts ->> m.key)::double precision), 0) AS average,
                max((r.metric_p95 ->> m.key)::double precision) AS p95_max
            FROM check_run_rollups_daily r CROSS JOIN LATERAL jsonb_each_text(r.metric_sums) m
            WHERE r.check_id = ? AND r.bucket_start >= ? AND r.bucket_start < ? AND m.key = ANY(?::text[])
            GROUP BY 1, 2 ORDER BY 1, 2
            SQL, [$checkId, $from->format('Y-m-d H:i:s.uP'), $until->format('Y-m-d H:i:s.uP'), '{'.implode(',', $metrics).'}']);
        if ($rows === []) {
            return [];
        }
        $byWeek = [];
        foreach ($rows as $row) {
            $week = CarbonImmutable::parse($row->week)->utc()->toIso8601String();
            if ($row->average !== null) {
                $byWeek[$week]['metric_averages'][$row->metric] = $row->average;
            }
            if ($row->p95_max !== null) {
                $byWeek[$week]['metric_p95_max'][$row->metric] = $row->p95_max;
            }
            $byWeek[$week] ??= [];
        }
        $weeks = [];
        for ($week = CarbonImmutable::parse($rows[0]->week)->utc(); $week->lessThan($until); $week = $week->addWeek()) {
            $bucket = $byWeek[$week->toIso8601String()] ?? [];
            $weeks[] = ['bucket_start' => $week, 'metric_averages' => $bucket['metric_averages'] ?? [], 'metric_p95_max' => $bucket['metric_p95_max'] ?? []];
        }

        return $weeks;
    }

    /**
     * One check's last day in 5-minute buckets from raw runs: average and 95th percentile of the total time, plus the
     * non-OK counts for the failure marks. Bounded to 24 hours by validateRecentWindow().
     *
     * @return list<array{bucket_start: CarbonImmutable, average_ms: ?float, p95_ms: ?float, failed_count: int, errored_count: int, warning_count: int}>
     */
    public function responseSamples(int $checkId, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $this->validateRecentWindow($from, $until);
        /** @var list<object{bucket_start: string, average_ms: float|null, p95_ms: float|null, failed_count: int, errored_count: int, warning_count: int}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT date_bin(interval '5 minutes', ended_at, ?::timestamptz)::text AS bucket_start,
                1000 * avg((metrics->>'total_time_in_seconds')::double precision)
                    FILTER (WHERE jsonb_typeof(metrics->'total_time_in_seconds') = 'number') AS average_ms,
                1000 * percentile_cont(0.95) WITHIN GROUP (ORDER BY (metrics->>'total_time_in_seconds')::double precision)
                    FILTER (WHERE jsonb_typeof(metrics->'total_time_in_seconds') = 'number') AS p95_ms,
                count(*) FILTER (WHERE result = 'failed')::int AS failed_count,
                count(*) FILTER (WHERE result = 'errored-or-timed-out')::int AS errored_count,
                count(*) FILTER (WHERE result = 'warning')::int AS warning_count
            FROM check_runs WHERE check_id = ? AND ended_at >= ? AND ended_at < ?
            GROUP BY 1 ORDER BY 1
            SQL, [$from, $checkId, $from, $until]);

        return array_map(static fn (object $row): array => [
            'bucket_start' => CarbonImmutable::parse($row->bucket_start)->utc(), 'average_ms' => $row->average_ms, 'p95_ms' => $row->p95_ms,
            'failed_count' => $row->failed_count, 'errored_count' => $row->errored_count, 'warning_count' => $row->warning_count,
        ], $rows);
    }

    /**
     * @param  list<int>  $checkIds
     * @return array<int, float|null> Check IDs to the weighted recent response time in milliseconds.
     */
    public function recentResponseTimes(array $checkIds, CarbonImmutable $from, CarbonImmutable $until): array
    {
        if ($checkIds === []) {
            return [];
        }
        $this->validateRecentWindow($from, $until);
        $placeholders = implode(',', array_fill(0, count($checkIds), '?'));
        /** @var list<object{check_id: int, average: float|null}> $rows */
        $rows = DB::select(<<<SQL
            SELECT check_id, 1000 * avg((metrics->>'total_time_in_seconds')::double precision) AS average
            FROM check_runs WHERE check_id IN ({$placeholders}) AND ended_at >= ? AND ended_at < ?
                AND jsonb_typeof(metrics->'total_time_in_seconds') = 'number'
            GROUP BY check_id
            SQL, [...$checkIds, $from, $until]);
        $averages = array_fill_keys($checkIds, null);
        foreach ($rows as $row) {
            $averages[$row->check_id] = $row->average;
        }

        return $averages;
    }

    /**
     * The list response chart is 80px wide: 18-minute buckets retain peaks and every non-OK observation.
     * Unlike public minute history, this projection does not flatten unrelated addon metrics.
     *
     * @param  list<int>  $checkIds
     * @return array<int, list<array{bucket_start: CarbonImmutable, maximum_ms: ?float, metric_maxima: array<string, int|float|null>, failed_count: int, errored_count: int, warning_count: int}>>
     */
    public function responsePeaks(array $checkIds, CarbonImmutable $from, CarbonImmutable $until, int $bucketMinutes = 18, bool $combine = false): array
    {
        $this->validateRecentWindow($from, $until);
        if (! in_array($bucketMinutes, [5, 18], true)) {
            throw new InvalidArgumentException('Response charts require 5- or 18-minute peak buckets.');
        }
        if ($checkIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($checkIds), '?'));
        $group = $combine ? '0' : 'check_id';
        $timings = [];
        foreach (['dns_time_in_seconds', 'tcp_time_in_seconds', 'ssl_handshake_time_in_seconds', 'remote_server_processing_time_in_seconds', 'download_time_in_seconds', 'total_time_in_seconds'] as $name) {
            $timings[] = "'{$name}', max((metrics->>'{$name}')::double precision) FILTER (WHERE jsonb_typeof(metrics->'{$name}') = 'number')";
        }
        $metrics = implode(', ', $timings);
        /** @var list<object{check_id: int, bucket_start: string, maximum_ms: float|null, metric_maxima: string, failed_count: int, errored_count: int, warning_count: int}> $rows */
        $rows = DB::select(<<<SQL
            SELECT {$group} AS check_id, date_bin(interval '{$bucketMinutes} minutes', ended_at, ?::timestamptz)::text AS bucket_start,
                1000 * max((metrics->>'total_time_in_seconds')::double precision)
                    FILTER (WHERE jsonb_typeof(metrics->'total_time_in_seconds') = 'number') AS maximum_ms,
                jsonb_build_object({$metrics})::text AS metric_maxima,
                count(*) FILTER (WHERE result = 'failed')::int AS failed_count,
                count(*) FILTER (WHERE result = 'errored-or-timed-out')::int AS errored_count,
                count(*) FILTER (WHERE result = 'warning')::int AS warning_count
            FROM check_runs WHERE check_id IN ({$placeholders}) AND ended_at >= ? AND ended_at < ?
            GROUP BY 1, 2 ORDER BY 1, 2
            SQL, [$from, ...$checkIds, $from, $until]);
        $groups = array_fill_keys($combine ? [0] : $checkIds, []);
        foreach ($rows as $row) {
            /** @var array<string, int|float|null> $maxima */
            $maxima = json_decode($row->metric_maxima, true, flags: JSON_THROW_ON_ERROR);
            $groups[$row->check_id][] = [
                'bucket_start' => CarbonImmutable::parse($row->bucket_start)->utc(), 'maximum_ms' => $row->maximum_ms,
                'metric_maxima' => $maxima,
                'failed_count' => $row->failed_count, 'errored_count' => $row->errored_count, 'warning_count' => $row->warning_count,
            ];
        }

        return $groups;
    }

    /**
     * Raw partition reads are bounded to 24h and retention; the indexed owner/time predicate excludes other windows.
     *
     * @return list<array{bucket_start: CarbonImmutable, succeeded_count: int, warning_count: int, failed_count: int, errored_count: int, completed_count: int, uptime_percentage: ?float, metric_sums: array<string, int|float>, metric_counts: array<string, int|float>, metric_averages: array<string, float>}>
     */
    public function recent(int $checkId, CarbonImmutable $from, CarbonImmutable $until): array
    {
        return $this->recentMany([$checkId], $from, $until)[$checkId];
    }

    /**
     * Same minute projection as recent(), in one query for a page of checks.
     * Empty checks have no samples, not a synthetic successful observation.
     *
     * @param  list<int>  $checkIds
     * @return array<int, list<array{bucket_start: CarbonImmutable, succeeded_count: int, warning_count: int, failed_count: int, errored_count: int, completed_count: int, uptime_percentage: ?float, metric_sums: array<string, int|float>, metric_counts: array<string, int|float>, metric_averages: array<string, float>}>>
     */
    public function recentMany(array $checkIds, CarbonImmutable $from, CarbonImmutable $until): array
    {
        return $this->recentGroups($checkIds, $from, $until, 'check_id');
    }

    /**
     * One aggregate minute series across authorized checks, with weighted metrics and no combined p95.
     *
     * @param  list<int>  $checkIds
     * @return list<SeriesBucket>
     */
    public function recentForChecks(array $checkIds, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $groups = $this->recentGroups($checkIds, $from, $until, '0');

        return $groups[0] ?? [];
    }

    /**
     * @param  list<int>  $checkIds
     * @return array<int, list<SeriesBucket>>
     */
    public function recentByClient(array $checkIds, CarbonImmutable $from, CarbonImmutable $until): array
    {
        return $this->recentGroups($checkIds, $from, $until, 'client_id');
    }

    /**
     * @param  list<int>  $checkIds
     * @return array<int, list<SeriesBucket>>
     */
    private function recentGroups(array $checkIds, CarbonImmutable $from, CarbonImmutable $until, string $group): array
    {
        $this->validateRecentWindow($from, $until);
        if ($checkIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($checkIds), '?'));
        if (! in_array($group, ['0', 'check_id', 'client_id'], true)) {
            throw new InvalidArgumentException('Unknown recent history grouping.');
        }
        $groupExpression = $group === 'client_id' ? 'sites.client_id' : ($group === '0' ? '0' : 'check_runs.check_id');
        $joins = $group === 'client_id' ? 'JOIN checks ON checks.id = check_runs.check_id JOIN sites ON sites.id = checks.site_id' : '';
        /** @var list<object{check_id: int, bucket_start: string, succeeded_count: int, warning_count: int, failed_count: int, errored_count: int, metric_sums: string, metric_counts: string}> $rows */
        // Recursive JSON cardinality estimates trigger JIT compilation even for one day of minute samples.
        $rows = DB::transaction(function () use ($groupExpression, $joins, $placeholders, $checkIds, $from, $until): array {
            /** @var object{enabled: string} $jit */
            $jit = DB::selectOne("SELECT current_setting('jit') AS enabled");
            DB::statement('SET LOCAL jit = off');
            $rows = DB::select(<<<SQL
            WITH RECURSIVE samples AS MATERIALIZED (
                SELECT {$groupExpression} AS check_id, result, metrics, date_trunc('minute', ended_at, 'UTC') AS bucket FROM check_runs {$joins}
                WHERE check_runs.check_id IN ({$placeholders}) AND ended_at >= ? AND ended_at < ?
            ), counts AS (
                SELECT check_id, bucket, count(*) FILTER (WHERE result = 'succeeded')::int AS succeeded_count,
                    count(*) FILTER (WHERE result = 'warning')::int AS warning_count, count(*) FILTER (WHERE result = 'failed')::int AS failed_count,
                    count(*) FILTER (WHERE result = 'errored-or-timed-out')::int AS errored_count FROM samples GROUP BY check_id, bucket
            ), leaves AS (
                SELECT check_id, bucket, ARRAY[]::text[] AS path, metrics AS value FROM samples WHERE metrics IS NOT NULL
                UNION ALL SELECT leaves.check_id, leaves.bucket, leaves.path || item.key, item.value FROM leaves CROSS JOIN LATERAL
                    jsonb_each(CASE WHEN jsonb_typeof(leaves.value) = 'object' THEN leaves.value ELSE '{}'::jsonb END) item
            ), metrics AS (
                SELECT check_id, bucket, array_to_string(path, '.') AS name, sum(value::text::double precision) AS sum, count(*) AS count
                FROM leaves WHERE jsonb_typeof(value) = 'number' GROUP BY check_id, bucket, path
            ), objects AS (
                SELECT check_id, bucket, jsonb_object_agg(name, sum) AS sums, jsonb_object_agg(name, count) AS counts FROM metrics GROUP BY check_id, bucket
            ) SELECT check_id, bucket::text AS bucket_start, succeeded_count, warning_count, failed_count, errored_count,
                coalesce(sums, '{}'::jsonb)::text AS metric_sums, coalesce(objects.counts, '{}'::jsonb)::text AS metric_counts
            FROM counts LEFT JOIN objects USING (check_id, bucket) ORDER BY check_id, bucket
            SQL, [...$checkIds, $from, $until]);
            // Restore the caller's setting when Laravel nests this read inside an existing transaction.
            DB::statement('SET LOCAL jit = '.$jit->enabled);

            return $rows;
        });
        $groups = array_fill_keys($group === '0' ? [0] : ($group === 'check_id' ? $checkIds : []), []);
        foreach ($rows as $row) {
            $groups[$row->check_id][] = $row;
        }

        return array_map($this->projectSeries(...), $groups);
    }

    /**
     * @param  list<SeriesBucket>  $buckets
     * @return array{uptime_percentage: ?float, metric_averages: array<string, float>}
     */
    public function summarize(array $buckets): array
    {
        $completed = 0;
        $online = 0;
        $sums = [];
        $counts = [];
        foreach ($buckets as $bucket) {
            $completed += $bucket['completed_count'];
            $online += $bucket['succeeded_count'] + $bucket['warning_count'];
            foreach ($bucket['metric_sums'] as $name => $sum) {
                if (! isset($bucket['metric_counts'][$name]) || $bucket['metric_counts'][$name] <= 0) {
                    throw new RuntimeException('Rollup metric sum has no positive sample count: '.$name);
                }
                $sums[$name] = ($sums[$name] ?? 0) + $sum;
                $counts[$name] = ($counts[$name] ?? 0) + $bucket['metric_counts'][$name];
            }
        }
        $averages = [];
        foreach ($sums as $name => $sum) {
            $averages[$name] = (float) ($sum / $counts[$name]);
        }

        return ['uptime_percentage' => $completed === 0 ? null : 100.0 * $online / $completed, 'metric_averages' => $averages];
    }

    private function validateRecentWindow(CarbonImmutable $from, CarbonImmutable $until): void
    {
        $days = RetentionPolicy::fromConfig()->rawDays;
        if ($from->lessThan(CarbonImmutable::now('UTC')->subDays($days))) {
            throw ValidationException::withMessages(['filter.start' => __('api.minute_retention', ['days' => $days])]);
        }
        if ($from->greaterThanOrEqualTo($until) || $from->diffInHours($until) > 24) {
            throw ValidationException::withMessages(['filter.end' => __('api.minute_range')]);
        }
    }

    /**
     * @param  list<object{bucket_start: string, succeeded_count: int, warning_count: int, failed_count: int, errored_count: int, metric_sums: string, metric_counts: string}>  $rows
     * @return list<array{bucket_start: CarbonImmutable, succeeded_count: int, warning_count: int, failed_count: int, errored_count: int, completed_count: int, uptime_percentage: ?float, metric_sums: array<string, int|float>, metric_counts: array<string, int|float>, metric_averages: array<string, float>}>
     */
    private function projectSeries(array $rows): array
    {
        $series = [];
        foreach ($rows as $row) {
            $completed = $row->succeeded_count + $row->warning_count + $row->failed_count + $row->errored_count;
            $sums = $this->numericObject($row->metric_sums);
            $counts = $this->numericObject($row->metric_counts);
            $averages = [];
            foreach ($sums as $key => $sum) {
                if (! isset($counts[$key]) || $counts[$key] <= 0) {
                    throw new RuntimeException('Metric sum has no positive count: '.$key);
                }
                $averages[$key] = $sum / $counts[$key];
            }
            $series[] = ['bucket_start' => CarbonImmutable::parse($row->bucket_start)->utc(), 'succeeded_count' => $row->succeeded_count,
                'warning_count' => $row->warning_count, 'failed_count' => $row->failed_count, 'errored_count' => $row->errored_count,
                'completed_count' => $completed, 'uptime_percentage' => $completed === 0 ? null : 100.0 * ($row->succeeded_count + $row->warning_count) / $completed,
                'metric_sums' => $sums, 'metric_counts' => $counts, 'metric_averages' => $averages];
        }

        return $series;
    }

    /** @return array<string, int|float> */
    private function numericObject(string $json): array
    {
        $values = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($values)) {
            throw new RuntimeException('Rollup metrics must be a numeric object.');
        }
        foreach ($values as $key => $value) {
            if (! is_string($key) || (! is_int($value) && ! is_float($value))) {
                throw new RuntimeException('Rollup metrics must contain named numeric values.');
            }
        }

        return $values;
    }
}
