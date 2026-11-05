<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Addons\Channels\TemplateMail;
use Heptapod\Contracts\CheckKey;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\SystemIssue;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as LaravelEvent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/../Support/SystemIssueRecipients.php';

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-20T12:00:00Z'));
    Http::preventStrayRequests();
    config(['mail.default' => 'smtp', 'queue.default' => 'redis']);
});

it('opens once and sends synchronously to eligible recipients then mails recovery after a finished run', function (): void {
    Mail::fake();
    $owner = systemIssueRecipient('Owner');
    $admin = systemIssueRecipient('Admin', true, ['locale' => 'de']);
    $excluded = systemIssueRecipient('Admin');
    $check = Check::factory()->create(['next_run_at' => now()->addDay(), 'claim_token' => '00000000-0000-0000-0000-000000000003', 'dispatched_at' => now()->subMinutes(11), 'lease_expires_at' => now()->subMinute()]);
    CheckRun::factory()->for($check)->create(['ended_at' => now()->subMinutes(11), 'started_at' => now()->subMinutes(12)]);

    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();

    $issue = SystemIssue::query()->where('kind', 'checks_not_running')->sole();
    expect($issue->resolved_at)->toBeNull();
    expect(systemIssueDeliveries($issue->id, 'open')->where('deliveries.state', 'sent')->count())->toBe(2);
    expect(systemIssueDeliveries($issue->id, 'open')->count())->toBe(2);
    Mail::assertSent(TemplateMail::class, fn (TemplateMail $mail): bool => $mail->hasTo($owner->email) && str_contains($mail->model->subject, 'No check has finished'));
    Mail::assertSent(TemplateMail::class, fn (TemplateMail $mail): bool => $mail->hasTo($admin->email) && str_contains($mail->model->subject, 'Seit mehr als zehn Minuten wurde keine Prüfung'));
    Mail::assertNotSent(TemplateMail::class, fn (TemplateMail $mail): bool => $mail->hasTo($excluded->email));
    Mail::assertNothingQueued();

    CheckRun::factory()->for($check)->create();
    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
    expect($issue->refresh()->resolved_at)->not->toBeNull();
    expect(systemIssueDeliveries($issue->id, 'recovery')->where('deliveries.state', 'sent')->count())->toBe(2);
});

it('suppresses the issue when nothing should finish', function (string $scenario): void {
    Mail::fake();
    $check = Check::factory()->create(['next_run_at' => now()->subHour()]);
    CheckRun::factory()->for($check)->create(['ended_at' => now()->subHour(), 'started_at' => now()->subHours(2)]);
    match ($scenario) {
        'paused' => $check->site->update(['paused_at' => now()]),
        'disabled' => $check->update(['enabled' => false]),
        'deleting' => $check->site->update(['marked_for_deletion_at' => now()]),
        'daily between runs' => $check->update(['interval_seconds' => 86400, 'next_run_at' => now()->addHours(23)]),
        'maintenance' => $this->artisan('down')->assertSuccessful(),
    };
    try {
        $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
        $this->assertDatabaseMissing('system_issues', ['kind' => 'checks_not_running', 'resolved_at' => null]);
    } finally {
        if ($scenario === 'maintenance') {
            $this->artisan('up')->assertSuccessful();
        }
    }
})->with(['paused', 'disabled', 'deleting', 'daily between runs', 'maintenance']);

it('does not alert while a daily check is in flight after a finish 23 hours ago', function (string $terminalPhase, string $terminalNote): void {
    Mail::fake();
    $check = Check::factory()->create(['interval_seconds' => 86400, 'next_run_at' => now()->addDay(), 'claim_token' => '00000000-0000-0000-0000-000000000012', 'dispatched_at' => now()->subSeconds(10), 'lease_expires_at' => now()->addMinutes(10)]);
    $run = CheckRun::factory()->for($check)->create(['ended_at' => now()->subHours(23), 'started_at' => now()->subHours(23)->subMinute()]);
    $check->update(['latest_run_id' => $run->id, 'latest_run_ended_at' => $run->ended_at]);
    DB::table('check_run_attempts')->insert([
        ['check_id' => $check->id, 'claim_token' => '00000000-0000-0000-0000-000000000010', 'phase' => 'done', 'queue' => 'checks-fast', 'requested_at' => now()->subDay(), 'due_at' => now()->subDay(), 'finished_at' => $run->ended_at, 'check_run_id' => $run->id, 'note' => null],
        ['check_id' => $check->id, 'claim_token' => '00000000-0000-0000-0000-000000000013', 'phase' => $terminalPhase, 'queue' => 'checks-fast', 'requested_at' => now()->subHours(2), 'due_at' => now()->subHours(2), 'finished_at' => now()->subHour(), 'check_run_id' => null, 'note' => $terminalNote],
        ['check_id' => $check->id, 'claim_token' => '00000000-0000-0000-0000-000000000011', 'phase' => 'skipped', 'queue' => 'checks-fast', 'requested_at' => now()->subSeconds(30), 'due_at' => now()->subSeconds(30), 'finished_at' => now()->subSeconds(10), 'check_run_id' => null, 'note' => 'lease_replaced'],
        ['check_id' => $check->id, 'claim_token' => '00000000-0000-0000-0000-000000000012', 'phase' => 'queued', 'queue' => 'checks-fast', 'requested_at' => now()->subSeconds(10), 'due_at' => now()->subSeconds(10), 'finished_at' => null, 'check_run_id' => null, 'note' => null],
    ]);
    config(['instance.heartbeat_url' => 'https://sentinel.example.test/ping/daily', 'heptapod.health.external_ping_cache_key' => 'checks-not-running-test:daily']);
    Http::fake(['https://sentinel.example.test/ping/daily*' => Http::response('', 204)]);
    try {
        $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
        $this->assertDatabaseMissing('system_issues', ['kind' => 'checks_not_running', 'resolved_at' => null]);
        $this->artisan('instance:heartbeat')->assertSuccessful();
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://sentinel.example.test/ping/daily?check_age_seconds=30');
    } finally {
        Cache::store('redis')->forget('checks-not-running-test:daily');
    }
})->with(['cancelled request' => ['cancelled', 'cancelled'], 'revoked claim' => ['skipped', 'revoked'], 'changed definition' => ['skipped', 'definition_changed'], 'unavailable check' => ['skipped', 'check_unavailable'], 'no result' => ['skipped', 'no_result']]);

it('does not carry a revoked lease chain into fresh daily work', function (): void {
    Mail::fake();
    $check = Check::factory()->create(['interval_seconds' => 86400, 'next_run_at' => now()->addDay(), 'claim_token' => '00000000-0000-0000-0000-000000000023', 'dispatched_at' => now()->subSeconds(10), 'lease_expires_at' => now()->addMinutes(10)]);
    $run = CheckRun::factory()->for($check)->create(['ended_at' => now()->subHours(23), 'started_at' => now()->subHours(23)->subMinute()]);
    $check->update(['latest_run_id' => $run->id, 'latest_run_ended_at' => $run->ended_at]);
    DB::table('check_run_attempts')->insert([
        ['check_id' => $check->id, 'claim_token' => '00000000-0000-0000-0000-000000000020', 'phase' => 'done', 'queue' => 'checks-fast', 'requested_at' => now()->subDay(), 'due_at' => now()->subDay(), 'finished_at' => $run->ended_at, 'check_run_id' => $run->id, 'note' => null],
        ['check_id' => $check->id, 'claim_token' => '00000000-0000-0000-0000-000000000021', 'phase' => 'skipped', 'queue' => 'checks-fast', 'requested_at' => now()->subHours(2), 'due_at' => now()->subHours(2), 'finished_at' => now()->subMinutes(90), 'check_run_id' => null, 'note' => 'lease_replaced'],
        ['check_id' => $check->id, 'claim_token' => '00000000-0000-0000-0000-000000000022', 'phase' => 'skipped', 'queue' => 'checks-fast', 'requested_at' => now()->subMinutes(90), 'due_at' => now()->subHours(2), 'finished_at' => now()->subHour(), 'check_run_id' => null, 'note' => 'revoked'],
        ['check_id' => $check->id, 'claim_token' => '00000000-0000-0000-0000-000000000023', 'phase' => 'queued', 'queue' => 'checks-fast', 'requested_at' => now()->subSeconds(30), 'due_at' => now()->subSeconds(30), 'finished_at' => null, 'check_run_id' => null, 'note' => null],
    ]);
    config(['instance.heartbeat_url' => 'https://sentinel.example.test/ping/closed-chain', 'heptapod.health.external_ping_cache_key' => 'checks-not-running-test:closed-chain']);
    Http::fake(['https://sentinel.example.test/ping/closed-chain*' => Http::response('', 204)]);
    try {
        $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
        $this->assertDatabaseMissing('system_issues', ['kind' => 'checks_not_running', 'resolved_at' => null]);
        $this->artisan('instance:heartbeat')->assertSuccessful();
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://sentinel.example.test/ping/closed-chain?check_age_seconds=30');
    } finally {
        Cache::store('redis')->forget('checks-not-running-test:closed-chain');
    }
});

it('does not expect a completion from an overdue unavailable type', function (): void {
    Mail::fake();
    config(['heptapod.browser.enabled' => false, 'instance.heartbeat_url' => 'https://sentinel.example.test/ping/unavailable', 'heptapod.health.external_ping_cache_key' => 'checks-not-running-test:unavailable']);
    Check::factory()->create(['type' => CheckKey::Lighthouse, 'next_run_at' => now()->subHour()]);
    Http::fake(['https://sentinel.example.test/ping/unavailable*' => Http::response('', 204)]);
    try {
        $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
        $this->assertDatabaseMissing('system_issues', ['kind' => 'checks_not_running', 'resolved_at' => null]);
        $this->artisan('instance:heartbeat')->assertSuccessful();
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://sentinel.example.test/ping/unavailable?check_age_seconds=null');
    } finally {
        Cache::store('redis')->forget('checks-not-running-test:unavailable');
    }
});

it('reports the shared age on heartbeats and preserves existing query parameters', function (?int $age, ?int $expected): void {
    $check = Check::factory()->create(['next_run_at' => $age === null ? now()->addDay() : now()->subMinutes(20)]);
    if ($age !== null) {
        CheckRun::factory()->for($check)->create(['ended_at' => now()->subSeconds($age), 'started_at' => now()->subSeconds($age + 1)]);
    }
    config(['instance.heartbeat_url' => 'https://sentinel.example.test/ping/token?existing=value', 'heptapod.health.external_ping_cache_key' => 'checks-not-running-test:ping']);
    Http::fake(['https://sentinel.example.test/ping/token*' => Http::response('', 204)]);
    try {
        $this->artisan('instance:heartbeat')->assertSuccessful();
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://sentinel.example.test/ping/token?existing=value&check_age_seconds='.($expected ?? 'null'));
    } finally {
        Cache::store('redis')->forget('checks-not-running-test:ping');
    }
})->with([[660, 660], [0, 0], [null, null]]);

it('logs rejected heartbeat status without exposing the secret or recording a successful ping', function (): void {
    config(['instance.heartbeat_url' => 'https://sentinel.example.test/ping/private-token', 'heptapod.health.external_ping_cache_key' => 'checks-not-running-test:rejected']);
    Http::fake(['https://sentinel.example.test/ping/private-token*' => Http::response('', 422)]);
    $logs = [];
    LaravelEvent::listen(MessageLogged::class, function (MessageLogged $log) use (&$logs): void {
        $logs[] = [$log->level, $log->message, $log->context];
    });
    Cache::store('redis')->forget('checks-not-running-test:rejected');

    expect(fn () => $this->artisan('instance:heartbeat')->run())->toThrow(RequestException::class);

    expect(array_values(array_filter($logs, static fn (array $log): bool => $log[0] === 'error')))->toBe([['error', 'Instance heartbeat rejected.', ['status' => 422]]])
        ->and(json_encode($logs, JSON_THROW_ON_ERROR))->not->toContain('private-token');
    expect(Cache::store('redis')->get('checks-not-running-test:rejected'))->toBeNull();
});

it('keeps the completion deadline when an expired claim is replaced', function (?int $previousFinishMinutes): void {
    Mail::fake();
    $check = Check::factory()->create(['next_run_at' => now()->addDay(), 'claim_token' => '00000000-0000-0000-0000-000000000002', 'dispatched_at' => now()->subSeconds(10), 'lease_expires_at' => now()->addMinutes(10)]);
    if ($previousFinishMinutes !== null) {
        $run = CheckRun::factory()->for($check)->create(['ended_at' => now()->subMinutes($previousFinishMinutes), 'started_at' => now()->subMinutes($previousFinishMinutes + 1)]);
        $check->update(['latest_run_id' => $run->id, 'latest_run_ended_at' => $run->ended_at]);
    }
    DB::table('check_run_attempts')->insert([
        ['check_id' => $check->id, 'claim_token' => '00000000-0000-0000-0000-000000000001', 'phase' => 'skipped', 'queue' => 'checks-fast', 'requested_at' => now()->subMinutes(11), 'due_at' => now()->subMinutes(11), 'note' => 'lease_replaced'],
        ['check_id' => $check->id, 'claim_token' => '00000000-0000-0000-0000-000000000002', 'phase' => 'queued', 'queue' => 'checks-fast', 'requested_at' => now()->subSeconds(10), 'due_at' => now()->subSeconds(10), 'note' => null],
    ]);

    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();

    $this->assertDatabaseHas('system_issues', ['kind' => 'checks_not_running', 'resolved_at' => null]);
})->with(['never finished' => [null], 'finished one hour ago' => [60]]);

it('waits ten minutes from the first due time on an instance with no finished runs', function (): void {
    Mail::fake();
    Check::factory()->create(['next_run_at' => now()->subMinutes(10)]);

    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
    $this->assertDatabaseMissing('system_issues', ['kind' => 'checks_not_running']);
    $this->travel(1)->seconds();
    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
    $this->assertDatabaseHas('system_issues', ['kind' => 'checks_not_running', 'resolved_at' => null]);
});
