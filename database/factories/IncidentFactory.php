<?php

declare(strict_types=1);

namespace Database\Factories;

use Heptapod\Enums\IncidentCause;
use Heptapod\Models\Check;
use Heptapod\Models\Incident;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Incident> */
class IncidentFactory extends Factory
{
    #[\Override]
    protected $model = Incident::class;

    public function definition(): array
    {
        return ['check_id' => Check::factory(), 'site_id' => fn (array $attributes) => Check::query()->whereKey($attributes['check_id'])->firstOrFail()->site_id,
            'cause' => IncidentCause::Failed, 'opened_at' => now(), 'first_failure_at' => now()->subMinute(), 'evidence' => ['status_code' => 503]];
    }
}
