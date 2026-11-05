<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Actions\CreateClient;
use Heptapod\Actions\ImportOhDearAccount;
use Heptapod\Actions\RecordDispatcherHeartbeat;
use Heptapod\Checks\DatabaseClock;
use Heptapod\Checks\Execution\CheckExecutor;
use Heptapod\Checks\Execution\ClaimService;
use Heptapod\Checks\Execution\ExecutionBudget;
use Heptapod\Checks\Execution\ManualRunRefused;
use Heptapod\Checks\Execution\RunOrigin;
use Heptapod\Checks\NoResultFreshness;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\RunPhase;
use Heptapod\Enums\ImportRunStatus;
use Heptapod\Jobs\RunCheck;
use Heptapod\Jobs\RunOhDearImport;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\CheckRunAttempt;
use Heptapod\Models\Client;
use Heptapod\Models\DispatcherHeartbeat;
use Heptapod\Models\ImportRun;
use Heptapod\Models\Site;
use Heptapod\Models\SystemIssue;
use Heptapod\Models\User;
use Heptapod\Support\ImportTokenStore;
use Heptapod\SystemHealth\EvaluateSystemIssues;
use Heptapod\Testing\FakeOutboundHttp;
use Heptapod\Testing\OffsetDatabaseClock;
use Heptapod\Testing\OhDearAccountTransport;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Saloon\Http\Faking\MockClient;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-20T12:00:00Z'));
    $prefix = 'freshness-clock-test:'.Str::uuid().':';
    config(['database.redis.options.prefix' => $prefix, 'cache.prefix' => $prefix]);
    Redis::purge('default');
    Redis::purge('cache');
    Cache::purge('redis');
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    foreach ([EvaluateSystemIssues::EvaluationKey, 'heptapod:mail-transport:outcome', config('heptapod.health.scheduler_cache_key')] as $key) {
        Cache::store('redis')->forget($key);
    }
});

it('recognizes no-result work and result recovery when the database clock is ahead of the app', function (): void {
    Bus::fake([RunCheck::class]);
    Mail::fake();
    $uptime = Check::factory()->create(['next_run_at' => now()->addDay()]);
    $check = Check::factory()->for($uptime->site)->create(['type' => CheckKey::Performance, 'next_run_at' => now()->addDay()]);
    $attempt = app(ClaimService::class)->runNow($check->id, RunOrigin::System);
    expect($attempt)->toBeInstanceOf(CheckRunAttempt::class);

    new RunCheck($check->id, $attempt->claim_token, 60, 'checks-fast')->handle(app(CheckExecutor::class));

    expect($check->refresh()->awaiting_result_since?->toIso8601String())->toBe('2026-10-20T12:00:00+00:00');
    expect($attempt->refresh()->started_at?->toIso8601String())->toBe('2026-10-20T12:00:00+00:00');
    expect($attempt->finished_at?->toIso8601String())->toBe('2026-10-20T12:00:00+00:00');
    expect(app(NoResultFreshness::class)->forCheck($check, CarbonImmutable::now()))->toBe(['action' => 'counts', 'reason' => 'freshness.no_result.uptime_no_measurements']);
    $this->travelTo(CarbonImmutable::parse('2026-10-20T13:00:00Z'));
    Cache::store('redis')->put(config('heptapod.health.scheduler_cache_key'), now()->toIso8601String(), 60);
    $heartbeatId = '00000000-0000-0000-0000-000000000062';
    DispatcherHeartbeat::query()->create(['id' => $heartbeatId, 'last_seen_at' => now()]);
    CheckRun::factory()->for($uptime)->create(['started_at' => now()->subMinute(), 'ended_at' => now()->subSeconds(30), 'metrics' => ['total_time_in_seconds' => 0.2]]);
    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
    $issue = SystemIssue::open()->where('kind', 'check_not_fresh')->where('scope', (string) $check->id)->sole();
    $next = app(ClaimService::class)->runNow($check->id, RunOrigin::System);
    expect($next)->toBeInstanceOf(CheckRunAttempt::class);

    new RunCheck($check->id, $next->claim_token, 60, 'checks-fast')->handle(app(CheckExecutor::class));
    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();

    expect($check->refresh()->awaiting_result_since)->toBeNull();
    expect($check->latest_run_result)->toBe(CheckResult::Succeeded);
    expect($check->latest_run_ended_at?->toIso8601String())->toBe('2026-10-20T13:00:00+00:00');
    expect($next->refresh()->finished_at?->toIso8601String())->toBe('2026-10-20T13:00:00+00:00');
    expect($issue->refresh()->details['resolution'])->toBe('result_accepted');
    expect($issue->resolved_at)->not->toBeNull();
    DispatcherHeartbeat::query()->whereKey($heartbeatId)->delete();
    Cache::store('redis')->forget(config('heptapod.health.scheduler_cache_key'));
});

it('claims due checks but leaves the next database-clock second unclaimed', function (int $offset): void {
    Bus::fake([RunCheck::class]);
    $db = OffsetDatabaseClock::use($offset);
    $due = Check::factory()->create(['next_run_at' => $db->subSecond()]);
    $notDue = Check::factory()->create(['next_run_at' => $db->addSecond()]);
    app(RecordDispatcherHeartbeat::class)('00000000-0000-0000-0000-000000000071');

    app(ClaimService::class)->dispatchDue();

    expect($due->refresh()->claim_token)->not->toBeNull();
    expect($notDue->refresh()->claim_token)->toBeNull();
    Bus::assertDispatchedTimes(RunCheck::class, 1);
})->with([-700, 0, 700]);

it('writes dispatch leases and heartbeats at the database-clock instant', function (int $offset): void {
    Bus::fake([RunCheck::class]);
    $db = OffsetDatabaseClock::use($offset);
    $due = Check::factory()->create(['next_run_at' => $db->subSecond()]);
    $heartbeatId = '00000000-0000-0000-0000-000000000071';
    app(RecordDispatcherHeartbeat::class)($heartbeatId);

    app(ClaimService::class)->dispatchDue();

    expect($due->refresh()->dispatched_at?->equalTo($db))->toBeTrue();
    expect($due->lease_expires_at?->equalTo($db->addSeconds(ExecutionBudget::queuedLease(60, 'checks-fast'))))->toBeTrue();
    expect(DispatcherHeartbeat::query()->findOrFail($heartbeatId)->last_seen_at->equalTo($db))->toBeTrue();
    Bus::assertDispatchedTimes(RunCheck::class, 1);
})->with([-700, 0, 700]);

it('replaces a running claim whose database-clock lease expired', function (int $offset): void {
    Bus::fake([RunCheck::class]);
    $db = OffsetDatabaseClock::use($offset);
    $check = Check::factory()->create(['next_run_at' => $db->subSecond()]);
    app(RecordDispatcherHeartbeat::class)('00000000-0000-0000-0000-000000000071');
    app(ClaimService::class)->dispatchDue();
    $attempt = CheckRunAttempt::query()->where('check_id', $check->id)->sole();
    $started = CarbonImmutable::now('UTC')->subMinute();
    $attempt->update(['phase' => RunPhase::Running, 'started_at' => $started]);
    $check->update(['run_started_at' => $started, 'lease_expires_at' => $db->subSecond()]);

    app(ClaimService::class)->dispatchDue();

    expect($check->refresh()->lost_attempts)->toBe(1);
    expect($check->claim_token)->not->toBe($attempt->claim_token);
    expect($attempt->refresh()->phase)->toBe(RunPhase::Skipped);
    Bus::assertDispatchedTimes(RunCheck::class, 2);
})->with([-700, 0, 700]);

it('records the manual request at the database-clock instant', function (int $offset): void {
    Bus::fake([RunCheck::class]);
    $db = OffsetDatabaseClock::use($offset);
    $uptime = Check::factory()->create(['next_run_at' => $db->addDay()]);
    $check = Check::factory()->for($uptime->site)->create(['type' => CheckKey::Performance, 'next_run_at' => $db->addDay()]);

    app(ClaimService::class)->runNow($check->id, RunOrigin::Manual);

    $requestedAt = $check->refresh()->last_manual_requested_at;
    expect($requestedAt)->not->toBeNull();
    expect(CarbonImmutable::parse($requestedAt)->equalTo($db))->toBeTrue();
    Bus::assertDispatchedTimes(RunCheck::class, 1);
})->with([-700, 0, 700]);

it('queues a manual request with a database-clock lease', function (int $offset): void {
    Bus::fake([RunCheck::class]);
    $db = OffsetDatabaseClock::use($offset);
    $uptime = Check::factory()->create(['next_run_at' => $db->addDay()]);
    $check = Check::factory()->for($uptime->site)->create(['type' => CheckKey::Performance, 'next_run_at' => $db->addDay()]);

    app(ClaimService::class)->runNow($check->id, RunOrigin::Manual);

    expect($check->refresh()->lease_expires_at?->equalTo($db->addSeconds(ExecutionBudget::queuedLease(60, 'checks-fast'))))->toBeTrue();
    Bus::assertDispatchedTimes(RunCheck::class, 1);
})->with([-700, 0, 700]);

it('persists the running lease at the database-clock instant before recording the result', function (int $offset): void {
    Bus::fake([RunCheck::class]);
    $db = OffsetDatabaseClock::use($offset);
    $uptime = Check::factory()->create(['next_run_at' => $db->addDay()]);
    $check = Check::factory()->for($uptime->site)->create(['type' => CheckKey::Performance, 'next_run_at' => $db->addDay()]);
    $attempt = app(ClaimService::class)->runNow($check->id, RunOrigin::Manual);
    expect($attempt)->toBeInstanceOf(CheckRunAttempt::class);
    $leases = [];
    Check::updated(function (Check $updated) use ($check, &$leases): void {
        if ($updated->id === $check->id && $updated->wasChanged('run_started_at') && $updated->run_started_at !== null) {
            $leases[] = Check::query()->findOrFail($check->id)->lease_expires_at;
        }
    });

    new RunCheck($check->id, $attempt->claim_token, 60, 'checks-fast')->handle(app(CheckExecutor::class));

    expect($leases)->toHaveCount(1);
    expect($leases[0]?->equalTo($db->addSeconds(ExecutionBudget::runningLease(60))))->toBeTrue();
    Bus::assertDispatchedTimes(RunCheck::class, 1);
})->with([-700, 0, 700]);

it('refuses an immediate second manual run for exactly thirty seconds', function (int $offset): void {
    Bus::fake([RunCheck::class]);
    $db = OffsetDatabaseClock::use($offset);
    $uptime = Check::factory()->create(['next_run_at' => $db->addDay()]);
    $check = Check::factory()->for($uptime->site)->create(['type' => CheckKey::Performance, 'next_run_at' => $db->addDay()]);
    $attempt = app(ClaimService::class)->runNow($check->id, RunOrigin::Manual);
    expect($attempt)->toBeInstanceOf(CheckRunAttempt::class);
    new RunCheck($check->id, $attempt->claim_token, 60, 'checks-fast')->handle(app(CheckExecutor::class));

    expect(fn () => app(ClaimService::class)->runNow($check->id, RunOrigin::Manual))
        ->toThrow(function (ManualRunRefused $exception): void {
            expect($exception->reason)->toBe('cooldown');
            expect($exception->retryAfterSeconds)->toBe(30);
        });
    Bus::assertDispatchedTimes(RunCheck::class, 1);
})->with([-700, 0, 700]);

it('dispatches at the live database-clock instant after sixty-one seconds in the transaction', function (int $offset): void {
    Bus::fake([RunCheck::class]);
    $db0 = OffsetDatabaseClock::use($offset);
    $this->travelTo(now()->addSeconds(61));
    $check = Check::factory()->create(['next_run_at' => app(DatabaseClock::class)->now()->subSecond()]);
    app(RecordDispatcherHeartbeat::class)('00000000-0000-0000-0000-000000000071');

    app(ClaimService::class)->dispatchDue();

    expect($check->refresh()->claim_token)->not->toBeNull();
    expect($check->dispatched_at?->equalTo($db0->addSeconds(61)))->toBeTrue();
    Bus::assertDispatchedTimes(RunCheck::class, 1);
})->with([-700, 0, 700]);

it('sets the initial database-clock schedule through web sites.store', function (): void {
    $db = OffsetDatabaseClock::use(700);
    $owner = User::factory()->owner()->create();
    $client = Client::factory()->create();

    $this->actingAs($owner)->post(route('sites.store'), [
        'client_id' => $client->id, 'url' => 'https://clock-create.test', 'checks' => ['uptime'],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $check = Check::query()->sole();
    expect($check->next_run_at->format('Y-m-d H:i:s.uP'))->toBe($db->format('Y-m-d H:i:s.uP'));
});

it('sets the initial database-clock schedule through web sites.update adding a check type', function (): void {
    $db = OffsetDatabaseClock::use(700);
    $owner = User::factory()->owner()->create();
    $existing = Check::factory()->create(['next_run_at' => $db->addDay()]);

    $this->actingAs($owner)->patch(route('sites.update', $existing->site_id), ['performance_check_settings' => []])
        ->assertRedirect()->assertSessionHasNoErrors();

    $check = $existing->site->checks()->where('type', CheckKey::Performance)->sole();
    expect($check->next_run_at->format('Y-m-d H:i:s.uP'))->toBe($db->format('Y-m-d H:i:s.uP'));
    expect($existing->refresh()->next_run_at->equalTo($db->addDay()))->toBeTrue();
});

it('sets the initial database-clock schedule through API createMonitor', function (): void {
    $db = OffsetDatabaseClock::use(700);
    $owner = User::factory()->owner()->create();
    $this->withToken($owner->createToken('clock-create', ['*'])->plainTextToken);

    $this->postJson('/api/monitors', ['team_id' => 1, 'type' => 'http', 'url' => 'https://clock-api.test', 'checks' => ['uptime']])
        ->assertCreated();

    $check = Check::query()->sole();
    expect($check->next_run_at->format('Y-m-d H:i:s.uP'))->toBe($db->format('Y-m-d H:i:s.uP'));
});

it('sets the initial database-clock schedule through API updateMonitor adding a check type', function (): void {
    $db = OffsetDatabaseClock::use(700);
    $owner = User::factory()->owner()->create();
    $this->withToken($owner->createToken('clock-update', ['*'])->plainTextToken);
    $existing = Check::factory()->create(['next_run_at' => $db->addDay()]);

    $this->putJson('/api/monitors/'.$existing->site_id, ['performance_check_settings' => []])->assertOk();

    $check = $existing->site->checks()->where('type', CheckKey::Performance)->sole();
    expect($check->next_run_at->format('Y-m-d H:i:s.uP'))->toBe($db->format('Y-m-d H:i:s.uP'));
    expect($existing->refresh()->next_run_at->equalTo($db->addDay()))->toBeTrue();
});

it('sets the initial database-clock schedule through API createCronCheck', function (): void {
    $db = OffsetDatabaseClock::use(700);
    $owner = User::factory()->owner()->create();
    $this->withToken($owner->createToken('clock-cron', ['*'])->plainTextToken);
    $site = Site::factory()->create();

    $this->postJson('/api/monitors/'.$site->id.'/cron-checks', [
        'name' => 'clock-task', 'type' => 'simple', 'frequency_in_minutes' => 5, 'grace_time_in_minutes' => 5, 'description' => '',
    ])->assertCreated();

    $check = $site->checks()->sole();
    expect($check->type)->toBe(CheckKey::Cron);
    expect($check->next_run_at->format('Y-m-d H:i:s.uP'))->toBe($db->format('Y-m-d H:i:s.uP'));
});

it('sets the initial database-clock schedule through API syncCronChecks', function (): void {
    $db = OffsetDatabaseClock::use(700);
    $owner = User::factory()->owner()->create();
    $this->withToken($owner->createToken('clock-sync', ['*'])->plainTextToken);
    $site = Site::factory()->create();

    $this->postJson('/api/monitors/'.$site->id.'/cron-checks/sync', ['cron_checks' => [[
        'name' => 'clock-task', 'type' => 'simple', 'frequency_in_minutes' => 5, 'grace_time_in_minutes' => 5, 'description' => '',
    ]]])->assertOk();

    $check = $site->checks()->sole();
    expect($check->type)->toBe(CheckKey::Cron);
    expect($check->next_run_at->format('Y-m-d H:i:s.uP'))->toBe($db->format('Y-m-d H:i:s.uP'));
});

it('sets the initial database-clock schedule through the RunOhDearImport job', function (): void {
    $db = OffsetDatabaseClock::use(700);
    $transport = clockImportTransport();
    $client = Client::factory()->create();
    $run = ImportRun::query()->create([
        'source' => 'ohdear', 'status' => ImportRunStatus::Queued, 'expected' => 1, 'attempts' => 1, 'teams' => [],
        'plan' => [['source_id' => '101']], 'assignments' => ['101' => ['client_id' => $client->id, 'new_client' => null]],
        'results' => [], 'created_client_ids' => [],
    ]);
    ImportTokenStore::put($run->id, 'clock-fixture-token');

    new RunOhDearImport($run->id)->handle(app(ImportOhDearAccount::class), app(CreateClient::class));

    expect($run->refresh()->status)->toBe(ImportRunStatus::Completed);
    $checks = Site::query()->where('oh_dear_id', 101)->sole()->checks()->get();
    expect($checks)->not->toBeEmpty();
    foreach ($checks as $check) {
        expect($check->next_run_at->format('Y-m-d H:i:s.uP'))->toBe($db->format('Y-m-d H:i:s.uP'));
    }
    $transport->assertSent('https://ohdear.app/api/monitors*');
});

it('sets the initial database-clock schedule through the heptapod:import-ohdear command', function (): void {
    $db = OffsetDatabaseClock::use(700);
    $transport = clockImportTransport();

    $this->artisan('heptapod:import-ohdear', ['--token' => 'clock-fixture-token'])->assertSuccessful();

    $checks = Site::query()->where('oh_dear_id', 101)->sole()->checks()->get();
    expect($checks)->not->toBeEmpty();
    foreach ($checks as $check) {
        expect($check->next_run_at->format('Y-m-d H:i:s.uP'))->toBe($db->format('Y-m-d H:i:s.uP'));
    }
    $transport->assertSent('https://ohdear.app/api/monitors*');
});

function clockImportTransport(): MockClient
{
    /** @var array{me: array<string, mixed>, monitors: list<array<string, mixed>>, cron: list<array<string, mixed>>, maintenance: list<array<string, mixed>>, recurring: list<array<string, mixed>>} $account */
    $account = json_decode(file_get_contents(base_path('tests/Fixtures/OhDear/import-account.json')), true, flags: JSON_THROW_ON_ERROR);
    $account['monitors'] = [$account['monitors'][0]];

    return app(OhDearAccountTransport::class)->fake($account);
}

it('saves health check intervals through Configure without treating schedule fields as endpoint configuration', function (): void {
    Http::preventStrayRequests();
    Http::fake([]);
    $owner = User::factory()->owner()->create();
    $check = Check::factory()->create(['type' => CheckKey::ApplicationHealth, 'interval_seconds' => 60,
        'config' => app(CheckRegistry::class)->get(CheckKey::ApplicationHealth)->definition()->configSchema->validateConfig([
            'result_url' => 'https://health.example.test/status', 'secret' => 'health-secret',
        ])]);
    $http = new FakeOutboundHttp()->respondUsing(fn (): FetchResult => new FetchResult(
        status: 200, body: json_encode(['finishedAt' => CarbonImmutable::now()->getTimestamp(), 'checkResults' => []], JSON_THROW_ON_ERROR)));
    app()->instance(OutboundHttp::class, $http);

    $this->actingAs($owner)->patch(route('sites.checks.update', ['site' => $check->site_id, 'check' => $check->id]), ['frequency_value' => 2, 'frequency_unit' => 'hour'])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($check->refresh()->interval_seconds)->toBe(7200);
    expect($check->executionConfig()['result_url'])->toBe('https://health.example.test/status');
    expect($http->requests)->toHaveCount(1);
    $this->patch(route('sites.checks.update', ['site' => $check->site_id, 'check' => $check->id]), ['frequency_value' => 61, 'frequency_unit' => 'hour'])
        ->assertSessionHasErrors('frequency_value');
    expect($check->refresh()->interval_seconds)->toBe(7200);
});
