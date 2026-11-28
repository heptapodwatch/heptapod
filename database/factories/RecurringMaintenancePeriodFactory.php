<?php

declare(strict_types=1);

namespace Database\Factories;

use Heptapod\Models\RecurringMaintenancePeriod;
use Heptapod\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RecurringMaintenancePeriod> */
class RecurringMaintenancePeriodFactory extends Factory
{
    #[\Override]
    protected $model = RecurringMaintenancePeriod::class;

    public function definition(): array
    {
        return ['site_id' => Site::factory(), 'target_id' => static fn (array $attributes): mixed => $attributes['site_id'], 'name' => fake()->word(), 'recurrence_type' => 'daily', 'start_time' => '01:00', 'end_time' => '02:00', 'timezone' => 'Europe/Berlin'];
    }
}
