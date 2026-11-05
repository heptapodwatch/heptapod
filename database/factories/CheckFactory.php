<?php

declare(strict_types=1);

namespace Database\Factories;

use Heptapod\Checks\DatabaseClock;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\Schema\Schema;
use Heptapod\Models\Check;
use Heptapod\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Check> */
class CheckFactory extends Factory
{
    #[\Override]
    protected $model = Check::class;

    public function definition(): array
    {
        return ['site_id' => Site::factory(), 'type' => CheckKey::Uptime, 'config' => new Schema([])->validateConfig([]), 'enabled' => true, 'interval_seconds' => 60, 'next_run_at' => app(DatabaseClock::class)->now()];
    }
}
