<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Checks\Execution\ClaimService;
use Heptapod\Checks\Execution\ExecutionBudget;
use Heptapod\Checks\Execution\RunOrigin;
use Heptapod\Contracts\CertificateFetcher;
use Heptapod\Contracts\CertificateFetchResult;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\DomainRegistrationLookup;
use Heptapod\Contracts\ExternalNetworkException;
use Heptapod\Contracts\Http\FetchRequest;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\RunResult;
use Heptapod\Enums\SiteStatus;
use Heptapod\Incidents\SiteState;
use Heptapod\Jobs\RunCheck;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Event;
use Heptapod\Models\Site;
use Heptapod\Testing\FakeCertificateFetcher;
use Heptapod\Testing\FakeDomainRegistrationLookup;
use Heptapod\Testing\FakeOutboundHttp;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as LaravelEvent;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-28T12:00:00Z'));
    Queue::fake();
    app()->instance(OutboundHttp::class, new FakeOutboundHttp);
});

function claimedJob(Check $check): RunCheck
{
    expect(app(ClaimService::class)->runNow($check->id, RunOrigin::System))->not->toBeNull();
    $definition = app(CheckRegistry::class)->get($check->type)->definition();

    return new RunCheck($check->id, $check->refresh()->claim_token, $definition->timeout, $definition->queue);
}

it('executes decrypted config with a reserved run identity and records exactly once across ack loss', function (): void {
    $type = app(CheckRegistry::class)->get(CheckKey::Uptime);
    expect($type->persistence())->toBeNull();
    $check = Check::factory()->create(['config' => $type->definition()->configSchema->validateConfig([
        'http_client_headers' => [['name' => 'Authorization', 'value' => 'private-execution-token']],
        'timeout' => 300,
    ])]);
    $job = claimedJob($check);
    $reserved = null;
    app(OutboundHttp::class)->respondUsing(function (FetchRequest $request, CheckContext $context) use ($check, $type, &$reserved): FetchResult {
        $started = $check->fresh();
        expect($request->headers['authorization'])->toBe(['private-execution-token'])
            ->and($started->run_started_at)->not->toBeNull()
            ->and($started->active_run_id)->not->toBeNull()
            ->and($context->deadline->getTimestampMs() - $context->startedAt->getTimestampMs())->toBe($type->definition()->timeout * 1000);
        $reserved = $started->active_run_id;

        return new FetchResult(200, 'ok');
    });
    app()->call($job->handle(...));
    $events = Event::query()->count();
    app()->call($job->handle(...));
    $job->failed(new RuntimeException('ack lost'));

    expect(CheckRun::query()->count())->toBe(1)
        ->and(CheckRun::query()->first()->id)->toBe($reserved)
        ->and(Event::query()->count())->toBe($events)
        ->and($events)->toBe(1)
        ->and($check->refresh()->latest_run_result)->toBe(CheckResult::Succeeded)
        ->and($check->claim_token)->toBeNull()
        ->and($check->lease_expires_at)->toBeNull();
    expect(app(OutboundHttp::class)->requests)->toHaveCount(1);
});

it('records redacted addon diagnostics without exposing config or crawler secrets in logs or traces', function (CheckKey $key): void {
    $schema = app(CheckRegistry::class)->get($key)->definition()->configSchema;
    $config = $key === CheckKey::Uptime ? ['http_client_headers' => [['name' => 'Authorization', 'value' => 'private-execution-token']]] : [];
    $check = Check::factory()->create(['type' => $key, 'config' => $schema->validateConfig($config)]);
    $headers = [['name' => 'X-Crawler-Key', 'value' => 'private-crawler-token']];
    if ($key === CheckKey::Domain) {
        $headers[] = ['name' => 'X-Execution-Key', 'value' => 'private-execution-token'];
    }
    $check->site->update(['url' => 'https://redaction.example.com', 'crawler_headers' => $headers]);
    $logs = [];
    LaravelEvent::listen(MessageLogged::class, function (MessageLogged $event) use (&$logs): void {
        $logs[] = $event;
    });
    $job = claimedJob($check);
    $message = 'Probe exploded: PRIVATE-EXECUTION-TOKEN / PRIVATE-CRAWLER-TOKEN';
    if ($key === CheckKey::Domain) {
        $exception = new ExternalNetworkException($message);
        app()->instance(DomainRegistrationLookup::class, (new FakeDomainRegistrationLookup)->respondWith('example.com', $exception));
    } else {
        $exception = new RuntimeException($message);
        app(OutboundHttp::class)->respondUsing(fn () => throw $exception);
    }
    app()->call($job->handle(...));

    $run = CheckRun::query()->first();
    $logged = json_encode(array_map(fn (MessageLogged $entry): array => [$entry->message, $entry->context], $logs), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $diagnostic = json_decode($run->error, true, flags: JSON_THROW_ON_ERROR);
    expect($diagnostic)->toBe(['class' => $exception::class, 'message' => 'Probe exploded: [redacted] / [redacted]']);
    expect($run->result)->toBe(RunResult::Errored)
        ->and($run->metrics)->toBeNull()
        ->and($run->error)->not->toContain('private-execution-token', 'private-crawler-token')
        ->and($logged)->toContain('Probe exploded: [redacted] / [redacted]', 'trace', 'file', 'line')
        ->not->toContain('private-execution-token', 'private-crawler-token', '"args"')
        ->and($check->refresh()->consecutive_failures)->toBe(1)
        ->and($check->claim_token)->toBeNull();
})->with([CheckKey::Uptime, CheckKey::Domain]);

it('records a queue timeout once and rejects the original late completion', function (): void {
    $check = Check::factory()->create();
    $job = claimedJob($check);
    app(OutboundHttp::class)->respondUsing(function () use ($job): FetchResult {
        $job->failed(new TimeoutExceededException('worker timeout'));

        return new FetchResult(200, 'ok');
    });
    app()->call($job->handle(...));

    expect(CheckRun::query()->count())->toBe(1)
        ->and(CheckRun::query()->first()->result)->toBe(RunResult::Errored)
        ->and(Event::query()->count())->toBe(1);
});

it('fences an executing worker when its expired lease is replaced', function (): void {
    $check = Check::factory()->create();
    $oldJob = claimedJob($check);
    $requests = 0;
    app(OutboundHttp::class)->respondUsing(function () use ($check, $oldJob, &$requests): FetchResult {
        if (++$requests > 1) {
            return new FetchResult(200, 'replacement');
        }
        $check->update(['lease_expires_at' => now()->subSecond()]);
        $newJob = claimedJob($check);
        expect($newJob->claimToken)->not->toBe($oldJob->claimToken);
        app()->call($newJob->handle(...));

        return new FetchResult(500, 'late original');
    });
    app()->call($oldJob->handle(...));
    $oldJob->failed(new RuntimeException('late failure'));

    expect(CheckRun::query()->count())->toBe(1)
        ->and(Event::query()->count())->toBe(1)
        ->and($check->refresh()->latest_run_result)->toBe(CheckResult::Succeeded)
        ->and($check->claim_token)->toBeNull();
});

it('does not probe inactive or stale queued work', function (string $state): void {
    $check = Check::factory()->create();
    $job = claimedJob($check);
    match ($state) {
        'disabled' => $check->update(['enabled' => false]),
        'paused' => $check->site->update(['paused_at' => now()]),
        'deleted' => $check->site->delete(),
        'stale' => $check->update(['claim_token' => (string) Str::uuid()]),
    };
    app()->call($job->handle(...));

    expect(app(OutboundHttp::class)->requests)->toBe([]);
    expect(CheckRun::query()->count())->toBe(0)
        ->and(Event::query()->count())->toBe(0);
    if (in_array($state, ['disabled', 'paused'], true)) {
        expect($check->refresh()->claim_token)->toBeNull()
            ->and($check->manual_run)->toBeFalse();
    }
})->with(['disabled', 'paused', 'deleted', 'stale']);

it('starts a queued job with little queue lease remaining and gives execution its own full lease', function (): void {
    $check = Check::factory()->create();
    $job = claimedJob($check);
    $check->update(['lease_expires_at' => now()->addSecond()]);
    app(OutboundHttp::class)->respondUsing(function () use ($check): FetchResult {
        expect($check->refresh()->lease_expires_at->diffInSeconds($check->run_started_at, true))->toBe((float) (60 + ExecutionBudget::Grace));

        return new FetchResult(200, 'ok');
    });
    app()->call($job->handle(...));
    expect(CheckRun::query()->sole()->result)->toBe(RunResult::Succeeded);
});

it('accepts absent staging for no observation and idempotently discards replayed jobs', function (): void {
    $check = Check::factory()->create(['type' => CheckKey::Performance]);
    expect(app(CheckRegistry::class)->get(CheckKey::Performance)->persistence())->toBeNull();
    $job = claimedJob($check);
    app()->call($job->handle(...));
    app()->call($job->handle(...));
    $job->failed(new RuntimeException('No-observation acknowledgement lost'));

    expect(app(OutboundHttp::class)->requests)->toBe([]);
    expect(CheckRun::query()->count())->toBe(0)
        ->and(Event::query()->count())->toBe(0)
        ->and($check->refresh()->latest_run_result)->toBe(CheckResult::Pending)
        ->and($check->consecutive_failures)->toBe(0)
        ->and($check->claim_token)->toBeNull();
});

it('resolves a TLS seam swap made after registry resolution through real execution and recording', function (bool $verified, RunResult $expected): void {
    config(['certificate_health.ca_file' => base_path('docker/fixtures/tls/ca.crt')]);
    $registry = app(CheckRegistry::class);
    $registry->get(CheckKey::CertificateHealth);
    $pem = file_get_contents(base_path('docker/fixtures/tls/valid.crt'));
    $site = Site::factory()->create(['url' => 'https://fixtures.test']);
    $check = Check::factory()->create(['site_id' => $site->id, 'type' => CheckKey::CertificateHealth]);
    app()->instance(CertificateFetcher::class, (new FakeCertificateFetcher)->respondWith('fixtures.test',
        new CertificateFetchResult([$pem], 'fixtures.test', 443, $verified)));
    $job = claimedJob($check);
    app()->call($job->handle(...));
    $run = CheckRun::query()->sole();
    expect($run->result)->toBe($expected)->and($run->metrics['is_valid'])->toBe($verified)
        ->and(DB::table('detected_certificates')->value('is_valid'))->toBe($verified)
        ->and(DB::table('certificate_health_snapshots')->whereNotNull('run_ended_at')->count())->toBe(1);
})->with([
    'native success' => [true, RunResult::Succeeded],
    'native rejection despite parseable PEM' => [false, RunResult::Failed],
]);

it('records legacy short secrets as translated configuration errors rather than target downtime', function (string $locale, string $hint, string $location): void {
    app()->setLocale($locale);
    $schema = app(CheckRegistry::class)->get(CheckKey::Uptime)->definition()->configSchema;
    $check = Check::factory()->create(['failure_threshold' => 1, 'config' => $schema->validateConfig(['http_client_headers' => [['name' => 'Authorization', 'value' => 'old-valid-secret']]])]);
    if ($location === 'check') {
        $check->forceFill(['config_secrets' => ['http_client_headers' => [['value' => 'abc']]]])->save();
    } else {
        $check->site->update(['crawler_headers' => [['name' => 'X-Legacy', 'value' => 'abc']]]);
    }
    app()->call(claimedJob($check)->handle(...));
    $run = CheckRun::query()->sole();
    expect($run->result)->toBe(RunResult::Errored)
        ->and(json_decode($run->error, true, flags: JSON_THROW_ON_ERROR)['message'])->toBe($hint)
        ->and(app(SiteState::class)->derive($check->site))->toBe(SiteStatus::CheckError)
        ->and($check->refresh()->claim_token)->toBeNull();
    expect(app(OutboundHttp::class)->requests)->toBe([]);
})->with([
    ['en', 'Secret shorter than 4 characters; please renew it.', 'check'],
    ['de', 'Das Geheimnis ist kürzer als 4 Zeichen. Bitte erneuern.', 'check'],
    ['en', 'Secret shorter than 4 characters; please renew it.', 'crawler'],
    ['de', 'Das Geheimnis ist kürzer als 4 Zeichen. Bitte erneuern.', 'crawler'],
]);
