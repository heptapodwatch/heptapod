<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\RunResult;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Site;
use Heptapod\Models\User;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-27T12:00:00Z'));
    $this->withoutVite();
});

it('names the reason of each uptime attempt as catalog keys, never as a check error for a site answer', function (): void {
    $user = User::factory()->owner()->create();
    $site = Site::factory()->create();
    $check = Check::factory()->for($site)->create(['type' => CheckKey::Uptime]);
    $attempt = static fn (int $minutes, RunResult $result, array $evidence): CheckRun => CheckRun::factory()->for($check)->create([
        'started_at' => now()->subMinutes($minutes)->subSecond(), 'ended_at' => now()->subMinutes($minutes),
        'result' => $result, 'evidence' => $evidence, 'metrics' => [],
    ]);
    $attempt(1, RunResult::Failed, ['status_code' => 503, 'issues' => ['status'], 'failure_kind' => 'http_status']);
    $attempt(2, RunResult::Failed, ['status_code' => null, 'issues' => ['connection'], 'failure_kind' => 'timeout']);
    $attempt(3, RunResult::Failed, ['status_code' => 200, 'issues' => ['required_string', 'header']]);
    $attempt(4, RunResult::Errored, ['status_code' => 403, 'issues' => ['bot_protection', 'legacy-free-text']]);

    $history = $this->actingAs($user)->get('/sites/'.$site->id.'/checks/'.$check->id)->assertOk()->inertiaProps()['history'];

    expect(array_map(static fn (array $run): array => [$run['result'], $run['reasons']], $history['recent_failures']))->toBe([
        ['failed', ['uptime.status']],
        ['failed', ['causes.outbound.timeout']],
        ['failed', ['uptime.reason.required_string', 'causes.header']],
        ['errored-or-timed-out', ['uptime.bot_protection']],
    ])->and($history['runs'][0])->not->toHaveKeys(['issues', 'failure_kind']);
    foreach (['en', 'de'] as $locale) {
        foreach (['uptime.status', 'causes.outbound.timeout', 'uptime.reason.required_string', 'causes.header', 'uptime.bot_protection'] as $key) {
            expect(__($key, locale: $locale))->not->toBe($key);
        }
    }
});
