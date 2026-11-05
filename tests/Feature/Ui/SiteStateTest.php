<?php

declare(strict_types=1);

use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckResult;
use Heptapod\Enums\IncidentCause;
use Heptapod\Enums\SiteStatus;
use Heptapod\Incidents\SiteState;
use Heptapod\Models\Check;
use Heptapod\Models\Incident;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\Site;

it('preserves the existing site state precedence', function (bool $paused, bool $maintenance, array $results, ?IncidentCause $cause, SiteStatus $expected, bool $uptimeEnabled = true, CheckKey $incidentKey = CheckKey::Uptime, ?string $maintenanceBoundary = null): void {
    $this->travelTo(now()->startOfMinute());
    $site = Site::factory()->create(['paused_at' => $paused ? now() : null]);
    if ($maintenance) {
        $bounds = match ($maintenanceBoundary) {
            'starts' => ['starts_at' => now(), 'ends_at' => now()->addMinute()],
            'ends' => ['starts_at' => now()->subMinute(), 'ends_at' => now()],
            default => [],
        };
        MaintenancePeriod::factory()->create(['site_id' => $site->id, ...$bounds]);
    }
    foreach ($results as $type => $result) {
        $check = Check::factory()->for($site)->create(['type' => $type, 'latest_run_result' => $result, 'enabled' => $type !== 'uptime' || $uptimeEnabled]);
        if ($type === $incidentKey->value && $cause instanceof IncidentCause) {
            Incident::factory()->for($check)->create(['cause' => $cause]);
        }
    }
    // A disabled failure must never outrank enabled checks.
    Check::factory()->for($site)->create(['type' => CheckKey::Dns, 'enabled' => false, 'latest_run_result' => CheckResult::Failed]);

    $reader = app(SiteState::class);
    expect($reader->derive($site))->toBe($expected)
        ->and($reader->annotate(Site::query())->whereKey($site->id)->firstOrFail()->getAttribute('state'))->toBe($expected->value);
})->with([
    'paused overrides maintenance and failure' => [true, true, ['uptime' => CheckResult::Failed], IncidentCause::Failed, SiteStatus::Paused],
    'maintenance overrides down' => [false, true, ['uptime' => CheckResult::Failed], IncidentCause::Failed, SiteStatus::Maintenance],
    'no enabled checks' => [false, false, [], null, SiteStatus::Disabled],
    'open uptime failure is down' => [false, false, ['uptime' => CheckResult::Failed], IncidentCause::Failed, SiteStatus::Down],
    'threshold failure before incident' => [false, false, ['uptime' => CheckResult::Failed], null, SiteStatus::Failed],
    'failure outranks check error' => [false, false, ['uptime' => CheckResult::Errored, 'performance' => CheckResult::Failed], IncidentCause::Errored, SiteStatus::Failed],
    'error outranks warning' => [false, false, ['uptime' => CheckResult::Errored, 'performance' => CheckResult::Warning], IncidentCause::Errored, SiteStatus::CheckError],
    'warning outranks pending' => [false, false, ['uptime' => CheckResult::Pending, 'performance' => CheckResult::Warning], null, SiteStatus::Warning],
    'pending outranks success' => [false, false, ['uptime' => CheckResult::Succeeded, 'performance' => CheckResult::Pending], null, SiteStatus::Pending],
    'all succeeded' => [false, false, ['uptime' => CheckResult::Succeeded], null, SiteStatus::Ok],
    'disabled uptime incident cannot make the site down' => [false, false, ['uptime' => CheckResult::Failed, 'performance' => CheckResult::Succeeded], IncidentCause::Failed, SiteStatus::Ok, false],
    'non-uptime failed incident is not down' => [false, false, ['uptime' => CheckResult::Succeeded, 'performance' => CheckResult::Failed], IncidentCause::Failed, SiteStatus::Failed, true, CheckKey::Performance],
    'maintenance includes its start' => [false, true, ['uptime' => CheckResult::Succeeded], null, SiteStatus::Maintenance, true, CheckKey::Uptime, 'starts'],
    'maintenance excludes its end' => [false, true, ['uptime' => CheckResult::Succeeded], null, SiteStatus::Ok, true, CheckKey::Uptime, 'ends'],
]);
