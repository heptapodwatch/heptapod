<?php

declare(strict_types=1);

namespace Database\Factories;

use Heptapod\Contracts\CheckResult;
use Heptapod\Models\Check;
use Heptapod\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Event> */
class EventFactory extends Factory
{
    #[\Override]
    protected $model = Event::class;

    public function definition(): array
    {
        return ['check_id' => Check::factory(), 'site_id' => fn (array $attributes) => Check::query()->whereKey($attributes['check_id'])->firstOrFail()->site_id,
            'type' => 'check.result_changed', 'occurred_at' => now(), 'from_result' => CheckResult::Pending, 'to_result' => CheckResult::Succeeded,
            'payload_version' => 1, 'payload' => [], 'suppressed' => false, 'suppression_reason' => null, 'suppression_evaluated_at' => now()];
    }
}
