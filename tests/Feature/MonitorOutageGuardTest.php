<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Addons\Channels\TemplateMail;
use Heptapod\Checks\Execution\CheckExecutor;
use Heptapod\Checks\Execution\ClaimService;
use Heptapod\Checks\Execution\RunOrigin;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\ControlProbe;
use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\Http\FetchRequest;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\Http\RedirectPolicy;
use Heptapod\Contracts\ReclassifiedCheckError;
use Heptapod\Contracts\RunResult;
use Heptapod\Enums\IncidentCause;
use Heptapod\Enums\NotificationCategory;
use Heptapod\Jobs\BroadcastLiveSignal;
use Heptapod\Jobs\DispatchNotifications;
use Heptapod\Jobs\RunCheck;
use Heptapod\Jobs\RunControlProbe;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\DispatcherHeartbeat;
use Heptapod\Models\Incident;
use Heptapod\Models\Site;
use Heptapod\Models\SystemIssue;
use Heptapod\SystemHealth\EvaluateSystemIssues;
use Heptapod\SystemHealth\MonitorOutageDetector;
use Heptapod\SystemHealth\SystemIssueKind;
use Heptapod\Testing\FakeControlProbe;
use Heptapod\Testing\FakeOutboundHttp;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support/NotificationChannels.php';
require_once __DIR__.'/../Support/SystemIssueRecipients.php';

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-20T23:20:00Z'));
    $prefix = 'monitor-outage-guard-test:'.Str::uuid().':';
    config([
        'mail.default' => 'smtp',
        'database.redis.options.prefix' => $prefix,
        'cache.prefix' => $prefix,
        'heptapod.control_probe.dns_hosts' => ['one.one.one.one', 'dns.google'],
        'heptapod.control_probe.ip_targets' => ['1.1.1.1:443', '8.8.8.8:443'],
    ]);
    Redis::purge('default');
    Redis::purge('cache');
    Cache::purge('redis');
    Http::preventStrayRequests();
    Cache::store('redis')->forever(config('heptapod.health.scheduler_cache_key'), now()->toIso8601String());
    DispatcherHeartbeat::query()->create(['id' => '00000000-0000-0000-0000-000000000097', 'last_seen_at' => now()]);
});

afterEach(function (): void {
    app(MonitorOutageDetector::class)->clear();
    foreach ([EvaluateSystemIssues::EvaluationKey, config('heptapod.health.scheduler_cache_key')] as $key) {
        Cache::store('redis')->forget($key);
    }
});

/**
 * @param  list<Check>  $checks
 * @param  bool  $fakeControlProbeJob  when true, RunControlProbe is intercepted by Bus::fake rather than run synchronously
 */
function executeUptimeWave(array $checks, FetchResult $result, CarbonImmutable $at, bool $fakeControlProbeJob = false): void
{
    test()->travelTo($at);
    $http = new FakeOutboundHttp;
    foreach ($checks as $check) {
        $http->respondWith(new FetchRequest(
            $check->site->url, 'GET', [], null,
            10485760, 10485760, RedirectPolicy::Operator, 5,
            timeoutSeconds: 30.0, acceptedStatuses: range(100, 599), strictRedirects: false,
        ), $result);
    }
    app()->instance(OutboundHttp::class, $http);
    Bus::fake($fakeControlProbeJob ? [RunCheck::class, RunControlProbe::class] : [RunCheck::class]);

    foreach ($checks as $check) {
        expect(app(ClaimService::class)->runNow($check->id, RunOrigin::System))->not->toBeNull();
        app(CheckExecutor::class)->execute($check->id, $check->refresh()->claim_token, 60);
    }
}

function runControlProbeJob(CarbonImmutable $at): void
{
    test()->travelTo($at);
    app()->call(new RunControlProbe()->handle(...));
}

/** @return list<Check> */
function uptimeBurstChecks(int $count, string $prefix = 'burst'): array
{
    $config = app(CheckRegistry::class)->get(CheckKey::Uptime)->definition()->configSchema->validateConfig([]);
    $checks = [];
    foreach (range(1, $count) as $index) {
        $site = Site::factory()->create([
            'friendly_name' => ucfirst($prefix)." Site {$index}",
            'url' => "https://{$prefix}-{$index}.example.test",
            'alert_after_minutes' => 2,
            'alert_recovery_checks' => 1,
            'alert_confirms_other_checks' => false,
            'alert_reminders' => 'standard',
        ]);
        $checks[] = Check::factory()->for($site)->create([
            'type' => CheckKey::Uptime,
            'failure_threshold' => 2,
            'config' => $config,
        ]);
    }

    return $checks;
}

it('dispatches RunControlProbe and records failures normally when a burst has no cached verdict yet', function (): void {
    $probe = FakeControlProbe::dnsFailing();
    app()->instance(ControlProbe::class, $probe);

    $checks = uptimeBurstChecks(4, 'cold');
    $dnsFailure = new FetchResult(null, '', [], failure: new FetchFailure(FetchFailureKind::Dns));

    executeUptimeWave($checks, $dnsFailure, CarbonImmutable::parse('2026-11-20T23:21:00Z'), fakeControlProbeJob: true);

    // Uptime check never ran the probe inline; it dispatched RunControlProbe and recorded the first wave normally.
    expect($probe->calls)->toBe(0);
    Bus::assertDispatched(RunControlProbe::class);
    foreach ($checks as $check) {
        expect(CheckRun::query()->where('check_id', $check->id)->sole()->result)->toBe(RunResult::Failed);
    }
    expect(Incident::query()->count())->toBe(0);
});

it('reclassifies a DNS burst using the cached failing verdict, opens one system issue and recovers when probes pass', function (): void {
    Mail::mailer()->setSymfonyTransport($transport = new ArrayTransport);
    Http::fake(['https://receiver.test/alerts' => Http::response([], 200)]);
    $owner = systemIssueRecipient('Owner');
    notificationRoute(notificationWebhook(), [NotificationCategory::Outage, NotificationCategory::CheckError, NotificationCategory::Recovery]);
    $probe = FakeControlProbe::dnsFailing();
    app()->instance(ControlProbe::class, $probe);

    $checks = uptimeBurstChecks(4);
    $dnsFailure = new FetchResult(null, '', [], failure: new FetchFailure(FetchFailureKind::Dns));

    // Wave 1 crosses the burst threshold on check 3 and dispatches RunControlProbe (which runs on sync queue and caches failing);
    // wave 2 (inside the 60 s soft age) sees the cached failing verdict across all 4 checks without re-probing.
    executeUptimeWave($checks, $dnsFailure, CarbonImmutable::parse('2026-11-20T23:21:00Z'));
    executeUptimeWave($checks, $dnsFailure, CarbonImmutable::parse('2026-11-20T23:21:45Z'));

    expect($probe->calls)->toBe(1);
    expect(Incident::query()->count())->toBe(0);
    foreach ($checks as $check) {
        $latest = CheckRun::query()->where('check_id', $check->id)->latest('id')->firstOrFail();
        expect($latest->result)->toBe(RunResult::Errored)
            ->and($latest->summary)->toBe('uptime.monitor_dns_outage')
            ->and(json_decode((string) $latest->error, true, flags: JSON_THROW_ON_ERROR)['class'])->toBe(ReclassifiedCheckError::class);
    }

    dispatchNotifications();
    Http::assertNothingSent();

    // Evaluator opens monitor_outage from the same cached verdict without running probes itself.
    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
    expect($probe->calls)->toBe(1);
    $issue = SystemIssue::open()->where('kind', SystemIssueKind::MonitorOutage->value)->sole();
    expect($issue->details)->toMatchArray([
        'mode' => 'dns',
        'affected_sites' => 4,
        'dns_probes' => '0 / 2',
        'ip_probes' => '2 / 2',
    ]);
    expect(collect($transport->messages())->map(fn ($sent): string => $sent->getOriginalMessage()->getSubject())->all())
        ->toBe(['[System] Heptapod cannot look up domain names']);
    expect($transport->messages()[0]->getEnvelope()->getRecipients()[0]->getAddress())->toBe($owner->email);

    // Control probes recover via RunControlProbe: the evaluator resolves monitor_outage from the cached healthy verdict.
    $probe->result = FakeControlProbe::healthy()->result;
    $recoveredAt = CarbonImmutable::parse('2026-11-20T23:25:00Z');
    runControlProbeJob($recoveredAt);
    Cache::store('redis')->forever(config('heptapod.health.scheduler_cache_key'), now()->toIso8601String());
    DispatcherHeartbeat::query()->update(['last_seen_at' => now()]);
    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();

    expect($probe->calls)->toBe(2)
        ->and($issue->refresh()->resolved_at)->not->toBeNull();
    expect(collect($transport->messages())->map(fn ($sent): string => $sent->getOriginalMessage()->getSubject())->all())
        ->toBe([
            '[System] Heptapod cannot look up domain names',
            '[System recovered] Heptapod cannot look up domain names',
        ]);
});

it('keeps normal Down incidents during a burst when control probes are healthy', function (): void {
    Mail::fake();
    $probe = FakeControlProbe::healthy();
    app()->instance(ControlProbe::class, $probe);

    $checks = uptimeBurstChecks(4, 'healthy');
    $dnsFailure = new FetchResult(null, '', [], failure: new FetchFailure(FetchFailureKind::Dns));

    executeUptimeWave($checks, $dnsFailure, CarbonImmutable::parse('2026-11-20T23:21:00Z'));
    executeUptimeWave($checks, $dnsFailure, CarbonImmutable::parse('2026-11-20T23:21:45Z'));

    expect($probe->calls)->toBe(1);
    expect(Incident::query()->where('cause', IncidentCause::Failed->value)->count())->toBe(4);
    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
    expect(SystemIssue::open()->where('kind', SystemIssueKind::MonitorOutage->value)->exists())->toBeFalse();
    Mail::assertNotSent(TemplateMail::class);
});

it('never dispatches control probes or reclassifies a single failing site', function (): void {
    $probe = FakeControlProbe::networkFailing();
    app()->instance(ControlProbe::class, $probe);

    $checks = uptimeBurstChecks(1, 'single');
    $dnsFailure = new FetchResult(null, '', [], failure: new FetchFailure(FetchFailureKind::Dns));

    executeUptimeWave($checks, $dnsFailure, CarbonImmutable::parse('2026-11-20T23:21:00Z'), fakeControlProbeJob: true);
    executeUptimeWave($checks, $dnsFailure, CarbonImmutable::parse('2026-11-20T23:22:00Z'), fakeControlProbeJob: true);

    expect($probe->calls)->toBe(0);
    Bus::assertNotDispatched(RunControlProbe::class);
    expect(Incident::query()->where('cause', IncidentCause::Failed->value)->count())->toBe(1);
    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
    expect(SystemIssue::open()->where('kind', SystemIssueKind::MonitorOutage->value)->exists())->toBeFalse();
});

it('reclassifies connect and timeout bursts only when IP probes fail, and keeps HTTP 5xx bursts Down', function (): void {
    $checks = uptimeBurstChecks(3, 'connect');

    // Cached DNS-only failing verdict does NOT cover Connect failures.
    $probe = FakeControlProbe::dnsFailing();
    app()->instance(ControlProbe::class, $probe);
    runControlProbeJob(CarbonImmutable::parse('2026-11-20T23:21:00Z'));
    $connectFailure = new FetchResult(null, '', [], failure: new FetchFailure(FetchFailureKind::Connect));
    executeUptimeWave($checks, $connectFailure, CarbonImmutable::parse('2026-11-20T23:21:00Z'));
    executeUptimeWave($checks, $connectFailure, CarbonImmutable::parse('2026-11-20T23:22:00Z'));
    expect(Incident::query()->where('cause', IncidentCause::Failed->value)->count())->toBe(3);

    // Fresh checks with HTTP 503 burst stay Down even with a cached network-failing verdict.
    $httpChecks = uptimeBurstChecks(3, 'http-burst');
    $probe->result = FakeControlProbe::networkFailing()->result;
    runControlProbeJob(CarbonImmutable::parse('2026-11-20T23:25:00Z'));
    $httpFailure = new FetchResult(503, 'Service Unavailable');
    executeUptimeWave($httpChecks, $httpFailure, CarbonImmutable::parse('2026-11-20T23:25:00Z'));
    executeUptimeWave($httpChecks, $httpFailure, CarbonImmutable::parse('2026-11-20T23:26:00Z'));
    expect(Incident::query()->whereIn('check_id', collect($httpChecks)->pluck('id'))->where('cause', IncidentCause::Failed->value)->count())->toBe(3);

    // Cached IP-failing verdict covers Timeout bursts.
    $timeoutChecks = uptimeBurstChecks(3, 'timeout-burst');
    runControlProbeJob(CarbonImmutable::parse('2026-11-20T23:30:00Z'));
    $timeoutFailure = new FetchResult(null, '', [], failure: new FetchFailure(FetchFailureKind::Timeout));
    executeUptimeWave($timeoutChecks, $timeoutFailure, CarbonImmutable::parse('2026-11-20T23:30:00Z'));
    executeUptimeWave($timeoutChecks, $timeoutFailure, CarbonImmutable::parse('2026-11-20T23:31:00Z'));
    expect(Incident::query()->whereIn('check_id', collect($timeoutChecks)->pluck('id'))->count())->toBe(0);
    foreach ($timeoutChecks as $check) {
        $latest = CheckRun::query()->where('check_id', $check->id)->latest('id')->firstOrFail();
        expect($latest->result)->toBe(RunResult::Errored)
            ->and($latest->summary)->toBe('uptime.monitor_network_outage');
    }
});

it('stays out when control probes are disabled by empty config', function (): void {
    config([
        'heptapod.control_probe.dns_hosts' => [],
        'heptapod.control_probe.ip_targets' => [],
    ]);
    $probe = FakeControlProbe::networkFailing();
    app()->instance(ControlProbe::class, $probe);

    $checks = uptimeBurstChecks(3, 'disabled');
    $dnsFailure = new FetchResult(null, '', [], failure: new FetchFailure(FetchFailureKind::Dns));
    executeUptimeWave($checks, $dnsFailure, CarbonImmutable::parse('2026-11-20T23:21:00Z'), fakeControlProbeJob: true);
    executeUptimeWave($checks, $dnsFailure, CarbonImmutable::parse('2026-11-20T23:22:00Z'), fakeControlProbeJob: true);

    expect($probe->calls)->toBe(0);
    Bus::assertNotDispatched(RunControlProbe::class);
    expect(Incident::query()->where('cause', IncidentCause::Failed->value)->count())->toBe(3);
});

it('does not publish an incomplete control probe batch or open monitor_outage', function (): void {
    $probe = FakeControlProbe::incomplete();
    app()->instance(ControlProbe::class, $probe);

    $checks = uptimeBurstChecks(3, 'incomplete');
    $dnsFailure = new FetchResult(null, '', [], failure: new FetchFailure(FetchFailureKind::Dns));
    executeUptimeWave($checks, $dnsFailure, CarbonImmutable::parse('2026-11-20T23:21:00Z'));
    executeUptimeWave($checks, $dnsFailure, CarbonImmutable::parse('2026-11-20T23:22:00Z'));

    expect($probe->calls)->toBeGreaterThanOrEqual(1)
        ->and(Cache::store('redis')->get(MonitorOutageDetector::VerdictKey))->toBeNull()
        ->and(Incident::query()->where('cause', IncidentCause::Failed->value)->count())->toBe(3);
    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
    expect(SystemIssue::open()->where('kind', SystemIssueKind::MonitorOutage->value)->exists())->toBeFalse();
});

it('counts only matching failure kinds in affected_sites during a DNS-only monitor outage', function (): void {
    $probe = FakeControlProbe::dnsFailing();
    app()->instance(ControlProbe::class, $probe);
    $at = CarbonImmutable::parse('2026-11-20T23:21:00Z');
    runControlProbeJob($at);

    $checks = uptimeBurstChecks(3, 'mixed');
    executeUptimeWave([$checks[0], $checks[1]], new FetchResult(null, '', [], failure: new FetchFailure(FetchFailureKind::Connect)), $at);
    executeUptimeWave([$checks[2]], new FetchResult(null, '', [], failure: new FetchFailure(FetchFailureKind::Dns)), $at->addSeconds(5));

    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
    $issue = SystemIssue::open()->where('kind', SystemIssueKind::MonitorOutage->value)->sole();
    expect($issue->details['affected_sites'])->toBe(1)
        ->and(CheckRun::query()->where('check_id', $checks[2]->id)->sole()->result)->toBe(RunResult::Errored)
        ->and(CheckRun::query()->where('check_id', $checks[0]->id)->sole()->result)->toBe(RunResult::Failed);
});

it('refreshes ahead past the soft age while keeping protection until hard TTL, then resolves on hard expiry', function (): void {
    $probe = FakeControlProbe::dnsFailing();
    app()->instance(ControlProbe::class, $probe);
    $t0 = CarbonImmutable::parse('2026-11-20T23:21:00Z');
    runControlProbeJob($t0);

    $checks = uptimeBurstChecks(3, 'refresh');
    $dnsFailure = new FetchResult(null, '', [], failure: new FetchFailure(FetchFailureKind::Dns));
    executeUptimeWave($checks, $dnsFailure, $t0->addSeconds(5));
    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
    $issue = SystemIssue::open()->where('kind', SystemIssueKind::MonitorOutage->value)->sole();

    // At soft age (65 s): dispatches refresh-ahead RunControlProbe AND still reclassifies using the cached verdict.
    executeUptimeWave($checks, $dnsFailure, $t0->addSeconds(65), fakeControlProbeJob: true);
    Bus::assertDispatched(RunControlProbe::class);
    expect(Incident::query()->count())->toBe(0);
    foreach ($checks as $check) {
        expect(CheckRun::query()->where('check_id', $check->id)->latest('id')->firstOrFail()->result)->toBe(RunResult::Errored);
    }

    // Past hard TTL (185 s) without a refreshed verdict: open issue resolves instead of staying pinned by connect failures.
    $tExpired = $t0->addSeconds(185);
    executeUptimeWave($checks, new FetchResult(null, '', [], failure: new FetchFailure(FetchFailureKind::Connect)), $tExpired, fakeControlProbeJob: true);
    Cache::store('redis')->forever(config('heptapod.health.scheduler_cache_key'), now()->toIso8601String());
    DispatcherHeartbeat::query()->update(['last_seen_at' => now()]);
    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();

    expect($issue->refresh()->resolved_at)->not->toBeNull();
});

it('reports guard enqueue errors without replacing the uptime result or aborting system evaluation', function (): void {
    $reported = [];
    app(ExceptionHandler::class)->reportable(static function (Throwable $exception) use (&$reported): false {
        $reported[] = $exception;

        return false;
    });

    $checks = uptimeBurstChecks(3, 'enqueue-fail');
    $dnsFailure = new FetchResult(null, '', [], failure: new FetchFailure(FetchFailureKind::Dns));
    $t0 = CarbonImmutable::parse('2026-11-20T23:21:00Z');

    // Make queue dispatch throw during burst evaluation: the uptime check still records its real Failed outcome.
    test()->travelTo($t0);
    $http = new FakeOutboundHttp;
    foreach ($checks as $check) {
        $http->respondWith(new FetchRequest(
            $check->site->url, 'GET', [], null,
            10485760, 10485760, RedirectPolicy::Operator, 5,
            timeoutSeconds: 30.0, acceptedStatuses: range(100, 599), strictRedirects: false,
        ), $dnsFailure);
    }
    app()->instance(OutboundHttp::class, $http);
    Bus::fake([RunCheck::class, DispatchNotifications::class, BroadcastLiveSignal::class]);
    foreach ($checks as $check) {
        $claim = app(ClaimService::class)->runNow($check->id, RunOrigin::System);
        expect($claim)->not->toBeNull();
        config(['queue.default' => 'broken-guard-queue']);
        app(CheckExecutor::class)->execute($check->id, $check->refresh()->claim_token, 60);
    }

    // Check 3 crossed the burst threshold, hit the broken queue on dispatch, reported it, and kept its real Failed result.
    expect($reported)->not->toBeEmpty();
    $thirdRun = CheckRun::query()->where('check_id', $checks[2]->id)->sole();
    expect($thirdRun->result)->toBe(RunResult::Failed)
        ->and($thirdRun->summary)->toBe('causes.connection');

    // System evaluation with an aging open monitor_outage also reports an enqueue error without aborting.
    $probe = FakeControlProbe::dnsFailing();
    app()->instance(ControlProbe::class, $probe);
    runControlProbeJob($t0);
    SystemIssue::query()->create([
        'kind' => SystemIssueKind::MonitorOutage,
        'scope' => '',
        'details' => ['mode' => 'dns', 'affected_sites' => 3, 'dns_probes' => '0 / 2', 'ip_probes' => '2 / 2'],
        'opened_at' => $t0,
    ]);
    $before = count($reported);
    $this->travelTo($t0->addSeconds(65));
    Cache::store('redis')->forever(config('heptapod.health.scheduler_cache_key'), now()->toIso8601String());
    DispatcherHeartbeat::query()->update(['last_seen_at' => now()]);
    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();

    expect(count($reported))->toBeGreaterThan($before);
});

it('guards RunControlProbe against overlapping execution with WithoutOverlapping middleware', function (): void {
    $middleware = (new RunControlProbe)->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class);
});
