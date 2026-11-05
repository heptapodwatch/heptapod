<?php

declare(strict_types=1);

namespace Database\Factories;

use Heptapod\Models\DispatcherHeartbeat;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DispatcherHeartbeat> */
class DispatcherHeartbeatFactory extends Factory
{
    #[\Override]
    protected $model = DispatcherHeartbeat::class;

    public function definition(): array
    {
        return ['id' => fake()->unique()->uuid(), 'last_seen_at' => now()];
    }
}
