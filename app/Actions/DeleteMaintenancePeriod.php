<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Events\LiveSignal;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Maintenance\MaintenanceTargets;
use Heptapod\Models\MaintenancePeriod;

final readonly class DeleteMaintenancePeriod
{
    public function __construct(private MaintenanceTargets $targets, private HandleDeferredUptimeOpening $deferredOpenings) {}

    public function __invoke(MaintenancePeriod $period): void
    {
        NotificationLocks::transaction(function () use ($period): void {
            $clientIds = $this->targets->clientIdsForWindow($period);
            ($this->deferredOpenings)->deletingWindow($period);
            $period->delete();
            LiveSignal::forClients($clientIds);
        });
    }
}
