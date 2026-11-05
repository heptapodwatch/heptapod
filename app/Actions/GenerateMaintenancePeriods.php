<?php

namespace Heptapod\Actions;

use Carbon\CarbonImmutable;
use Heptapod\Events\LiveSignal;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Maintenance\MaintenanceOccurrences;
use Heptapod\Maintenance\MaintenanceTargets;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\RecurringMaintenancePeriod;
use Heptapod\Models\Site;
use Illuminate\Support\Facades\DB;

final readonly class GenerateMaintenancePeriods
{
    public function __construct(private CreateMaintenancePeriod $create, private MaintenanceOccurrences $occurrences, private MaintenanceTargets $targets) {}

    public function __invoke(RecurringMaintenancePeriod $period, ?CarbonImmutable $until = null, bool $futureOnly = false): int
    {
        return DB::transaction(function () use ($period, $until, $futureOnly): int {
            // NotificationLocks: the recurrence (exclusive); the windows inserted below take their site's key.
            NotificationLocks::maintenanceRecurrences([$period->id], exclusive: true);
            $period = RecurringMaintenancePeriod::query()->findOrFail($period->id);
            $today = CarbonImmutable::now($period->timezone)->startOfDay()->format('Y-m-d');
            $from = $period->last_generated_until?->addDay()->format('Y-m-d') ?? $today;
            $from = max($from, $today);
            $through = ($until ?? CarbonImmutable::now($period->timezone)->addDays(config()->integer('heptapod.maintenance.horizon_days')))->setTimezone($period->timezone)->format('Y-m-d');
            if ($through < $from) {
                return 0;
            }
            if (CarbonImmutable::parse($from, $period->timezone)->diffInDays(CarbonImmutable::parse($through, $period->timezone)) > 400) {
                throw new \InvalidArgumentException('Maintenance generation must cover at most 400 days.');
            }
            $site = $period->site_id === null ? null : Site::query()->findOrFail($period->site_id);
            $count = 0;
            foreach (($this->occurrences)($period, $from, $through) as [$start, $end]) {
                if (($futureOnly && $start <= CarbonImmutable::now()) || MaintenancePeriod::query()->getQuery()->where('recurring_maintenance_period_id', $period->id)->where('starts_at', $start->format('Y-m-d H:i:s.uP'))->exists()) {
                    continue;
                }
                if ($site !== null) {
                    $window = ($this->create)($site, $period->name, $start, $end, $period->id);
                    $window->update(['reason' => $period->reason, 'mute_alerts' => $period->mute_alerts,
                        'catch_up_at_end' => $period->catch_up_at_end, 'timezone' => $period->timezone]);
                } else {
                    MaintenancePeriod::query()->create(['site_id' => null, 'target_type' => $period->target_type,
                        'target_id' => $period->target_id, 'recurring_maintenance_period_id' => $period->id,
                        'name' => $period->name, 'reason' => $period->reason, 'mute_alerts' => $period->mute_alerts,
                        'catch_up_at_end' => $period->catch_up_at_end, 'timezone' => $period->timezone,
                        'starts_at' => $start, 'ends_at' => $end]);
                }
                $count++;
            }
            $period->update(['last_generated_until' => $through]);
            if ($count > 0) {
                LiveSignal::forClients($this->targets->clientIdsForWindow($period));
            }

            return $count;
        });
    }
}
