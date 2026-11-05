<?php

declare(strict_types=1);

namespace Database\Factories;

use Heptapod\Models\Check;
use Heptapod\Models\CheckSnooze;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CheckSnooze> */
class CheckSnoozeFactory extends Factory
{
    #[\Override]
    protected $model = CheckSnooze::class;

    public function definition(): array
    {
        return ['check_id' => Check::factory(), 'starts_at' => now(), 'ends_at' => now()->addHour(), 'snoozed_by' => fake()->name(), 'source' => 'api'];
    }
}
