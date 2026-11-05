<?php

declare(strict_types=1);

namespace Database\Factories;

use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MaintenancePeriod> */
class MaintenancePeriodFactory extends Factory
{
    #[\Override]
    protected $model = MaintenancePeriod::class;

    public function definition(): array
    {
        return ['site_id' => Site::factory(), 'name' => fake()->word(), 'starts_at' => now(), 'ends_at' => now()->addHour()];
    }
}
