<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Lazy\ClosureLazy;
use Spatie\LaravelData\Support\Lazy\InertiaDeferred;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Props of GET /sites/{id}. Every prop is a closure so partial reloads evaluate only the requested keys; the record lines and the alert line arrive deferred. */
#[TypeScript]
final class SiteOverviewPageData extends Data
{
    /**
     * @param  ClosureLazy|list<UptimeBucketData>  $history
     * @param  ClosureLazy|list<EventData>  $events
     * @param  ClosureLazy|list<IncidentData>  $incidents
     * @param  ClosureLazy|list<RunAllCheckData>  $run_all_checks
     * @param  ClosureLazy|array{hours24: ?float, days7: ?float, days30: ?float, days90: ?float}  $uptime_ranges
     * @param  ClosureLazy|array{queued: int, unavailable: int, results: list<array{check_id: int, status: string, reason: string|null}>}|null  $run_all_result
     * @param  InertiaDeferred|list<UpcomingItemData>  $upcoming  the next certificate expiry, domain expiry and maintenance window
     * @param  InertiaDeferred|int|null  $alert_channels  channels an uptime outage would reach now; null without an enabled uptime check
     */
    public function __construct(
        public readonly ClosureLazy|SiteRowData $site,
        public readonly ClosureLazy|SiteIdentityData $header,
        public readonly ClosureLazy|array $history,
        public readonly ClosureLazy|array $events,
        public readonly ClosureLazy|array $incidents,
        public readonly ClosureLazy|ClientData $client,
        public readonly ClosureLazy|array $run_all_checks,
        public readonly ClosureLazy|bool $can_manage,
        public readonly ClosureLazy|array $uptime_ranges,
        public readonly ClosureLazy|array|null $run_all_result,
        public readonly ClosureLazy|MaintenanceSiteHeaderData|null $maintenance,
        public readonly InertiaDeferred|SiteRecordData|null $record,
        public readonly InertiaDeferred|array $upcoming,
        public readonly InertiaDeferred|int|null $alert_channels,
        public readonly ClosureLazy|bool $can_move = false,
    ) {}
}
