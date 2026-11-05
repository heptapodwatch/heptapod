<?php

namespace Heptapod\Actions;

use Carbon\CarbonImmutable;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\Site;
use Illuminate\Support\Facades\Validator;

final class CreateMaintenancePeriod
{
    public function __invoke(Site $site, ?string $name, CarbonImmutable $startsAt, CarbonImmutable $endsAt, ?int $recurringPeriodId = null): MaintenancePeriod
    {
        $this->validate($name, $startsAt, $endsAt);

        return MaintenancePeriod::query()->create(['site_id' => $site->id, 'target_type' => 'site', 'target_id' => $site->id,
            'name' => $name, 'starts_at' => $startsAt, 'ends_at' => $endsAt, 'recurring_maintenance_period_id' => $recurringPeriodId]);
    }

    public function validate(?string $name, CarbonImmutable $startsAt, CarbonImmutable $endsAt): void
    {
        Validator::make(['name' => $name, 'starts_at' => $startsAt, 'ends_at' => $endsAt], ['name' => 'nullable|string|max:255', 'starts_at' => 'required|date', 'ends_at' => 'required|date|after:starts_at'])->validate();
    }
}
