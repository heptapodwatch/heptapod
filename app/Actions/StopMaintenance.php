<?php

namespace Heptapod\Actions;

use Carbon\CarbonImmutable;
use Heptapod\Events\LiveSignal;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Maintenance\MaintenanceTargets;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\MaintenanceSiteExclusion;
use Heptapod\Models\Site;
use Illuminate\Support\Facades\Validator;

final readonly class StopMaintenance
{
    public function __construct(private EmitMaintenanceCatchUps $catchUps, private MaintenanceTargets $targets) {}

    public function __invoke(Site $site, int $delaySeconds = 0): int
    {
        Validator::make(['delay_seconds' => $delaySeconds], ['delay_seconds' => 'integer|min:0'])->validate();

        /** @var list<int> $windowIds */
        $windowIds = NotificationLocks::transaction(function () use ($site, $delaySeconds): array {
            $at = CarbonImmutable::now('UTC');
            $query = MaintenancePeriod::activeForSite($site->id, $at);
            NotificationLocks::forUpdate($query->getQuery()->orderBy('maintenance_periods.id'));
            $periods = $query->get();
            $affectedClientIds = [];
            foreach ($periods as $period) {
                $affectedClientIds = [...$affectedClientIds, ...$this->targets->clientIdsForWindow($period)];
                $end = min($period->ends_at, $at->addSeconds($delaySeconds));
                if ($period->target_type === 'site') {
                    $period->update(['ends_at' => $end]);

                    continue;
                }
                $exclusions = MaintenanceSiteExclusion::query();
                NotificationLocks::forUpdate($exclusions->getQuery()->where('maintenance_period_id', $period->id)->where('site_id', $site->id));
                $exclusion = $exclusions->first();
                if ($exclusion === null) {
                    MaintenanceSiteExclusion::query()->create(['maintenance_period_id' => $period->id,
                        'site_id' => $site->id, 'left_at' => $end]);
                } elseif ($end->lessThan($exclusion->left_at)) {
                    $exclusion->update(['left_at' => $end]);
                }
            }

            LiveSignal::forClients($affectedClientIds);

            return $periods->pluck('id')->all();
        });
        if ($delaySeconds === 0) {
            foreach ($windowIds as $windowId) {
                ($this->catchUps)($windowId);
            }
        }

        return count($windowIds);
    }
}
