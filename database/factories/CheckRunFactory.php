<?php

declare(strict_types=1);

namespace Database\Factories;

use Heptapod\Contracts\RunResult;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CheckRun> */
class CheckRunFactory extends Factory
{
    #[\Override]
    protected $model = CheckRun::class;

    public function definition(): array
    {
        return ['check_id' => Check::factory(), 'check_type' => fn (array $attributes) => Check::query()->whereKey($attributes['check_id'])->firstOrFail()->type, 'result' => RunResult::Succeeded,
            'started_at' => now()->subSecond(), 'ended_at' => now(), 'summary' => 'checks.uptime.succeeded', 'summary_params' => [], 'metrics' => [], 'evidence' => []];
    }
}
