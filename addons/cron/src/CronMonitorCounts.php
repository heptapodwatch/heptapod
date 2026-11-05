<?php

declare(strict_types=1);

namespace Heptapod\Addons\Cron;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\MonitorCountProvider;
use Heptapod\Contracts\MonitorCounts;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

final readonly class CronMonitorCounts implements MonitorCountProvider
{
    public function counts(array $visibleSiteIds, CarbonImmutable $now, CarbonImmutable $until): MonitorCounts
    {
        if ($visibleSiteIds === []) {
            return new MonitorCounts;
        }

        $row = DB::table('cron_checks')->join('checks', 'checks.id', '=', 'cron_checks.check_id')
            ->whereIntegerInRaw('checks.site_id', $visibleSiteIds)->where('checks.enabled', true)->where('checks.type', 'cron')
            ->selectRaw("count(*)::int AS total, count(*) FILTER (WHERE cron_checks.latest_result <> 'pingFailed' AND (cron_checks.latest_result = 'pingMissed' OR cron_checks.next_due_at + make_interval(mins => coalesce(cron_checks.grace_time_in_minutes, ?)) <= ?))::int AS overdue", [Config::integer('cron.default_grace_time_in_minutes'), $now->toIso8601String()])
            ->first();
        if ($row === null) {
            throw new \LogicException('Cron aggregate query must return one row.');
        }

        if (! is_int($row->total) || ! is_int($row->overdue)) {
            throw new \LogicException('Cron aggregate query returned non-integer counts.');
        }

        return new MonitorCounts(scheduledTasks: $row->total, overduePings: $row->overdue);
    }
}
