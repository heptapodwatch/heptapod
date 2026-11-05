<?php

declare(strict_types=1);

namespace Heptapod\Checks;

use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRunPresentationPartData;
use Heptapod\Enums\FreshnessSuppression;
use Heptapod\Enums\IncidentCause;
use Heptapod\Models\Check;
use Heptapod\Models\Incident;

final readonly class PerformanceAvailability
{
    public function __construct(private UptimeDependency $uptime) {}

    public function forCheck(Check $check, ?FreshnessSuppressionState $suppression = null, ?UptimeSnapshot $snapshot = null): ?CheckRunPresentationPartData
    {
        if ($check->type !== CheckKey::Performance || ! $check->enabled) {
            return null;
        }

        if ($suppression?->reason === FreshnessSuppression::Down) {
            return new CheckRunPresentationPartData('performance.no_data_while_down', ['date' => $suppression->at->toIso8601String()]);
        }
        $incident = $snapshot instanceof UptimeSnapshot ? $snapshot->openIncident($check->site_id, IncidentCause::Failed)
            : $this->uptime->openIncident($check->site_id, IncidentCause::Failed);

        return $incident instanceof Incident ? new CheckRunPresentationPartData(
            'performance.no_data_while_down', ['date' => $incident->first_failure_at->toIso8601String()],
        ) : null;
    }
}
