<?php

declare(strict_types=1);

namespace Heptapod\Addons\CertificateHealth;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\MonitorCountProvider;
use Heptapod\Contracts\MonitorCounts;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

final readonly class CertificateMonitorCounts implements MonitorCountProvider
{
    public function counts(array $visibleSiteIds, CarbonImmutable $now, CarbonImmutable $until): MonitorCounts
    {
        if ($visibleSiteIds === []) {
            return new MonitorCounts;
        }

        $count = DB::table('checks')->whereIntegerInRaw('checks.site_id', $visibleSiteIds)
            ->where('checks.type', 'certificate_health')->where('checks.enabled', true)
            ->join('certificate_health_snapshots as observed', function (JoinClause $join): void {
                $join->on('observed.check_id', '=', 'checks.id')
                    ->whereRaw("observed.id = (SELECT id FROM certificate_health_snapshots WHERE check_id = checks.id AND run_ended_at IS NOT NULL AND snapshot->'certificate_details'->>'valid_until' IS NOT NULL ORDER BY run_ended_at DESC, run_id DESC LIMIT 1)");
            })
            ->whereRaw("(observed.snapshot->'certificate_details'->>'valid_until')::timestamptz <= ?", [$until->toIso8601String()])
            ->count();

        return new MonitorCounts(certificates: $count);
    }
}
