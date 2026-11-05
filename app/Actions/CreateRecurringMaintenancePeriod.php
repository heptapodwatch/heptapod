<?php

namespace Heptapod\Actions;

use Heptapod\Maintenance\RecurringMaintenanceAttributes;
use Heptapod\Models\RecurringMaintenancePeriod;
use Heptapod\Models\Site;
use Illuminate\Support\Facades\DB;

final readonly class CreateRecurringMaintenancePeriod
{
    public function __construct(private GenerateMaintenancePeriods $generate, private RecurringMaintenanceAttributes $attributes) {}

    /** @param array<string, mixed> $attributes */
    public function __invoke(Site $site, array $attributes, bool $generateNow = true): RecurringMaintenancePeriod
    {
        $attributes['timezone'] ??= $site->client()->getQuery()->firstOrFail()->timezone;
        $validated = $this->attributes->validate($attributes);

        return DB::transaction(function () use ($site, $validated, $generateNow): RecurringMaintenancePeriod {
            $period = RecurringMaintenancePeriod::query()->create(['site_id' => $site->id, 'target_type' => 'site', 'target_id' => $site->id, ...$validated]);
            if ($generateNow) {
                ($this->generate)($period);
            }

            return $period;
        });
    }
}
