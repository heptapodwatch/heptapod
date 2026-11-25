<?php

declare(strict_types=1);

namespace Heptapod\Queries;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Heptapod\Checks\History\RetentionPolicy;
use Heptapod\Checks\History\RollupReader;
use Heptapod\Contracts\CheckKey;
use Heptapod\Data\Ui\MonthlyUptimeData;
use Heptapod\Data\Ui\ResponseTimeBucketData;
use Heptapod\Data\Ui\ResponseTimeSeriesData;
use Heptapod\Data\Ui\UptimeBucketData;
use Heptapod\Data\Ui\UptimeMonthData;
use Heptapod\Enums\BucketStatus;
use Heptapod\Enums\ResponseTimeRange;
use Heptapod\Maintenance\MaintenanceTargets;
use Heptapod\Models\Check;
use Heptapod\Models\User;
use Heptapod\Policies\SitePolicy;
use Illuminate\Support\Facades\DB;

final readonly class HistoryReader
{
    /** "All" reads at most three years of daily rollups (156 weeks). */
    private const int AllDays = 1092;

    public function __construct(private SitePolicy $policy, private RollupReader $history, private UptimeReader $uptimeReader) {}

    /** @return array{uptime: list<UptimeBucketData>, uptime_percentage: ?float} */
    public function read(User $user, ?int $siteId = null, ?int $checkId = null): array
    {
        // The validated 92-day hourly minimum preserves all 90 local days, including UTC+14 boundaries.
        RetentionPolicy::fromConfig();
        $sites = $this->policy->visibleSites($user, includeArchived: $siteId !== null)->getQuery()->select('sites.id');
        if ($siteId !== null) {
            $sites->where('sites.id', $siteId);
        }
        $checks = Check::query()->getQuery()->whereIn('site_id', clone $sites);
        if ($checkId === null) {
            $checks->where('type', CheckKey::Uptime);
        } else {
            $checks->where('id', $checkId);
        }
        /** @var list<int> $checkIds */
        $checkIds = $checks->pluck('id')->all();
        $checkScopeSql = $checks->select('checks.id', 'checks.type', 'checks.interval_seconds')->toSql();
        $timezone = $user->resolvedTimezone();
        $until = CarbonImmutable::now('UTC');
        $percentage = $this->uptimeReader->percentage($checkIds, $until->subDay(), $until);
        $localEnd = $until->setTimezone($timezone)->startOfDay();
        // Construct each local midnight independently so DST transitions (including skipped midnights) never drift.
        $dayStarts = [];
        for ($offset = 90; $offset >= 0; $offset--) {
            $dayStarts[] = $localEnd->subDays($offset)->startOfDay();
        }
        $from = $dayStarts[0];
        $end = $dayStarts[90];
        $daily = $this->history->seriesForChecks($checkIds, 'day', $from, $end, $timezone);
        $byTime = [];
        foreach ($daily as $bucket) {
            $byTime[$bucket['bucket_start']->toIso8601String()] = $bucket;
        }
        $bounds = [];
        for ($index = 0; $index < 90; $index++) {
            $day = $dayStarts[$index];
            $nextDay = $dayStarts[$index + 1];
            $bounds[] = [
                // Exact local midnights in UTC drive labels, incident/maintenance overlaps, and uptime percentages.
                'date' => $day->utc()->toIso8601String(),
                'until' => $nextDay->utc()->toIso8601String(),
                // Hourly rollups are UTC-hour aligned; keep the floored-hour lookup separate from exact day bounds.
                'rollup_from' => $day->utc()->startOfHour()->toIso8601String(),
                'rollup_until' => $nextDay->utc()->startOfHour()->toIso8601String(),
            ];
        }
        $siteScope = clone $sites;
        $scopeSql = $siteScope->addSelect('sites.client_id')->toSql();
        [$scopedMaintenanceSql, $scopedMaintenanceBindings] = MaintenanceTargets::coveredBySitesSql('visible_sites', $from, $end);
        /** @var list<object{date: string, maintenance: bool, incident_count: int, outage: bool, failed_seconds: int}> $intervals */
        $intervals = DB::select(<<<SQL
            WITH visible_sites AS MATERIALIZED ({$scopeSql}), visible_checks AS MATERIALIZED ({$checkScopeSql}), buckets AS (
                SELECT * FROM jsonb_to_recordset(?::jsonb) AS bounds(date text, until text, rollup_from text, rollup_until text)
            ), scoped_incidents AS MATERIALIZED (
                SELECT i.* FROM incidents i JOIN checks c ON c.id = i.check_id
                    JOIN visible_sites s ON s.id = i.site_id AND s.id = c.site_id
                WHERE i.first_failure_at < ?::timestamptz
                    AND (i.resolved_at IS NULL OR i.resolved_at > ?::timestamptz)
            ), scoped_maintenance AS MATERIALIZED (
                {$scopedMaintenanceSql}
            ) SELECT date,
                (SELECT coalesce(sum(r.failed_count::bigint * c.interval_seconds), 0)::bigint
                    FROM check_run_rollups_hourly r JOIN visible_checks c ON c.id = r.check_id
                    WHERE c.type = 'uptime' AND r.bucket_start >= buckets.rollup_from::timestamptz
                        AND r.bucket_start < buckets.rollup_until::timestamptz) AS failed_seconds,
                EXISTS (SELECT 1 FROM scoped_incidents i JOIN visible_checks c ON c.id = i.check_id
                    WHERE c.type = 'uptime' AND i.cause = 'failed'
                        AND i.first_failure_at < buckets.until::timestamptz
                        AND (i.resolved_at IS NULL OR i.resolved_at > buckets.date::timestamptz)) AS outage,
                EXISTS (SELECT 1 FROM scoped_maintenance m
                    WHERE m.starts_at < buckets.until::timestamptz AND m.ends_at > buckets.date::timestamptz
                        AND NOT EXISTS (SELECT 1 FROM maintenance_site_exclusions ex WHERE ex.maintenance_period_id = m.id
                            AND ex.site_id = m.site_id AND ex.left_at <= buckets.date::timestamptz)) AS maintenance,
                (SELECT count(*)::int FROM scoped_incidents i
                    WHERE i.first_failure_at < buckets.until::timestamptz
                        AND (i.resolved_at IS NULL OR i.resolved_at > buckets.date::timestamptz)) AS incident_count
            FROM buckets ORDER BY date
            SQL, [...$sites->getBindings(), ...$checks->getBindings(), json_encode($bounds, JSON_THROW_ON_ERROR),
            $end->utc()->format('Y-m-d H:i:s.uP'), $from->utc()->format('Y-m-d H:i:s.uP'), ...$scopedMaintenanceBindings]);
        $uptime = [];
        $uptimeWindows = [];
        foreach ($intervals as $index => $interval) {
            $uptimeWindows[] = [CarbonImmutable::parse($interval->date), CarbonImmutable::parse($bounds[$index]['until'])];
        }
        $dailyUptime = $this->uptimeReader->series($checkIds, $uptimeWindows);
        foreach ($intervals as $index => $interval) {
            $bucket = $byTime[$bounds[$index]['rollup_from']] ?? null;
            $succeeded = $bucket['succeeded_count'] ?? 0;
            $warning = $bucket['warning_count'] ?? 0;
            $failed = $bucket['failed_count'] ?? 0;
            $errored = $bucket['errored_count'] ?? 0;
            // Rollups retain counts, not historical intervals: estimate each failed run using its check's current interval.
            $status = BucketStatus::forUptime($interval->failed_seconds, $interval->outage, $errored, $warning, $succeeded, $interval->maintenance);
            $uptime[] = new UptimeBucketData($interval->date, $dailyUptime[$index], $succeeded, $warning, $failed, $errored, $status, $interval->incident_count, $interval->failed_seconds);
        }

        return ['uptime' => $uptime, 'uptime_percentage' => $percentage];
    }

    /**
     * One range of one check's response time: average and 95th percentile per bucket. Each range reads one bounded index
     * range; past 7 days buckets are UTC days or weeks (local days would need hourly rollups, which deletion may remove).
     */
    public function responseTime(int $checkId, ResponseTimeRange $range): ResponseTimeSeriesData
    {
        $now = CarbonImmutable::now('UTC');
        $today = $now->startOfDay();
        $metric = 'total_time_in_seconds';
        $ms = static fn (int|float|null $seconds): ?float => $seconds === null ? null : 1000 * $seconds;
        $points = [];
        if ($range === ResponseTimeRange::Hours24) {
            foreach ($this->history->responseSamples($checkId, $now->subDay(), $now) as $bucket) {
                $points[] = new ResponseTimeBucketData($bucket['bucket_start']->toIso8601String(), $bucket['average_ms'], $bucket['p95_ms'],
                    $bucket['failed_count'], $bucket['errored_count'], $bucket['warning_count']);
            }
        } elseif ($range === ResponseTimeRange::All) {
            foreach ($this->history->weekly($checkId, [$metric], $today->subDays(self::AllDays)->startOfWeek(CarbonInterface::MONDAY), $today) as $bucket) {
                $points[] = new ResponseTimeBucketData($bucket['bucket_start']->toIso8601String(),
                    $ms($bucket['metric_averages'][$metric] ?? null), $ms($bucket['metric_p95_max'][$metric] ?? null));
            }
        } else {
            [$period, $from, $until] = match ($range) {
                ResponseTimeRange::Days7 => ['hourly', $now->startOfHour()->subDays(7), $now->startOfHour()],
                ResponseTimeRange::Days30 => ['daily', $today->subDays(30), $today],
                ResponseTimeRange::Days90 => ['daily', $today->subDays(90), $today],
                ResponseTimeRange::Year1 => ['daily', $today->subDays(365), $today],
            };
            foreach ($this->history->read($checkId, $period, $from, $until) as $bucket) {
                $points[] = new ResponseTimeBucketData($bucket['bucket_start']->toIso8601String(),
                    $ms($bucket['metric_averages'][$metric] ?? null), $ms($bucket['metric_p95'][$metric] ?? null));
            }
        }
        $recent = $this->history->summarize($this->history->recentForChecks([$checkId], $now->subMinutes(15), $now));
        $average = $recent['metric_averages'][$metric] ?? null;

        return new ResponseTimeSeriesData($range, $points, $average === null ? null : round(1000 * $average, 2));
    }

    /**
     * Calendar months in the viewer's time zone since the check's first result, the newest 24 drawn. Each month's
     * interrupted time and uptime come from the same incident intervals; null before the first result.
     */
    public function monthlyUptime(Check $check, string $timezone): ?MonthlyUptimeData
    {
        if ($check->first_result_at === null) {
            return null;
        }
        $now = CarbonImmutable::now('UTC');
        $since = $check->first_result_at->utc();
        $start = $since->setTimezone($timezone)->startOfMonth()->max($now->setTimezone($timezone)->startOfMonth()->subMonths(23));
        $windows = [];
        for ($month = $start; $month->lessThan($now); $month = $month->addMonth()->startOfMonth()) {
            $windows[] = [$month->utc(), $month->addMonth()->startOfMonth()->utc()->min($now)];
        }
        $months = [];
        foreach ($this->uptimeReader->seriesWithDowntime([$check->id], $windows) as $index => $month) {
            $months[] = new UptimeMonthData($windows[$index][0]->setTimezone($timezone)->toIso8601String(), $month['uptime'], $month['downtime_seconds']);
        }
        $ranges = $this->uptimeReader->percentagesForRanges([$check->id], ['days90' => $now->subDays(90), 'days365' => $now->subDays(365), 'all' => $since], $now);

        return new MonthlyUptimeData($since->toIso8601String(), $months, $ranges['days90'], $ranges['days365'], $ranges['all']);
    }
}
