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
use Heptapod\Contracts\ControlProbeResult;
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
use Heptapod\Jobs\RunCheck;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\DispatcherHeartbeat;
use Heptapod\Models\Incident;
use Heptapod\Models\Site;
use Heptapod\Models\SystemIssue;
use Heptapod\SystemHealth\EvaluateSystemIssues;
use Heptapod\SystemHealth\MonitorOutageDetector;
use Heptapod\SystemHealth\NativeControlProbe;
use Heptapod\SystemHealth\SystemIssueKind;
use Heptapod\Testing\FakeControlProbe;
use Heptapod\Testing\FakeOutboundHttp;
use Heptapod\Testing\FakeTargetResolver;
use Illuminate\Mail\Transport\ArrayTransport;
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
    foreach ([EvaluateSystemIssues::EvaluationKey, MonitorOutageDetector::Key, config('heptapod.health.scheduler_cache_key')] as $key) {
        Cache::store('redis')->forget($key);
    }
});

/**
 * @param  list<Check>  $checks
 */
function executeUptimeWave(array $checks, FetchResult $result, CarbonImmutable $at): void
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
    Bus::fake([RunCheck::class]);

    foreach ($checks as $check) {
        expect(app(ClaimService::class)->runNow($check->id, RunOrigin::System))->not->toBeNull();
        app(CheckExecutor::class)->execute($check->id, $check->refresh()->claim_token, 60);
    }
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

it('records a DNS burst as Check error without Down incidents and opens one system issue when DNS control probes fail', function (): void {
    Mail::mailer()->setSymfonyTransport($transport = new ArrayTransport);
    Http::fake(['https://receiver.test/alerts' => Http::response([], 200)]);
    $owner = systemIssueRecipient('Owner');
    $webhook = notificationRoute(notificationWebhook(), [NotificationCategory::Outage, NotificationCategory::CheckError, NotificationCategory::Recovery]);
    $probe = FakeControlProbe::dnsFailing();
    app()->instance(ControlProbe::class, $probe);

    $checks = uptimeBurstChecks(4);
    $dnsFailure = new FetchResult(null, '', [], failure: new FetchFailure(FetchFailureKind::Dns));

    // Wave 1 reaches the burst threshold on check 3; wave 2 confirms every check stays Check error with zero incidents.
    executeUptimeWave($checks, $dnsFailure, CarbonImmutable::parse('2026-11-20T23:21:00Z'));
    executeUptimeWave($checks, $dnsFailure, CarbonImmutable::parse('2026-11-20T23:22:00Z'));

    expect($probe->calls)->toBe(2);
    expect(Incident::query()->count())->toBe(0);
    foreach ($checks as $check) {
        $latest = CheckRun::query()->where('check_id', $check->id)->latest('id')->firstOrFail();
        expect($latest->result)->toBe(RunResult::Errored)
            ->and($latest->summary)->toBe('uptime.monitor_dns_outage')
            ->and(json_decode((string) $latest->error, true, flags: JSON_THROW_ON_ERROR)['class'])->toBe(ReclassifiedCheckError::class);
    }

    dispatchNotifications();
    Http::assertNothingSent();

    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
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

    // Control probes recover: the system issue resolves and mails recovery.
    $probe->result = FakeControlProbe::healthy()->result;
    $this->travelTo(CarbonImmutable::parse('2026-11-20T23:30:00Z'));
    Cache::store('redis')->forever(config('heptapod.health.scheduler_cache_key'), now()->toIso8601String());
    DispatcherHeartbeat::query()->update(['last_seen_at' => now()]);
    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();

    expect($issue->refresh()->resolved_at)->not->toBeNull();
    expect(collect($transport->messages())->map(fn ($sent): string => $sent->getOriginalMessage()->getSubject())->all())
        ->toBe([
            '[System] Heptapod cannot look up domain names',
            '[System recovered] Heptapod cannot look up domain names',
        ]);
});

it('keeps normal Down incidents during a burst when control probes succeed', function (): void {
    Mail::fake();
    $probe = FakeControlProbe::healthy();
    app()->instance(ControlProbe::class, $probe);

    $checks = uptimeBurstChecks(4);
    $dnsFailure = new FetchResult(null, '', [], failure: new FetchFailure(FetchFailureKind::Dns));

    executeUptimeWave($checks, $dnsFailure, CarbonImmutable::parse('2026-11-20T23:21:00Z'));
    executeUptimeWave($checks, $dnsFailure, CarbonImmutable::parse('2026-11-20T23:22:00Z'));

    expect($probe->calls)->toBeGreaterThanOrEqual(1);
    expect(Incident::query()->where('cause', IncidentCause::Failed->value)->count())->toBe(4);
    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
    expect(SystemIssue::open()->where('kind', SystemIssueKind::MonitorOutage->value)->exists())->toBeFalse();
    Mail::assertNotSent(TemplateMail::class);
});

it('never invokes control probes or reclassifies a single failing site', function (): void {
    $probe = FakeControlProbe::networkFailing();
    app()->instance(ControlProbe::class, $probe);

    $checks = uptimeBurstChecks(1);
    $dnsFailure = new FetchResult(null, '', [], failure: new FetchFailure(FetchFailureKind::Dns));

    executeUptimeWave($checks, $dnsFailure, CarbonImmutable::parse('2026-11-20T23:21:00Z'));
    executeUptimeWave($checks, $dnsFailure, CarbonImmutable::parse('2026-11-20T23:22:00Z'));

    expect($probe->calls)->toBe(0);
    expect(Incident::query()->where('cause', IncidentCause::Failed->value)->count())->toBe(1);
    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
    expect(SystemIssue::open()->where('kind', SystemIssueKind::MonitorOutage->value)->exists())->toBeFalse();
});

it('reclassifies connect and timeout bursts only when IP probes fail, and keeps HTTP 5xx bursts Down', function (): void {
    $checks = uptimeBurstChecks(3);

    // DNS-only probe failure does NOT cover Connect failures.
    $probe = FakeControlProbe::dnsFailing();
    app()->instance(ControlProbe::class, $probe);
    $connectFailure = new FetchResult(null, '', [], failure: new FetchFailure(FetchFailureKind::Connect));
    executeUptimeWave($checks, $connectFailure, CarbonImmutable::parse('2026-11-20T23:21:00Z'));
    executeUptimeWave($checks, $connectFailure, CarbonImmutable::parse('2026-11-20T23:22:00Z'));
    expect(Incident::query()->where('cause', IncidentCause::Failed->value)->count())->toBe(3);

    // Fresh checks with HTTP 503 burst stay Down even if network probes are failing.
    $httpChecks = uptimeBurstChecks(3, 'http-burst');
    $probe->result = FakeControlProbe::networkFailing()->result;
    $httpFailure = new FetchResult(503, 'Service Unavailable');
    executeUptimeWave($httpChecks, $httpFailure, CarbonImmutable::parse('2026-11-20T23:25:00Z'));
    executeUptimeWave($httpChecks, $httpFailure, CarbonImmutable::parse('2026-11-20T23:26:00Z'));
    expect(Incident::query()->whereIn('check_id', collect($httpChecks)->pluck('id'))->where('cause', IncidentCause::Failed->value)->count())->toBe(3);

    // IP probe failure covers Timeout bursts.
    $timeoutChecks = uptimeBurstChecks(3, 'timeout-burst');
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

    $checks = uptimeBurstChecks(3);
    $dnsFailure = new FetchResult(null, '', [], failure: new FetchFailure(FetchFailureKind::Dns));
    executeUptimeWave($checks, $dnsFailure, CarbonImmutable::parse('2026-11-20T23:21:00Z'));
    executeUptimeWave($checks, $dnsFailure, CarbonImmutable::parse('2026-11-20T23:22:00Z'));

    expect($probe->calls)->toBe(0);
    expect(Incident::query()->where('cause', IncidentCause::Failed->value)->count())->toBe(3);
});

it('treats an incomplete or budget-exhausted probe batch as non-failing without opening a monitor outage', function (): void {
    $resolver = new FakeTargetResolver;
    $native = new NativeControlProbe($resolver);
    // Remaining budget under the 2-second recording margin returns incomplete immediately without touching the network.
    $exhausted = $native->probe(CarbonImmutable::now('UTC')->addSecond());
    expect($exhausted->complete)->toBeFalse()
        ->and($exhausted->dnsFailed())->toBeFalse()
        ->and($exhausted->ipFailed())->toBeFalse()
        ->and($exhausted->mode())->toBeNull();

    $probe = FakeControlProbe::incomplete();
    app()->instance(ControlProbe::class, $probe);

    $checks = uptimeBurstChecks(3, 'budget');
    $dnsFailure = new FetchResult(null, '', [], failure: new FetchFailure(FetchFailureKind::Dns));
    executeUptimeWave($checks, $dnsFailure, CarbonImmutable::parse('2026-11-20T23:21:00Z'));
    executeUptimeWave($checks, $dnsFailure, CarbonImmutable::parse('2026-11-20T23:22:00Z'));

    expect($probe->calls)->toBeGreaterThanOrEqual(1)
        ->and($probe->lastDeadline)->toBeInstanceOf(CarbonImmutable::class)
        ->and(Incident::query()->where('cause', IncidentCause::Failed->value)->count())->toBe(3);
    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
    expect(SystemIssue::open()->where('kind', SystemIssueKind::MonitorOutage->value)->exists())->toBeFalse();
});

it('counts only matching failure kinds in affected_sites during a DNS-only monitor outage', function (): void {
    $probe = FakeControlProbe::dnsFailing();
    app()->instance(ControlProbe::class, $probe);

    $checks = uptimeBurstChecks(3, 'mixed');
    $at = CarbonImmutable::parse('2026-11-20T23:21:00Z');
    // First two checks fail with Connect (not covered by DNS-only probe failure); third fails with DNS.
    executeUptimeWave([$checks[0], $checks[1]], new FetchResult(null, '', [], failure: new FetchFailure(FetchFailureKind::Connect)), $at);
    executeUptimeWave([$checks[2]], new FetchResult(null, '', [], failure: new FetchFailure(FetchFailureKind::Dns)), $at->addSeconds(5));

    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
    $issue = SystemIssue::open()->where('kind', SystemIssueKind::MonitorOutage->value)->sole();
    expect($issue->details['affected_sites'])->toBe(1)
        ->and(CheckRun::query()->where('check_id', $checks[2]->id)->sole()->result)->toBe(RunResult::Errored)
        ->and(CheckRun::query()->where('check_id', $checks[0]->id)->sole()->result)->toBe(RunResult::Failed);
});

it('prevents an older failing probe from overwriting a newer healthy verdict', function (): void {
    $probe = FakeControlProbe::healthy();
    app()->instance(ControlProbe::class, $probe);
    $detector = app(MonitorOutageDetector::class);

    $t0 = CarbonImmutable::parse('2026-11-20T23:21:00Z');
    $checks = uptimeBurstChecks(3, 'stale');
    // Seed 2 failures in the window, then check 3 triggers a slow failing probe at T0 during which a newer healthy
    // verdict is published at T0 + 2s.
    $detector->evaluateFailure($checks[0]->id, FetchFailureKind::Dns, $t0);
    $detector->evaluateFailure($checks[1]->id, FetchFailureKind::Dns, $t0);

    $probe->handler = static function () use ($t0): ControlProbeResult {
        // Simulate a newer healthy system evaluation finishing at T0 + 2s before this slow failing probe completes.
        $healthy = FakeControlProbe::healthy();
        app()->instance(ControlProbe::class, $healthy);
        new MonitorOutageDetector($healthy)->evaluateSystemIssue($t0->addSeconds(2), true);

        return FakeControlProbe::dnsFailing()->result;
    };

    $verdict = $detector->evaluateFailure($checks[2]->id, FetchFailureKind::Dns, $t0, $t0->addSeconds(30));

    expect($verdict)->toBeNull();
    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
    expect(SystemIssue::open()->where('kind', SystemIssueKind::MonitorOutage->value)->exists())->toBeFalse();
});
