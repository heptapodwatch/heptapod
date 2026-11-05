<?php

declare(strict_types=1);

namespace Heptapod\Queries;

use Carbon\CarbonImmutable;
use Heptapod\Checks\History\RetentionPolicy;
use Heptapod\Checks\History\RollupReader;
use Heptapod\Contracts\CheckKey;
use Heptapod\Data\Ui\ResponseTimeBucketData;
use Heptapod\Data\Ui\ResponseTimeSeriesData;
use Heptapod\Data\Ui\UptimeBucketData;
use Heptapod\Enums\BucketStatus;
use Heptapod\Enums\ResponseTimeRange;
use Heptapod\Maintenance\MaintenanceTargets;
use Heptapod\Models\Check;
use Heptapod\Models\User;
use Heptapod\Policies\SitePolicy;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Support\Facades\DB;

final readonly class HistoryReader
{
    public function __construct(private SitePolicy $policy, private RollupReader $history, private UptimeReader $uptimeReader, private InstanceSettings $settings) {}

    /** @return array{uptime: list<UptimeBucketData>, response_times: list<ResponseTimeSeriesData>, uptime_percentage: ?float} */
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
        $until = CarbonImmutable::now('UTC');
        $percentage = $this->uptimeReader->percentage($checkIds, $until->subDay(), $until);
        $end = $until->setTimezone($this->settings->timezone)->startOfDay();
        $from = $end->subDays(90);
        $daily = $this->history->seriesForChecks($checkIds, 'day', $from, $end, $this->settings->timezone);
        $byTime = [];
        foreach ($daily as $bucket) {
            $byTime[$bucket['bucket_start']->toIso8601String()] = $bucket;
        }
        $bounds = [];
        for ($day = $from; $day->lessThan($end); $day = $day->addDay()) {
            // Match RollupReader's floored UTC-hour boundaries, including fractional-offset timezones.
            $bounds[] = ['date' => $day->utc()->startOfHour()->toIso8601String(), 'until' => $day->addDay()->utc()->startOfHour()->toIso8601String()];
        }
        $siteScope = clone $sites;
        $scopeSql = $siteScope->addSelect('sites.client_id')->toSql();
        [$coversSite, $coversSiteBindings] = MaintenanceTargets::coversSiteSql('m', 's');
        /** @var list<object{date: string, maintenance: bool, incident_count: int, outage: bool, failed_seconds: int}> $intervals */
        $intervals = DB::select(<<<SQL
            WITH visible_sites AS MATERIALIZED ({$scopeSql}), visible_checks AS MATERIALIZED ({$checkScopeSql}), buckets AS (
                SELECT * FROM jsonb_to_recordset(?::jsonb) AS bounds(date text, until text)
            ), scoped_incidents AS MATERIALIZED (
                SELECT i.* FROM incidents i JOIN checks c ON c.id = i.check_id
                    JOIN visible_sites s ON s.id = i.site_id AND s.id = c.site_id
                WHERE i.first_failure_at < ?::timestamptz
                    AND (i.resolved_at IS NULL OR i.resolved_at > ?::timestamptz)
            ) SELECT date,
                (SELECT coalesce(sum(r.failed_count::bigint * c.interval_seconds), 0)::bigint
                    FROM check_run_rollups_hourly r JOIN visible_checks c ON c.id = r.check_id
                    WHERE c.type = 'uptime' AND r.bucket_start >= buckets.date::timestamptz
                        AND r.bucket_start < buckets.until::timestamptz) AS failed_seconds,
                EXISTS (SELECT 1 FROM scoped_incidents i JOIN visible_checks c ON c.id = i.check_id
                    WHERE c.type = 'uptime' AND i.cause = 'failed'
                        AND i.first_failure_at < buckets.until::timestamptz
                        AND (i.resolved_at IS NULL OR i.resolved_at > buckets.date::timestamptz)) AS outage,
                EXISTS (SELECT 1 FROM maintenance_periods m JOIN visible_sites s ON
                    {$coversSite}
                    WHERE m.starts_at < buckets.until::timestamptz AND m.ends_at > buckets.date::timestamptz
                        AND NOT EXISTS (SELECT 1 FROM maintenance_site_exclusions ex WHERE ex.maintenance_period_id = m.id
                            AND ex.site_id = s.id AND ex.left_at <= buckets.date::timestamptz)) AS maintenance,
                (SELECT count(*)::int FROM scoped_incidents i
                    WHERE i.first_failure_at < buckets.until::timestamptz
                        AND (i.resolved_at IS NULL OR i.resolved_at > buckets.date::timestamptz)) AS incident_count
            FROM buckets ORDER BY date
            SQL, [...$sites->getBindings(), ...$checks->getBindings(), json_encode($bounds, JSON_THROW_ON_ERROR),
            $end->utc()->startOfHour(), $from->utc()->startOfHour(), ...$coversSiteBindings]);
        $uptime = [];
        $uptimeWindows = [];
        foreach ($intervals as $interval) {
            $bucketStart = CarbonImmutable::parse($interval->date);
            $uptimeWindows[] = [$bucketStart, CarbonImmutable::parse($bounds[count($uptimeWindows)]['until'])];
        }
        $dailyUptime = $this->uptimeReader->series($checkIds, $uptimeWindows);
        foreach ($intervals as $index => $interval) {
            $bucket = $byTime[$interval->date] ?? null;
            $succeeded = $bucket['succeeded_count'] ?? 0;
            $warning = $bucket['warning_count'] ?? 0;
            $failed = $bucket['failed_count'] ?? 0;
            $errored = $bucket['errored_count'] ?? 0;
            // Rollups retain counts, not historical intervals: estimate each failed run using its check's current interval.
            $status = BucketStatus::forUptime($interval->failed_seconds, $interval->outage, $errored, $warning, $succeeded, $interval->maintenance);
            $uptime[] = new UptimeBucketData($interval->date, $dailyUptime[$index], $succeeded, $warning, $failed, $errored, $status, $interval->incident_count, $interval->failed_seconds);
        }
        if ($checkId === null) {
            return ['uptime' => $uptime, 'response_times' => [], 'uptime_percentage' => $percentage];
        }

        $recent = $this->history->responsePeaks($checkIds, $until->subDay(), $until, 5, combine: true)[0] ?? [];
        $recentSummary = $this->history->summarize($this->history->recentForChecks($checkIds, $until->subMinutes(15), $until));
        $average = $recentSummary['metric_averages']['total_time_in_seconds'] ?? null;
        $recentAverage = $average === null ? null : round(1000 * $average, 2);
        $points = [];
        foreach ($intervals as $interval) {
            $average = $byTime[$interval->date]['metric_averages']['total_time_in_seconds'] ?? null;
            $points[] = new ResponseTimeBucketData($interval->date, $average === null ? null : 1000 * $average, null);
        }
        $minutePoints = [];
        foreach ($recent as $bucket) {
            $minutePoints[] = new ResponseTimeBucketData($bucket['bucket_start']->toIso8601String(), null, null,
                $bucket['maximum_ms'], $bucket['failed_count'], $bucket['errored_count'], $bucket['warning_count']);
        }
        $responses = [new ResponseTimeSeriesData(ResponseTimeRange::Hours24, $minutePoints, $recentAverage)];
        foreach ([[ResponseTimeRange::Days7, 7], [ResponseTimeRange::Days30, 30], [ResponseTimeRange::Days90, 90]] as [$range, $days]) {
            $responses[] = new ResponseTimeSeriesData($range, array_slice($points, -$days), $recentAverage);
        }

        return ['uptime' => $uptime, 'response_times' => $responses, 'uptime_percentage' => $percentage];
    }
}
