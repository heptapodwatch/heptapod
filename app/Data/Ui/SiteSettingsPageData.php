<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Lazy\InertiaLazy;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class SiteSettingsPageData extends Data
{
    /**
     * @param  list<ClientData>  $clients
     * @param  list<RunAllCheckData>  $run_all_checks
     * @param  array{minutes_min: int, minutes_max: int, stored_minutes_min: int, stored_minutes_max: int, recovery_min: int, recovery_max: int}  $alert_sensitivity_bounds
     * @param  list<AlertSensitivityData>  $alert_sensitivity_presets
     */
    public function __construct(
        public readonly SiteRowData $site,
        public readonly ClientData $client,
        public readonly ?string $resume_at,
        public readonly int $check_count,
        public readonly int $incident_count,
        public readonly AlertSensitivityData $alert_sensitivity,
        public readonly AlertSensitivityData $team_alert_sensitivity,
        public readonly array $alert_sensitivity_presets,
        public readonly array $alert_sensitivity_bounds,
        public readonly InertiaLazy|int $result_count,
        public readonly array $run_all_checks = [],
        public readonly ?MaintenanceSiteHeaderData $maintenance = null,
        public readonly ?string $icon_fetched_at = null,
        public readonly ?IconRefreshResultData $icon_manual_result = null,
        /** Untrusted first redirect target reported by a crawl check; the crawler never requested it. */
        public readonly ?string $start_redirect_target_url = null,
        public readonly ?string $start_redirect_summary_key = null,
        public readonly array $clients = [],
        /** @var list<string> Server IANA list for the inline client-create sheet. */
        public readonly array $timezones = [],
        /** @var list<CheckSettingsData> Every check of the site, in the site's check order, for the Checks section. */
        public readonly array $check_settings = [],
    ) {}
}
