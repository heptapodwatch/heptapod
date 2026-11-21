<?php

declare(strict_types=1);

use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CronTaskStore;
use Heptapod\Models\Check;

function createCronTaskForPing(): string
{
    $check = Check::factory()->create(['type' => CheckKey::Cron]);

    return app(CronTaskStore::class)->create($check->id, [
        'name' => 'Nightly job',
        'type' => 'simple',
        'frequency_in_minutes' => 60,
        'grace_time_in_minutes' => 5,
    ])->uuid;
}

it('marks GET and POST cron ping responses as non-cacheable', function (string $method): void {
    $uuid = createCronTaskForPing();

    $response = $this->call($method, route('cron.ping', ['uuid' => $uuid]));

    $response->assertNoContent();
    expect($response->headers->get('Cache-Control'))
        ->toContain('no-store')
        ->toContain('private');
})->with(['GET', 'POST']);
