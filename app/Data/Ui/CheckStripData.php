<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\CheckRunPresentationData;
use Heptapod\Enums\FreshnessSuppression;
use Heptapod\Enums\IncidentCause;
use Heptapod\Enums\SiteStatus;
use Heptapod\Incidents\UptimeDependencies;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class CheckStripData extends Data
{
    public readonly SiteStatus $state;

    public readonly ?string $freshness_suppression_label_key;

    /** Replaces the headline while the uptime outage explains this check's open incident (UptimeDependencies::headlineKey). */
    public readonly ?string $dependency_headline_key;

    /**
     * @param  array<string, string|int|float|bool|null>  $summary_params
     */
    public function __construct(
        public readonly int $id,
        public readonly CheckKey $key,
        public readonly string $label_key,
        public readonly string $icon_id,
        public readonly bool $enabled,
        public readonly CheckResult $result,
        public readonly ?int $open_incident_id,
        public readonly ?string $open_incident_started_at,
        public readonly ?string $summary,
        public readonly array $summary_params,
        public readonly ?string $checked_at,
        public readonly ?string $snoozed_until,
        public readonly ?string $meta_label = null,
        public readonly ?string $meta = null,
        public readonly ?string $unavailable_reason = null,
        public readonly ?CheckRunStateData $run = null,
        public readonly bool $open_incident_requires_complete_recovery = false,
        public readonly ?int $uptime_parent_incident_id = null,
        /** @var list<string> */
        public readonly array $affected_checks = [],
        public readonly ?string $uptime_recheck_after = null,
        public readonly bool $held_after_recovery = false,
        public readonly ?string $next_run_at = null,
        public readonly ?CheckRunPresentationData $presentation = null,
        public readonly bool $not_fresh = false,
        public readonly ?FreshnessSuppression $freshness_suppressed_by = null,
        public readonly bool $pending_recheck = false,
    ) {
        $this->freshness_suppression_label_key = $freshness_suppressed_by?->labelKey();
        $this->dependency_headline_key = UptimeDependencies::headlineKey($open_incident_id !== null, $held_after_recovery, $uptime_parent_incident_id);
        $this->state = match (true) {
            ! $enabled => SiteStatus::Paused,
            $unavailable_reason !== null => SiteStatus::Unavailable,
            $pending_recheck || $result === CheckResult::Pending => SiteStatus::Pending,
            $result === CheckResult::Succeeded => SiteStatus::Ok,
            $result === CheckResult::Warning => SiteStatus::Warning,
            default => SiteStatus::forIncident($key, $result === CheckResult::Errored ? IncidentCause::Errored : IncidentCause::Failed),
        };
    }

    public function withPresentation(?CheckRunPresentationData $presentation): self
    {
        return clone ($this, ['presentation' => $presentation]);
    }
}
