<?php

declare(strict_types=1);

namespace Database\Factories;

use Heptapod\Models\Check;
use Heptapod\Models\CheckRunRollupHourly;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CheckRunRollupHourly> */
class CheckRunRollupHourlyFactory extends Factory
{
    #[\Override]
    protected $model = CheckRunRollupHourly::class;

    public function definition(): array
    {
        return ['check_id' => Check::factory(), 'bucket_start' => now()->startOfHour(), 'computed_at' => now(),
            'succeeded_count' => 1, 'warning_count' => 0, 'failed_count' => 0, 'errored_count' => 0,
            'metric_sums' => (object) [], 'metric_counts' => (object) [], 'metric_p95' => (object) []];
    }
}
