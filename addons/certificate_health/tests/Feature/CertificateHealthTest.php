<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Addons\Channels\RenderedAlert;
use Heptapod\Checks\Recording\RecordableRun;
use Heptapod\Checks\Recording\ResultRecorder;
use Heptapod\Contracts\CertificateFetcher;
use Heptapod\Contracts\CertificateFetchResult;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\ExternalNetworkException;
use Heptapod\Contracts\ExternalTimeoutException;
use Heptapod\Contracts\PreviousResult;
use Heptapod\Contracts\RunReference;
use Heptapod\Contracts\RunResult;
use Heptapod\Events\Notifications\NotificationMessage;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Testing\ClaimedRun;
use Heptapod\Testing\FakeCertificateFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class);

/** @param array<string, mixed> $config */
function certificateContext(string $url = 'https://fixtures.test', array $config = [], ?CarbonImmutable $at = null): CheckContext
{
    $site = Site::factory()->create(['url' => $url]);
    $schema = app(CheckRegistry::class)->get(CheckKey::CertificateHealth)->definition()->configSchema;
    $check = Check::factory()->create(['site_id' => $site->id, 'type' => CheckKey::CertificateHealth, 'config' => $schema->validateConfig($config)]);
    $now = $at ?? CarbonImmutable::now();

    return new CheckContext($site->id, $check->id, (int) DB::scalar("SELECT nextval('check_runs_id_seq')"), (string) Str::uuid(), $url, $now, $now->addSeconds(30), $config, [], null);
}

function nextCertificateAttempt(CheckContext $context, ?PreviousResult $previous = null): CheckContext
{
    $now = CarbonImmutable::now();

    return new CheckContext($context->siteId, $context->checkId, (int) DB::scalar("SELECT nextval('check_runs_id_seq')"), (string) Str::uuid(), $context->url, $now, $now->addSeconds(30), $context->config, [], $previous, $context->history);
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05T12:00:00Z'));
    config(['certificate_health.ca_file' => base_path('docker/fixtures/tls/ca.crt')]);
});

it('maps supplied native verdicts and expiry thresholds into staged certificate display snapshots', function (string $fixture, string $host, RunResult $result, string $summary, ?string $at, array $config): void {
    $pem = $fixture === 'malformed' ? 'not a PEM certificate' : file_get_contents(base_path('docker/fixtures/tls/'.$fixture.'.crt'));
    expect($pem)->toBeString();
    app()->instance(CertificateFetcher::class, (new FakeCertificateFetcher)->respondWith($host, new CertificateFetchResult([$pem], $host, 8443, $result !== RunResult::Failed), 8443));
    $context = certificateContext('https://'.$host.':8443', $config, $at === null ? null : CarbonImmutable::parse($at));
    $type = app(CheckRegistry::class)->get(CheckKey::CertificateHealth);
    $outcome = $type->execute($context);
    expect($outcome->result)->toBe($result)->and($outcome->summary)->toBe($summary);
    $type->definition()->metricSchema->validateValues($outcome->metrics->toArray());
    $snapshot = json_decode(DB::table('certificate_health_snapshots')->where('run_id', $context->runId)->value('snapshot'), true, flags: JSON_THROW_ON_ERROR);
    expect($snapshot['certificate_checks'][0]['type'])->toBe('native_verification')->and(DB::table('detected_certificates')->count())->toBe(0);
    DB::transaction(fn () => $type->persistence()->finalize(new RunReference($context->runId, $context->startedAt), $context->claimToken));
    expect(DB::table('certificate_health_snapshots')->where('run_id', $context->runId)->value('run_ended_at'))->not->toBeNull();
    if ($fixture === 'malformed') {
        expect($snapshot['certificate_details'])->toBeNull()->and(DB::table('detected_certificates')->count())->toBe(0);
    } else {
        $certificate = DB::table('detected_certificates')->first();
        expect($certificate->issuer)->toBe('Heptapod offline fixture CA')
            ->and($certificate->fingerprint)->toHaveLength(64)
            ->and($certificate->domain)->toBe($host)
            ->and(json_decode($certificate->additional_domains, true))->toBe([$certificate->domain])
            ->and($certificate->signature_algorithm)->toBe('RSA-SHA256')
            ->and($certificate->is_valid)->toBe($result !== RunResult::Failed);
    }
})->with([
    'valid' => ['valid', 'fixtures.test', RunResult::Succeeded, 'certificate_health.valid', null, []],
    'default threshold inclusive' => ['valid', 'fixtures.test', RunResult::Warning, 'causes.certificate_expiry', '2045-12-18T00:00:00Z', []],
    'outside default threshold' => ['valid', 'fixtures.test', RunResult::Succeeded, 'certificate_health.valid', '2045-12-17T00:00:00Z', []],
    'custom threshold' => ['valid', 'fixtures.test', RunResult::Warning, 'causes.certificate_expiry', '2045-12-17T00:00:00Z', ['expires_soon_threshold_in_days' => 20]],
    'invalid PEM' => ['malformed', 'fixtures.test', RunResult::Failed, 'causes.tls.handshake', null, []],
]);

it('fails untrusted chains instead of treating parseable certificates as valid', function (): void {
    config(['certificate_health.ca_file' => null]);
    $outcome = app(CheckRegistry::class)->get(CheckKey::CertificateHealth)->execute(certificateContext());
    expect($outcome->result)->toBe(RunResult::Failed)->and($outcome->evidence->toArray()['certificate_checks'][0]['passed'])->toBeFalse();
});

it('propagates transport failures without staging observations', function (string $exception): void {
    app()->instance(CertificateFetcher::class, (new FakeCertificateFetcher)->respondWith('fixtures.test', new $exception('transport unavailable', previous: new RuntimeException('original cause'))));
    $context = certificateContext();
    expect(fn () => app(CheckRegistry::class)->get(CheckKey::CertificateHealth)->execute($context))->toThrow($exception, 'transport unavailable');
    $type = app(CheckRegistry::class)->get(CheckKey::CertificateHealth);
    $type->persistence()->finalize(new RunReference($context->runId, $context->startedAt), $context->claimToken);
    $type->persistence()->discard($context->runId, $context->claimToken);
    expect(DB::table('certificate_health_snapshots')->count())->toBe(0)->and(DB::table('detected_certificates')->count())->toBe(0);
})->with([ExternalNetworkException::class, ExternalTimeoutException::class]);

it('records a failed observation when a configured site no longer uses HTTPS', function (): void {
    app()->instance(CertificateFetcher::class, new FakeCertificateFetcher);
    $outcome = app(CheckRegistry::class)->get(CheckKey::CertificateHealth)->execute(certificateContext('http://fixtures.test'));
    expect($outcome->result)->toBe(RunResult::Failed)->and($outcome->summary)->toBe('certificate_health.not_https');
});

it('fetches and evaluates the real offline TLS variants through the registered check', function (string $host, RunResult $result, string $failedCheck): void {
    $type = app(CheckRegistry::class)->get(CheckKey::CertificateHealth);
    $context = certificateContext('https://'.$host);
    $outcome = $type->execute($context);
    expect($outcome->result)->toBe($result);
    $checks = array_column($outcome->evidence->toArray()['certificate_checks'], 'passed', 'type');
    expect($checks[$failedCheck])->toBe($result === RunResult::Succeeded);
    DB::transaction(fn () => $type->persistence()->finalize(new RunReference($context->runId, $context->startedAt), $context->claimToken));
    expect(DB::table('detected_certificates')->value('is_valid'))->toBe($result === RunResult::Succeeded);
})->with([
    ['fixtures.test', RunResult::Succeeded, 'native_verification'],
    ['expired.fixtures.test', RunResult::Failed, 'native_verification'],
    ['wrong-host.fixtures.test', RunResult::Failed, 'native_verification'],
]);

it('publishes only the specified attempt and rolls back all certificate writes with the recorder transaction', function (): void {
    $pem = file_get_contents(base_path('docker/fixtures/tls/valid.crt'));
    app()->instance(CertificateFetcher::class, (new FakeCertificateFetcher)->respondWith('fixtures.test', new CertificateFetchResult([$pem], 'fixtures.test', 443, true)));
    $context = certificateContext();
    $other = new CheckContext($context->siteId, $context->checkId, $context->runId, (string) Str::uuid(), $context->url, $context->startedAt, $context->deadline, [], [], null, $context->history);
    $type = app(CheckRegistry::class)->get(CheckKey::CertificateHealth);
    $type->execute($context);
    $type->execute($other);
    $run = new RunReference($context->runId, CarbonImmutable::parse('2026-10-01T12:00:00.123456Z'));
    try {
        DB::transaction(function () use ($type, $run, $context): void {
            $type->persistence()->finalize($run, $context->claimToken);
            throw new RuntimeException('recorder rollback');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('recorder rollback');
    }
    expect(DB::table('detected_certificates')->count())->toBe(0)->and(DB::table('certificate_health_snapshots')->whereNotNull('run_ended_at')->count())->toBe(0);
    $type->persistence()->finalize($run, $other->claimToken);
    $type->persistence()->finalize($run, $other->claimToken);
    $type->persistence()->discard($context->runId, $context->claimToken);
    $type->persistence()->discard($context->runId, $other->claimToken);
    expect(DB::table('certificate_health_snapshots')->count())->toBe(1)
        ->and(DB::table('certificate_health_snapshots')->value('claim_token'))->toBe($other->claimToken)
        ->and(DB::table('certificate_health_snapshots')->value('run_ended_at'))->toContain('.123456')
        ->and(DB::table('detected_certificates')->count())->toBe(1);
    expect(fn () => $type->execute($other))->toThrow(LogicException::class, 'Cannot stage an accepted');
    expect(fn () => $type->persistence()->stage($context, 'other', [[]]))->toThrow(InvalidArgumentException::class);
    expect(fn () => $type->persistence()->stage($context, 'snapshot', []))->toThrow(InvalidArgumentException::class);
});

it('prunes raw snapshots and dead attempts but keeps detected certificate history', function (): void {
    $pem = file_get_contents(base_path('docker/fixtures/tls/valid.crt'));
    app()->instance(CertificateFetcher::class, (new FakeCertificateFetcher)->respondWith('fixtures.test', new CertificateFetchResult([$pem], 'fixtures.test', 443, true)));
    $context = certificateContext();
    $type = app(CheckRegistry::class)->get(CheckKey::CertificateHealth);
    $type->execute($context);
    $type->persistence()->finalize(new RunReference($context->runId, $context->startedAt), $context->claimToken);
    $this->travel(1)->hours();
    $baseline = nextCertificateAttempt($context);
    $type->execute($baseline);
    $type->persistence()->finalize(new RunReference($baseline->runId, $baseline->startedAt), $baseline->claimToken);
    $this->travel(61)->days();
    $abandoned = certificateContext('https://fixtures.test/abandoned');
    $type->execute($abandoned);
    DB::table('certificate_health_snapshots')->where('run_id', $abandoned->runId)->update(['created_at' => now()->subHours(2)]);
    $fresh = certificateContext('https://fixtures.test/fresh');
    $type->execute($fresh);
    $type->persistence()->discard($abandoned->runId, $abandoned->claimToken);
    $type->persistence()->prune(CarbonImmutable::now()->subDays(31));
    expect(DB::table('certificate_health_snapshots')->orderBy('run_id')->pluck('run_id')->all())->toBe([$baseline->runId, $fresh->runId])->and(DB::table('detected_certificates')->count())->toBe(1);
    $resume = nextCertificateAttempt($context);
    expect($type->execute($resume)->result)->toBe(RunResult::Succeeded);
});

it('prefers SAN over CN and limits wildcards to one DNS label on native TLS', function (string $host, bool $matches): void {
    config(['certificate_health.ca_file' => base_path('docker/fixtures/tls/names.crt')]);
    $outcome = app(CheckRegistry::class)->get(CheckKey::CertificateHealth)->execute(certificateContext('https://'.$host));
    $checks = array_column($outcome->evidence->toArray()['certificate_checks'], 'passed', 'type');
    expect($checks['native_verification'])->toBe($matches)->and($outcome->result)->toBe($matches ? RunResult::Succeeded : RunResult::Failed);
})->with([
    'CN cannot override SAN' => ['names.fixtures.test', false],
    'wildcard one label' => ['www.wildcard.fixtures.test', true],
    'wildcard not base domain' => ['wildcard.fixtures.test', false],
    'wildcard not multiple labels' => ['nested.www.wildcard.fixtures.test', false],
    'exact SAN' => ['alt.fixtures.test', true],
    'self-signed' => ['self-signed.fixtures.test', false],
]);

it('keeps display issuer provenance for each captured PEM in the chain', function (): void {
    $chain = [file_get_contents(base_path('docker/fixtures/tls/valid.crt')), file_get_contents(base_path('docker/fixtures/tls/ca.crt'))];
    app()->instance(CertificateFetcher::class, (new FakeCertificateFetcher)->respondWith('fixtures.test', new CertificateFetchResult($chain, 'fixtures.test', 443, true)));
    $outcome = app(CheckRegistry::class)->get(CheckKey::CertificateHealth)->execute(certificateContext());
    expect($outcome->result)->toBe(RunResult::Succeeded)->and($outcome->evidence->toArray()['certificate_chain_issuers'])->toBe(['Heptapod offline fixture CA', 'Heptapod offline fixture CA']);
});

it('rejects invalid threshold settings at the config boundary', function (mixed $value): void {
    try {
        app(CheckRegistry::class)->get(CheckKey::CertificateHealth)->definition()->configSchema->validateConfig(['expires_soon_threshold_in_days' => $value]);
        $this->fail('Invalid threshold accepted.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('values.expires_soon_threshold_in_days');
    }
})->with([-1, '14', 1.5]);

it('preserves the cause and supports valid captures after a real TLS connection failure', function (): void {
    $context = certificateContext('https://fixtures.test:81');
    try {
        app(CheckRegistry::class)->get(CheckKey::CertificateHealth)->execute($context);
        $this->fail('Closed fixture port accepted.');
    } catch (ExternalNetworkException $exception) {
        expect($exception->getPrevious())->toBeInstanceOf(ErrorException::class)->and($exception->getMessage())->toBe('TLS connection failed.');
    }
    expect(DB::table('certificate_health_snapshots')->count())->toBe(0);
    // A subsequent valid capture proves adapter cleanup did not poison its next invocation.
    expect(app(CertificateFetcher::class)->fetch('fixtures.test', siteHost: 'fixtures.test')->pemChain)->not->toBeEmpty();
});

it('bounds display parsing without changing the native verification result', function (int $count, bool $verified): void {
    $pem = file_get_contents(base_path('docker/fixtures/tls/valid.crt'));
    app()->instance(CertificateFetcher::class, (new FakeCertificateFetcher)->respondWith('fixtures.test', new CertificateFetchResult(array_fill(0, $count, $pem), 'fixtures.test', 443, $verified)));
    $outcome = app(CheckRegistry::class)->get(CheckKey::CertificateHealth)->execute(certificateContext());
    expect($outcome->result)->toBe($verified ? RunResult::Succeeded : RunResult::Failed)
        ->and($outcome->metrics->toArray()['is_valid'])->toBe($verified)
        ->and($outcome->evidence->toArray()['certificate_chain_issuers'])->toHaveCount(min($count, 20));
})->with([[0, false], [21, false], [21, true]]);

it('detects an issuer change from accepted snapshots across a checker timeout', function (): void {
    $file = base_path('addons/certificate_health/tests/Fixtures/prior.crt');
    $fake = (new FakeCertificateFetcher)->respondWith('fixtures.test', new CertificateFetchResult([file_get_contents($file)], 'fixtures.test', 443, true));
    app()->instance(CertificateFetcher::class, $fake);
    config(['certificate_health.ca_file' => $file]);
    $context = certificateContext();
    $type = app(CheckRegistry::class)->get(CheckKey::CertificateHealth);
    $first = $type->execute($context);
    expect($first->result)->toBe(RunResult::Succeeded);
    $type->persistence()->finalize(new RunReference($context->runId, $context->startedAt), $context->claimToken);

    $fake->respondWith('fixtures.test', new ExternalTimeoutException('TLS timeout'));
    $timeout = nextCertificateAttempt($context);
    expect(fn () => $type->execute($timeout))->toThrow(ExternalTimeoutException::class);
    $type->persistence()->finalize(new RunReference($timeout->runId, $timeout->startedAt), $timeout->claimToken);
    $errored = new PreviousResult(new RunReference($timeout->runId, $timeout->startedAt), RunResult::Errored, $timeout->startedAt, 'engine.error', [], null);

    config(['certificate_health.ca_file' => base_path('docker/fixtures/tls/ca.crt')]);
    $fake->respondWith('fixtures.test', new CertificateFetchResult([file_get_contents(base_path('docker/fixtures/tls/valid.crt'))], 'fixtures.test', 443, true));
    $next = nextCertificateAttempt($context, $errored);
    $changed = $type->execute($next);
    expect($changed->result)->toBe(RunResult::Warning)->and($changed->summary)->toBe('certificate_health.issuer_changed');
    $type->persistence()->finalize(new RunReference($next->runId, $next->startedAt), $next->claimToken);
    $sameIssuer = $type->execute(nextCertificateAttempt($context));
    expect($sameIssuer->result)->toBe(RunResult::Succeeded);
});

it('bounds a real oversized chain for display while native verification remains authoritative', function (): void {
    $outcome = app(CheckRegistry::class)->get(CheckKey::CertificateHealth)->execute(certificateContext('https://fixtures.test:444'));
    expect($outcome->result)->toBe(RunResult::Succeeded)
        ->and($outcome->metrics->toArray()['is_valid'])->toBeTrue()
        ->and($outcome->evidence->toArray()['certificate_chain_issuers'])->toHaveCount(20);
});

it('verifies an IP SAN through the native TLS handshake', function (): void {
    config(['certificate_health.ca_file' => base_path('docker/fixtures/tls/names.crt')]);
    $server = new Process(['openssl', 's_server', '-accept', '127.0.0.1:9443',
        '-cert', base_path('docker/fixtures/tls/names.crt'), '-key', base_path('docker/fixtures/tls/names.key'), '-quiet']);
    $server->start();
    try {
        $ready = false;
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $socket = @stream_socket_client('tcp://127.0.0.1:9443', $code, $message, 0.1);
            if ($socket !== false) {
                fclose($socket);
                $ready = true;
                break;
            }
            usleep(10000);
        }
        expect($ready)->toBeTrue($server->getErrorOutput());
        $outcome = app(CheckRegistry::class)->get(CheckKey::CertificateHealth)->execute(certificateContext('https://127.0.0.1:9443'));
        expect($outcome->result)->toBe(RunResult::Succeeded)->and($outcome->metrics->toArray()['is_valid'])->toBeTrue();
    } finally {
        $server->stop();
    }
});

it('records certificate expiry facts before redacting query values from display evidence', function (): void {
    $at = CarbonImmutable::parse('2026-10-03T00:00:00Z');
    $this->travelTo($at);
    $pem = file_get_contents(base_path('docker/fixtures/tls/valid.crt'));
    app()->instance(CertificateFetcher::class, (new FakeCertificateFetcher)->respondWith('fixtures.test', new CertificateFetchResult([$pem], 'fixtures.test', 443, true)));
    $context = certificateContext('https://fixtures.test?year=2046', ['expires_soon_threshold_in_days' => 10000], $at->subSecond());
    $check = Check::query()->findOrFail($context->checkId);
    $context = ClaimedRun::start($check, $context->startedAt, $context->runId, $context->config);
    $check->update(['latest_run_result' => CheckResult::Failed]);
    $incident = Incident::factory()->for($check)->create(['first_failure_at' => $at->subMinute(), 'opened_at' => $at->subMinute()]);
    $type = app(CheckRegistry::class)->get(CheckKey::CertificateHealth);
    $outcome = $type->execute($context);
    expect($outcome->result)->toBe(RunResult::Warning);
    app(ResultRecorder::class)->record(new RecordableRun($check->id, $context->claimToken, $context->startedAt, $at, $outcome));

    $run = CheckRun::forRun(new RunReference($context->runId, $at))->firstOrFail();
    expect($run->summary)->toBe('causes.certificate_expiry')->and($run->summary_params)->toBe(['days' => 7030, 'presentation' => 'direct']);
    expect($run->evidence['certificate_details']['valid_until'])->toBe('[redacted]-01-01T00:00:00+00:00');
    expect($incident->refresh()->resolved_at->equalTo($at))->toBeTrue();
    $event = Event::query()->where('incident_id', $incident->id)->where('type', 'incident.resolved')->sole();
    $message = app(NotificationMessage::class)->build($event);
    expect(new RenderedAlert($message, 'en', 'UTC', 'Heptapod', 'https://heptapod.example')->details())
        ->toContain('Certificate expires in 7030 days')->not->toContain('2046');
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $props = $this->actingAs($owner)->get('/sites/'.$check->site_id.'/checks/'.$check->id)->assertOk()->inertiaProps();
    expect($props['check']['summary'])->toBe('causes.certificate_expiry')->and($props['check']['summary_params'])->toBe(['days' => 7030, 'presentation' => 'direct']);
    expect(json_encode($props['detail'], JSON_THROW_ON_ERROR))->not->toContain('2046');
});
