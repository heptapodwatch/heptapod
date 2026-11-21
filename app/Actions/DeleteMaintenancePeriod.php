<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Carbon\CarbonImmutable;
use Heptapod\Events\LiveSignal;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Maintenance\MaintenanceTargets;
use Heptapod\Models\MaintenancePeriod;

final readonly class DeleteMaintenancePeriod
{
    public function __construct(private MaintenanceTargets $targets, private HandleDeferredUptimeOpening $deferredOpenings) {}

    /**
     * $authorize decides on the locked window, at one instant it is given, before anything is written and must refuse a
     * window that has started by then: it is locked here before the check keys deletingWindow takes, which only a window
     * nothing refers to yet allows (as in DeleteRecurringMaintenancePeriod).
     *
     * @param  (\Closure(MaintenancePeriod, CarbonImmutable): void)|null  $authorize
     */
    public function __invoke(MaintenancePeriod $period, ?\Closure $authorize = null): void
    {
        NotificationLocks::transaction(function () use ($period, $authorize): void {
            if ($authorize instanceof \Closure) {
                NotificationLocks::maintenanceWindows([$period->id], exclusive: true);
                $period = MaintenancePeriod::query()->findOrFail($period->id);
                $now = CarbonImmutable::now('UTC');
                $authorize($period, $now);
                if ($period->starts_at <= $now) {
                    throw new \LogicException('A window deleted under its own lock first must not have started.');
                }
            }
            $clientIds = $this->targets->clientIdsForWindow($period);
            ($this->deferredOpenings)->deletingWindow($period);
            $period->delete();
            LiveSignal::forClients($clientIds);
        });
    }
}
