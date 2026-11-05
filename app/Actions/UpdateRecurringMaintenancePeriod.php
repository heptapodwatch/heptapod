<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Carbon\CarbonImmutable;
use Heptapod\Events\LiveSignal;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Maintenance\MaintenanceTargets;
use Heptapod\Maintenance\RecurringMaintenanceAttributes;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\RecurringMaintenancePeriod;

final readonly class UpdateRecurringMaintenancePeriod
{
    public function __construct(private RecurringMaintenanceAttributes $attributes, private GenerateMaintenancePeriods $generate, private DeleteMaintenancePeriod $delete, private MaintenanceTargets $targets) {}

    /** @param array<string, mixed> $changes */
    public function __invoke(RecurringMaintenancePeriod $period, array $changes, bool $regenerateFuturePeriods = true): RecurringMaintenancePeriod
    {
        return NotificationLocks::transaction(function () use ($period, $changes, $regenerateFuturePeriods): RecurringMaintenancePeriod {
            // NotificationLocks: the recurrence, then (when regenerating) its future windows in ascending id, which are deleted
            // below. Nothing refers to a future window yet, so their deletions take no check locks after these window locks.
            NotificationLocks::maintenanceRecurrences([$period->id], exclusive: true);
            $period = RecurringMaintenancePeriod::query()->findOrFail($period->id);
            $validated = $this->attributes->validate(array_replace($period->toArray(),
                ['anchor_date' => $period->anchor_date?->format('Y-m-d')], $changes));
            $targetType = $validated['target_type'] ?? $period->target_type;
            $targetId = $validated['target_id'] ?? $period->target_id ?? $period->site_id;
            $affectedClientIds = $this->targets->clientIdsForWindow($period);
            $period->update([...$validated, 'target_type' => $targetType, 'target_id' => $targetId,
                'site_id' => $targetType === 'site' ? $targetId : null]);
            LiveSignal::forClients([...$affectedClientIds, ...$this->targets->clientIdsForWindow($period)]);
            if ($regenerateFuturePeriods) {
                $future = MaintenancePeriod::query();
                $future->getQuery()->where('recurring_maintenance_period_id', $period->id)->where('starts_at', '>', CarbonImmutable::now()->format('Y-m-d H:i:s.uP'))->orderBy('id');
                NotificationLocks::maintenanceWindows((clone $future)->getQuery()->pluck('id'), exclusive: true);
                foreach ($future->get() as $window) {
                    if ($window->starts_at > CarbonImmutable::now()) {
                        ($this->delete)($window);
                    }
                }
                $period->update(['last_generated_until' => null]);
                ($this->generate)($period, futureOnly: true);
            }

            return $period->refresh();
        });
    }
}
