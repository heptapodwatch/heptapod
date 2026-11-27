<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Contracts\CheckHistoryData;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Lazy\ClosureLazy;
use Spatie\LaravelData\Support\Lazy\InertiaDeferred;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Props of GET /sites/{id}. Every prop is a closure so partial reloads evaluate only the requested keys; the launchpad numbers and the alert line arrive deferred. */
#[TypeScript]
final class SiteOverviewPageData extends Data
{
    /**
     * @param  ClosureLazy|list<UptimeBucketData>  $history
     * @param  ClosureLazy|IncidentData|null  $open_uptime_incident  the uptime check's open incident, for the Down banner
     * @param  ClosureLazy|list<RunAllCheckData>  $run_all_checks
     * @param  ClosureLazy|array{hours24: ?float, days7: ?float, days30: ?float, days90: ?float}  $uptime_ranges
     * @param  ClosureLazy|array{queued: int, cooldown?: int, unavailable: int, results: list<array{check_id: int, status: string, reason: string|null}>}|null  $run_all_result
     * @param  ClosureLazy|list<SiteRepositoryData>  $repositories
     * @param  InertiaDeferred|int|null  $alert_channels  channels an uptime outage would reach now; null without an enabled uptime check
     */
    public function __construct(
        public readonly ClosureLazy|SiteRowData $site,
        public readonly ClosureLazy|SiteIdentityData $header,
        public readonly ClosureLazy|array $history,
        public readonly ClosureLazy|IncidentData|null $open_uptime_incident,
        public readonly ClosureLazy|ClientData $client,
        public readonly ClosureLazy|array $run_all_checks,
        public readonly ClosureLazy|bool $can_manage,
        public readonly ClosureLazy|array $uptime_ranges,
        public readonly ClosureLazy|array|null $run_all_result,
        public readonly ClosureLazy|MaintenanceSiteHeaderData|null $maintenance,
        /** The Performance tab's latest hourly average for the Response card; null without an uptime check or a measured hour. */
        public readonly InertiaDeferred|float|null $response_ms,
        public readonly InertiaDeferred|int|null $alert_channels,
        /** The Lighthouse tab's own score history for the launchpad card; null without an enabled Lighthouse check. */
        public readonly InertiaDeferred|CheckHistoryData|null $lighthouse,
        public readonly ClosureLazy|bool $can_move = false,
        public readonly ClosureLazy|string|null $resume_at = null,
        public readonly ClosureLazy|array $repositories = [],
        /** The server the site runs on (the servers addon, SiteServers), only for those who may see servers; null while unlinked. */
        #[LiteralTypeScriptType('Heptapod.Addons.Servers.Data.ServerRowData | null')]
        public readonly ClosureLazy|Data|null $server = null,
    ) {}
}
