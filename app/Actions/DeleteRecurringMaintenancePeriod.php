<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Carbon\CarbonImmutable;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\RecurringMaintenancePeriod;
use Illuminate\Support\Facades\DB;

final readonly class DeleteRecurringMaintenancePeriod
{
    public function __construct(private DeleteMaintenancePeriod $delete) {}

    public function __invoke(RecurringMaintenancePeriod $period): void
    {
        NotificationLocks::transaction(function () use ($period): void {
            // NotificationLocks: the recurrence, then every window of it in ascending id (deleting the recurrence SETs NULL
            // on all of them), the future ones exclusive (deleted below). Nothing refers to a future window yet, so their
            // deletions take no check locks after these window locks.
            NotificationLocks::maintenanceRecurrences([$period->id], exclusive: true);
            $period = RecurringMaintenancePeriod::query()->findOrFail($period->id);
            $future = MaintenancePeriod::query();
            $future->getQuery()->where('recurring_maintenance_period_id', $period->id)->where('starts_at', '>', CarbonImmutable::now()->format('Y-m-d H:i:s.uP'))->orderBy('id');
            $futureIds = (clone $future)->getQuery()->pluck('id')->all();
            foreach (DB::table('maintenance_periods')->where('recurring_maintenance_period_id', $period->id)->orderBy('id')->pluck('id') as $windowId) {
                NotificationLocks::maintenanceWindows([$windowId], exclusive: in_array($windowId, $futureIds, true));
            }
            foreach ($future->get() as $window) {
                if ($window->starts_at > CarbonImmutable::now()) {
                    ($this->delete)($window);
                }
            }
            $period->delete();
        });
    }
}
