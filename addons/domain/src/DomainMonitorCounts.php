<?php

declare(strict_types=1);

namespace Heptapod\Addons\Domain;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\MonitorCountProvider;
use Heptapod\Contracts\MonitorCounts;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

final readonly class DomainMonitorCounts implements MonitorCountProvider
{
    public function counts(array $visibleSiteIds, CarbonImmutable $now, CarbonImmutable $until): MonitorCounts
    {
        if ($visibleSiteIds === []) {
            return new MonitorCounts;
        }

        $count = DB::table('checks')->whereIntegerInRaw('checks.site_id', $visibleSiteIds)
            ->where('checks.type', 'domain')->where('checks.enabled', true)
            ->join('domain_snapshots as observed', function (JoinClause $join): void {
                $join->on('observed.check_id', '=', 'checks.id')
                    ->whereRaw("observed.id = (SELECT id FROM domain_snapshots WHERE check_id = checks.id AND run_ended_at IS NOT NULL AND snapshot->>'expires_at' IS NOT NULL ORDER BY run_ended_at DESC, run_id DESC LIMIT 1)");
            })
            ->whereRaw("CASE WHEN (observed.snapshot->>'expires_at') ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' THEN ((observed.snapshot->>'expires_at')::date::timestamp AT TIME ZONE 'UTC') ELSE (observed.snapshot->>'expires_at')::timestamptz END <= ?", [$until->toIso8601String()])
            ->count();

        return new MonitorCounts(domains: $count);
    }
}
