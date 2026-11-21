<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CronTaskStore;
use Heptapod\Models\Check;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

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

it('marks unknown, invalid, and throttled cron ping responses as non-cacheable', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-31T12:00:00Z'));
    $uuid = createCronTaskForPing();

    $unknown = $this->get(route('cron.ping', ['uuid' => (string) Str::uuid()]))->assertNotFound();
    expect($unknown->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');

    $invalid = $this->postJson(route('cron.ping', ['uuid' => $uuid]), ['runtime' => 'nope'])->assertUnprocessable();
    expect($invalid->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');

    RateLimiter::increment(md5('cron-pingsuuid:'.strtolower($uuid)), amount: 120);
    $throttled = $this->get(route('cron.ping', ['uuid' => $uuid]))->assertTooManyRequests();
    expect($throttled->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
});

it('rejects oversized, unbounded, or non-numeric cron ping metadata without storing it', function (array $payload, string $field): void {
    $uuid = createCronTaskForPing();

    $this->postJson(route('cron.ping', ['uuid' => $uuid]), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect(DB::table('cron_pings')->count())->toBe(0);
})->with([
    'padded runtime string' => [['runtime' => str_repeat('0', 200).'1.5'], 'runtime'],
    'non-numeric runtime' => [['runtime' => 'fast'], 'runtime'],
    'padded memory string' => [['memory' => str_repeat('0', 200).'512'], 'memory'],
    'oversized memory' => [['memory' => 1_099_511_627_777], 'memory'],
    'infinite memory' => [['memory' => '1e500'], 'memory'],
    'padded exit_code string' => [['exit_code' => str_repeat('0', 200).'1'], 'exit_code'],
    'out-of-range exit_code' => [['exit_code' => 256], 'exit_code'],
]);

it('normalizes valid numeric cron ping strings to numbers before storing', function (): void {
    $uuid = createCronTaskForPing();

    $this->get(route('cron.ping', ['uuid' => $uuid]).'?runtime=12.5&exit_code=0&memory=65536')
        ->assertNoContent();

    /** @var object{meta: string}|null $ping */
    $ping = DB::table('cron_pings')->first();
    expect($ping)->not->toBeNull();
    /** @var array<string, mixed> $meta */
    $meta = json_decode($ping->meta, true, flags: JSON_THROW_ON_ERROR);
    expect($meta)->toHaveCount(3)
        ->and($meta['runtime'])->toBe(12.5)
        ->and($meta['exit_code'])->toBe(0)
        ->and($meta['memory'])->toBe(65536);
});

it('allows large NAT cron bursts up to the per-IP ceiling and throttles unknown UUID sprays beyond it', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-31T12:00:00Z'));
    $ip = '198.51.100.10';

    // Prove 151 jobs reporting start + finish (302 pings) from one NAT IP stay well within the 1,200/min IP budget.
    $tasks = [];
    for ($i = 0; $i < 4; $i++) {
        $tasks[] = createCronTaskForPing();
    }
    for ($i = 0; $i < 302; $i++) {
        $uuid = $tasks[$i % 4];
        $phase = $i % 2 === 0 ? 'starting' : 'finished';
        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->get(route('cron.ping', ['uuid' => $uuid, 'phase' => $phase]))
            ->assertNoContent();
    }

    // Advance the IP counter to 1,199 and prove unknown UUIDs exhaust the remaining IP budget cleanly.
    RateLimiter::increment(md5('cron-pingsip:'.$ip), amount: 1199 - 302);

    $this->withServerVariables(['REMOTE_ADDR' => $ip])
        ->get(route('cron.ping', ['uuid' => (string) Str::uuid()]))
        ->assertNotFound();

    $this->withServerVariables(['REMOTE_ADDR' => $ip])
        ->get(route('cron.ping', ['uuid' => (string) Str::uuid()]))
        ->assertTooManyRequests();

    // Another IP is unaffected.
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.11'])
        ->get(route('cron.ping', ['uuid' => (string) Str::uuid()]))
        ->assertNotFound();
});

it('still enforces the per-UUID cron ping limit across different IPs', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-31T12:00:00Z'));
    $uuid = createCronTaskForPing();

    for ($attempt = 1; $attempt <= 120; $attempt++) {
        $ip = '198.51.100.'.(($attempt % 200) + 1);
        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->get(route('cron.ping', ['uuid' => $uuid]))
            ->assertNoContent();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.250'])
        ->get(route('cron.ping', ['uuid' => $uuid]))
        ->assertTooManyRequests();
});
