<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRunPresentationData;
use Heptapod\Enums\IncidentCause;
use Heptapod\Enums\SiteIconBacking;
use Heptapod\Incidents\UptimeDependencies;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class IncidentData extends Data
{
    /** Replaces the headline while the uptime outage explains this open incident (UptimeDependencies::headlineKey). */
    public readonly ?string $dependency_headline_key;

    public function __construct(
        public readonly int $id,
        public readonly int $site_id,
        public readonly string $site_name,
        public readonly int $client_id,
        public readonly CheckKey $check,
        public readonly IncidentCause $cause,
        public readonly int $duration_seconds,
        public readonly string $started_at,
        public readonly ?string $resolved_at,
        public readonly ?string $error,
        public readonly ?string $snoozed_until,
        public readonly ?string $summary = null,
        /** @var array<string, string|int|float|bool|null> */
        public readonly array $summary_params = [],
        public readonly ?string $icon_url = null,
        public readonly ?int $uptime_parent_incident_id = null,
        /** @var list<string> */
        public readonly array $affected_checks = [],
        public readonly ?string $uptime_recheck_after = null,
        public readonly bool $held_after_recovery = false,
        public readonly ?string $next_run_at = null,
        public readonly SiteIconBacking $icon_backing = SiteIconBacking::None,
        public readonly ?CheckRunPresentationData $presentation = null,
    ) {
        $this->dependency_headline_key = UptimeDependencies::headlineKey($resolved_at === null, $held_after_recovery, $uptime_parent_incident_id);
    }
}
