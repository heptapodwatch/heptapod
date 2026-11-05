<?php

namespace Heptapod\Actions;

use Carbon\CarbonImmutable;
use Heptapod\Events\LiveSignal;
use Heptapod\Maintenance\MaintenanceTargets;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\Site;
use Illuminate\Support\Facades\Validator;

final readonly class StartMaintenance
{
    public function __construct(private CreateMaintenancePeriod $create, private MaintenanceTargets $targets) {}

    public function __invoke(Site $site, ?string $name = null, int $stopMaintenanceAfterSeconds = 3600): MaintenancePeriod
    {
        Validator::make(['stop_maintenance_after_seconds' => $stopMaintenanceAfterSeconds], ['stop_maintenance_after_seconds' => 'integer|min:5'])->validate();
        $at = CarbonImmutable::now();

        $window = ($this->create)($site, $name, $at, $at->addSeconds($stopMaintenanceAfterSeconds));
        LiveSignal::forClients($this->targets->clientIds('site', $site->id));

        return $window;
    }
}
