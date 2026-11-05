<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Actions\CreateSite;
use Heptapod\Addons\Domain\BoundedWhoisClient;
use Heptapod\Checks\Execution\ClaimService;
use Heptapod\Checks\Execution\RunOrigin;
use Heptapod\Checks\Recording\RecordableRun;
use Heptapod\Checks\Recording\ResultRecorder;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\DomainRegistrationLookup;
use Heptapod\Contracts\DomainRegistrationResult;
use Heptapod\Contracts\DomainRegistrationSource;
use Heptapod\Contracts\ExternalTimeoutException;
use Heptapod\Contracts\Http\FetchException;
use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\Http\FetchRequest;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\Http\RedirectPolicy;
use Heptapod\Contracts\Http\TargetAddressPolicy;
use Heptapod\Contracts\PublicSuffixList;
use Heptapod\Contracts\RunReference;
use Heptapod\Contracts\RunResult;
use Heptapod\Jobs\RunCheck;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Client;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Queries\MonitorCountsReader;
use Heptapod\Testing\ExamplePublicSuffixList;
use Heptapod\Testing\FakeDomainRegistrationLookup;
use Heptapod\Testing\FakeOutboundHttp;
use Heptapod\Testing\LoopbackTargetAddressPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Klkvsk\Whoeasy\Client\Exception\ClientTimeoutException;
use Klkvsk\Whoeasy\Client\Whois\HttpWhoisClient;
use Klkvsk\Whoeasy\Registry\ServerRegistry;
use Klkvsk\Whoeasy\Whoeasy;
use Symfony\Component\Process\Process;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class);

/** @return array<string, mixed> */
function domainRdap(string $domain = 'example.com', ?string $expiry = '2028-08-13T04:00:00Z', array $statuses = ['active'], string $registrar = 'Example registrar'): array
{
    return ['objectClassName' => 'domain', 'ldhName' => $domain, 'status' => $statuses,
        'events' => $expiry === null ? [] : [['eventAction' => 'expiration', 'eventDate' => $expiry]],
        'nameservers' => [['ldhName' => 'NS1.EXAMPLE.COM']],
        'entities' => [['roles' => ['registrar'], 'vcardArray' => ['vcard', [['fn', [], 'text', $registrar]]]]],
    ];
}

function domainHttpRequest(string $url, string $siteHost = 'www.example.com'): FetchRequest
{
    return new FetchRequest($url, headers: ['Accept' => 'application/rdap+json'], maxWireBytes: 262144, maxDecodedBytes: 262144, redirects: RedirectPolicy::Untrusted, maxRedirects: 3, siteHost: $siteHost, timeoutSeconds: 10);
}

function domainCheckFor(string $url = 'https://www.example.com', array $config = []): Check
{
    $site = Site::factory()->create(['url' => $url]);

    return Check::factory()->create(['site_id' => $site->id, 'type' => CheckKey::Domain,
        'config' => app(CheckRegistry::class)->get(CheckKey::Domain)->definition()->configSchema->validateConfig($config)]);
}

function executeDomainJob(Check $check): CheckRun
{
    expect(app(ClaimService::class)->runNow($check->id, RunOrigin::System))->not->toBeNull();
    $definition = app(CheckRegistry::class)->get(CheckKey::Domain)->definition();
    $job = new RunCheck($check->id, $check->refresh()->claim_token, $definition->timeout, $definition->queue);
    app()->call($job->handle(...));

    return CheckRun::query()->orderByDesc('ended_at')->orderByDesc('id')->firstOrFail();
}

beforeEach(function (): void {
    Queue::fake();
    app()->instance(OutboundHttp::class, new FakeOutboundHttp);
    // WHOIS registries require public addresses; only the local whois-server.php fixture on 127.0.0.1 is exempt.
    app()->extend(TargetAddressPolicy::class, static fn (TargetAddressPolicy $policy): LoopbackTargetAddressPolicy => new LoopbackTargetAddressPolicy($policy));
    $this->travelTo(CarbonImmutable::parse(DB::scalar('SELECT clock_timestamp()::text')));
});

it('enables domain by default while preserving compatible monitor settings', function (string $scheme): void {
    $site = app(CreateSite::class)(Client::factory()->create()->id, [
        'url' => $scheme.'://www.example.com',
        'domain_check_settings' => ['expires_soon_threshold_in_days' => 30],
    ]);
    $check = $site->checks()->where('type', CheckKey::Domain)->firstOrFail();
    expect($check->enabled)->toBeTrue()->and($check->config)->toBe(['expires_soon_threshold_in_days' => 30]);
})->with(['http', 'https']);

it('evaluates registration fixtures after a seam swap through real execution and recording', function (string $domain, array|string $raw, RunResult $expected, string $reason, string $summary): void {
    app(CheckRegistry::class)->get(CheckKey::Domain);
    if (is_array($raw) && in_array($summary, ['expires_soon', 'expired'], true)) {
        $at = CarbonImmutable::parse(DB::scalar('SELECT clock_timestamp()::text'));
        $raw['events'][0]['eventDate'] = ($summary === 'expires_soon' ? $at->addDays(20) : $at->subDay())->toIso8601String();
    }
    $source = is_array($raw) ? DomainRegistrationSource::Rdap : DomainRegistrationSource::Whois;
    app()->instance(DomainRegistrationLookup::class, (new FakeDomainRegistrationLookup)->respondWith($domain,
        new DomainRegistrationResult($source, $raw, 'whois.nic.ch')));
    $check = domainCheckFor('https://www.'.$domain);
    $run = executeDomainJob($check);
    expect($run->result)->toBe($expected)->and($run->summary)->toBe('domain.'.$summary)
        ->and($run->evidence['not_supported_reason'])->toBe($reason === '' ? null : $reason);
    if ($expected === RunResult::Errored) {
        expect($run->metrics)->toBeNull()->and(DB::table('domain_snapshots')->count())->toBe(0);
    } else {
        expect(DB::table('domain_snapshots')->whereNotNull('run_ended_at')->count())->toBe(1);
    }
    if ($domain === 'example.de' && $expected === RunResult::Succeeded) {
        expect($run->evidence['expires_at'])->toBeNull()->and(Incident::query()->count())->toBe(0);
    }
})->with([
    'healthy' => ['example.com', domainRdap(), RunResult::Succeeded, '', 'valid'],
    'warning inside DB-relative window' => ['example.com', domainRdap(), RunResult::Warning, '', 'expires_soon'],
    'expired before DB clock' => ['example.com', domainRdap(), RunResult::Failed, '', 'expired'],
    'pendingDelete' => ['example.com', domainRdap(statuses: ['pendingDelete']), RunResult::Failed, '', 'registration_at_risk'],
    'redemption period' => ['example.com', domainRdap(statuses: ['redemption period']), RunResult::Failed, '', 'registration_at_risk'],
    'clientHold' => ['example.com', domainRdap(statuses: ['clientHold']), RunResult::Failed, '', 'registration_at_risk'],
    'server hold' => ['example.com', domainRdap(statuses: ['server hold']), RunResult::Failed, '', 'registration_at_risk'],
    'prohibitions are normal' => ['example.com', domainRdap(statuses: ['client transfer prohibited', 'serverDeleteProhibited']), RunResult::Succeeded, '', 'valid'],
    'DENIC no expiry' => ['example.de', domainRdap('example.de', null), RunResult::Succeeded, 'registry_does_not_publish', 'registry_does_not_publish'],
    'DENIC danger despite no expiry' => ['example.de', domainRdap('example.de', null, ['pending delete']), RunResult::Failed, 'registry_does_not_publish', 'registration_at_risk'],
    'SWITCH denial' => ['example.ch', 'Requests of this client are not permitted. Please use https://www.nic.ch/whois/ for queries.', RunResult::Errored, 'lookup_unavailable', 'lookup_unavailable'],
    'empty RDAP' => ['example.ch', [], RunResult::Errored, 'lookup_unavailable', 'lookup_unavailable'],
    'AT identifiable no expiry' => ['example.at', "domain: example.at\nnserver: ns1.example.com\nchanged: 2024-01-01T00:00:00Z\n", RunResult::Errored, 'lookup_unavailable', 'lookup_unavailable'],
    'EU identifiable no expiry' => ['example.eu', "Domain: example.eu\nRegistrar: Example registrar\n", RunResult::Errored, 'lookup_unavailable', 'lookup_unavailable'],
    'mismatched record' => ['example.de', domainRdap(), RunResult::Errored, 'lookup_unavailable', 'lookup_unavailable'],
]);

it('uses PSL and IDNA for the registry identity and preserves WHOIS dates without fabricating RDAP', function (): void {
    app()->instance(PublicSuffixList::class, new ExamplePublicSuffixList);
    $raw = "Domain Name: XN--BCHER-KVA.CO.EXAMPLE\nRegistry Expiry Date: 2028-01-01T00:00:00Z\nCreation Date: 2020-01-01T00:00:00Z\nRegistrar: Example registrar\nName Server: NS1.EXAMPLE.COM\nDomain Status: clientTransferProhibited https://icann.org/epp#clientTransferProhibited\n";
    app()->instance(DomainRegistrationLookup::class, (new FakeDomainRegistrationLookup)->respondWith('xn--bcher-kva.co.example',
        new DomainRegistrationResult(DomainRegistrationSource::Whois, $raw, 'whois.example')));
    $run = executeDomainJob(domainCheckFor('https://shop.bücher.co.example'));
    expect($run->result)->toBe(RunResult::Succeeded)->and($run->evidence['domain'])->toBe('xn--bcher-kva.co.example')
        ->and($run->evidence['rdap_domain_response'])->toBeNull()->and($run->evidence['expires_at'])->toBe('2028-01-01')
        ->and($run->evidence['registered_at'])->toBe('2020-01-01')
        ->and($run->evidence['nameservers'])->toBe(['ns1.example.com']);
});

it('honors custom thresholds and rejects invalid config instead of accepting editable detection reasons', function (): void {
    $schema = app(CheckRegistry::class)->get(CheckKey::Domain)->definition()->configSchema;
    foreach ([-1, '30', 1.5] as $invalid) {
        expect(fn () => $schema->validateConfig(['expires_soon_threshold_in_days' => $invalid]))->toThrow(ValidationException::class);
    }
    expect(fn () => $schema->validateConfig(['not_supported_reason' => 'registry_does_not_publish']))->toThrow(ValidationException::class);
    app()->instance(DomainRegistrationLookup::class, (new FakeDomainRegistrationLookup)->respondWith('example.com',
        new DomainRegistrationResult(DomainRegistrationSource::Rdap, domainRdap(expiry: CarbonImmutable::parse(DB::scalar('SELECT clock_timestamp()::text'))->addDays(10)->toIso8601String()), 'https://rdap.example.com/')));
    expect(executeDomainJob(domainCheckFor(config: ['expires_soon_threshold_in_days' => 5]))->result)->toBe(RunResult::Succeeded);
});

it('emits accepted registration and expiry changes once across errors and retention at unchanged severity', function (): void {
    $fake = new FakeDomainRegistrationLookup;
    app()->instance(DomainRegistrationLookup::class, $fake);
    $check = domainCheckFor();
    $fake->respondWith('example.com', new DomainRegistrationResult(DomainRegistrationSource::Rdap, domainRdap(), 'https://rdap.example.com/'));
    expect(executeDomainJob($check)->result)->toBe(RunResult::Succeeded);
    $this->travel(1)->seconds();
    expect(executeDomainJob($check)->result)->toBe(RunResult::Succeeded);
    expect(Event::query()->where('type', 'like', 'domain.%')->count())->toBe(0);
    $baseline = DB::table('domain_snapshots')->orderByDesc('run_id')->value('run_id');
    $store = app(CheckRegistry::class)->get(CheckKey::Domain)->persistence();
    $store->prune(CarbonImmutable::now()->addDays(61));
    expect(DB::table('domain_snapshots')->pluck('run_id')->all())->toBe([$baseline]);
    $this->travel(1)->seconds();
    $fake->respondWith('example.com', new ExternalTimeoutException('Registry timed out'));
    expect(executeDomainJob($check)->result)->toBe(RunResult::Errored);
    $this->travel(1)->seconds();
    $fake->respondWith('example.com', new DomainRegistrationResult(DomainRegistrationSource::Rdap,
        domainRdap(expiry: '2029-08-13T04:00:00Z', statuses: ['active', 'client transfer prohibited'], registrar: 'New registrar'), 'https://rdap.example.com/'));
    $changed = executeDomainJob($check);
    expect($changed->result)->toBe(RunResult::Succeeded);
    $this->travel(1)->seconds();
    executeDomainJob($check);
    $events = Event::query()->where('type', 'like', 'domain.%')->orderBy('type')->get();
    // The recorder wraps every addon payload in the run envelope (summary, summary_params, run_id, run_ended_at) since d5dd89f9.
    expect($events->pluck('type')->all())->toBe(['domain.expiry_changed', 'domain.registration_changed'])
        ->and($events[0]->payload)->toEqual(['domain' => 'example.com', 'old' => ['expires_at' => '2028-08-13T04:00:00+00:00', 'source' => 'rdap', 'date_precision' => 'exact'], 'new' => ['expires_at' => '2029-08-13T04:00:00+00:00', 'source' => 'rdap', 'date_precision' => 'exact'],
            'summary' => 'domain.valid', 'summary_params' => [], 'run_id' => $changed->id, 'run_ended_at' => $changed->ended_at->format('Y-m-d\TH:i:s.uP')])
        ->and($events[1]->payload['new']['registrar'])->toBe('New registrar')
        ->and($events[1]->payload['new']['domain_statuses'])->toEqual(['active' => true, 'client_transfer_prohibited' => true]);
});

it('fences staging, finalization, rollback and repeated discard by attempt token', function (): void {
    $check = domainCheckFor();
    $at = CarbonImmutable::now();
    $context = new CheckContext($check->site_id, $check->id, 123, (string) Str::uuid(), $check->site->url, $at, $at->addSeconds(120), [], [], null);
    $other = new CheckContext($context->siteId, $context->checkId, 123, (string) Str::uuid(), $context->url, $at, $at->addSeconds(120), [], [], null);
    app()->instance(DomainRegistrationLookup::class, (new FakeDomainRegistrationLookup)->respondWith('example.com',
        new DomainRegistrationResult(DomainRegistrationSource::Rdap, domainRdap(), 'https://rdap.example.com/')));
    $type = app(CheckRegistry::class)->get(CheckKey::Domain);
    $store = $type->persistence();
    $run = new RunReference(123, $at->addSecond()->setMicrosecond(123456));
    $store->finalize($run, $context->claimToken);
    $store->discard(123, $context->claimToken);
    expect(DB::table('domain_snapshots')->count())->toBe(0);
    $type->execute($context);
    $type->execute($other);
    expect(fn () => DB::transaction(function () use ($store, $run, $context): void {
        $store->finalize($run, $context->claimToken);
        throw new RuntimeException('recorder rollback');
    }))->toThrow(RuntimeException::class, 'recorder rollback');
    expect(DB::table('domain_snapshots')->whereNotNull('run_ended_at')->count())->toBe(0);
    $store->finalize($run, (string) Str::uuid());
    expect(DB::table('domain_snapshots')->whereNotNull('run_ended_at')->count())->toBe(0);
    $store->finalize($run, $other->claimToken);
    $store->finalize($run, $other->claimToken);
    $store->discard(123, $context->claimToken);
    $store->discard(123, $context->claimToken);
    $store->discard(123, $other->claimToken);
    expect(DB::table('domain_snapshots')->count())->toBe(1)->and(DB::table('domain_snapshots')->value('run_ended_at'))->toContain('.123456');
    expect(fn () => $type->execute($other))->toThrow(LogicException::class, 'Cannot stage an accepted');
    expect(fn () => $store->finalize(new RunReference(123, $at), $other->claimToken))->toThrow(LogicException::class);
    expect(fn () => $store->stage($context, 'other', [[]]))->toThrow(InvalidArgumentException::class);
    expect(fn () => $store->stage($context, 'snapshot', []))->toThrow(InvalidArgumentException::class);
});

it('uses registry overrides and IANA bootstrap through the HTTP seam with the engine deadline', function (string $domain, string $server): void {
    $http = app(OutboundHttp::class);
    $http->respondWith(domainHttpRequest('https://data.iana.org/rdap/dns.json', 'www.'.$domain), new FetchResult(200, json_encode(['services' => [[['com', 'net', 'org'], ['https://bootstrap.example/']]]], JSON_THROW_ON_ERROR)))
        ->respondWith(domainHttpRequest($server.'domain/'.$domain, 'www.'.$domain), new FetchResult(200, json_encode(domainRdap($domain), JSON_THROW_ON_ERROR)));
    $run = executeDomainJob(domainCheckFor('https://www.'.$domain));
    expect($run->result)->toBe(RunResult::Succeeded);
    expect(array_column(array_column($http->requests, 'request'), 'url'))->toBe(in_array($domain, ['example.com', 'example.net', 'example.org'], true)
        ? ['https://data.iana.org/rdap/dns.json', $server.'domain/'.$domain] : [$server.'domain/'.$domain]);
    foreach ($http->requests as $request) {
        expect($request['context']->runId)->toBe($run->id)->and($request['context']->startedAt->equalTo($run->started_at))->toBeTrue()
            ->and($request['context']->deadline->equalTo($run->started_at->addSeconds(120)))->toBeTrue();
    }
})->with([
    ['example.de', 'https://rdap.denic.de/'], ['example.ch', 'https://rdap.nic.ch/'],
    ['example.io', 'https://rdap.identitydigital.services/rdap/'], ['example.us', 'https://rdap.nic.us/'],
    ['example.com', 'https://bootstrap.example/'], ['example.net', 'https://bootstrap.example/'], ['example.org', 'https://bootstrap.example/'],
]);

it('uses real bounded WHOIS without referrals after supported HTTP failures', function (string $domain, string|FetchFailureKind $mode): void {
    $server = new Process([PHP_BINARY, base_path('addons/domain/tests/Support/whois-server.php')]);
    $server->start();
    $seen = [];
    set_error_handler(static function (int $severity, string $message) use (&$seen): bool {
        $seen[] = [$severity, $message];

        return true;
    });
    try {
        $ready = $server->waitUntil(static fn (string $type, string $output): bool => $type === Process::OUT && str_contains($output, "\n"));
        expect($ready)->toBeTrue($server->getErrorOutput());
        $address = trim(explode("\n", $server->getOutput())[0]);
        $tld = substr($domain, strrpos($domain, '.') + 1);
        app()->instance(ServerRegistry::class, new ServerRegistry(tldServers: ['.'.$tld => [$address, null]]));
        if ($mode !== 'direct') {
            app(OutboundHttp::class)->respondWith(domainHttpRequest('https://rdap.denic.de/domain/'.$domain, 'www.'.$domain),
                $mode instanceof FetchFailureKind ? new FetchResult(failure: new FetchFailure($mode)) : new FetchResult(200, json_encode(domainRdap($domain, null), JSON_THROW_ON_ERROR)));
            config(['domain.rdap_overrides.'.$tld => 'https://rdap.denic.de/']);
        }
        $run = executeDomainJob(domainCheckFor('https://www.'.$domain));
        expect($run->error)->toBeNull();
        expect($run->result)->toBe(RunResult::Succeeded)->and($run->evidence['source'])->toBe('whois')
            ->and($run->evidence['server'])->toBe($address)->and($run->evidence['expires_at'])->toBe('2028-01-01');
        trigger_error('handler restored', E_USER_WARNING);
        expect($seen)->toBe([[E_USER_WARNING, 'handler restored']]);
        $server->wait();
        expect($server->getExitCode())->toBe(0)->and($server->getOutput())->toContain('query='.$domain);
        if ($mode === 'direct') {
            expect(app(OutboundHttp::class)->requests)->toBe([]);
        } else {
            expect(app(OutboundHttp::class)->requests)->toHaveCount(1);
        }
    } finally {
        restore_error_handler();
        $server->stop();
        expect($server->isRunning())->toBeFalse();
    }
})->with([
    'AT direct' => ['example.at', 'direct'],
    'EU direct' => ['example.eu', 'direct'],
    'RDAP status failure fallback' => ['example.ch', FetchFailureKind::HttpStatus],
    'RDAP timeout fallback' => ['example.ch', FetchFailureKind::Timeout],
    'RDAP TLS fallback' => ['example.ch', FetchFailureKind::Tls],
    'RDAP DNS fallback' => ['example.ch', FetchFailureKind::Dns],
    'RDAP connection fallback' => ['example.ch', FetchFailureKind::Connect],
    'RDAP missing-date fallback' => ['example.ch', 'missing-date'],
]);

it('records WHOIS dates honestly at day precision when the package loses timezone information', function (string $date, string $day): void {
    app()->instance(DomainRegistrationLookup::class, (new FakeDomainRegistrationLookup)->respondWith('example.com',
        new DomainRegistrationResult(DomainRegistrationSource::Whois, "Domain Name: EXAMPLE.COM\nRegistry Expiry Date: ".$date."\n", 'whois.verisign-grs.com')));
    $run = executeDomainJob(domainCheckFor());
    expect($run->result)->toBe(RunResult::Succeeded)->and($run->evidence['expires_at'])->toBe($day)
        ->and($run->evidence['date_precision'])->toBe('day');
})->with([
    'offset may cross UTC day; one-day uncertainty' => ['2028-01-01T00:30:00+02:00', '2028-01-01'],
    'UTC' => ['2028-01-01T00:30:00Z', '2028-01-01'],
    'date only' => ['2028-01-01', '2028-01-01'],
    'abbreviation' => ['2028-01-01 12:00:00 CET', '2028-01-01'],
]);

it('counts only the latest domain observation inside thirty days and reads WHOIS dates in UTC', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04T00:30:00Z'));
    $check = domainCheckFor();
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $lookup = new FakeDomainRegistrationLookup;
    app()->instance(DomainRegistrationLookup::class, $lookup);
    $future = "Domain Name: EXAMPLE.COM\nRegistry Expiry Date: 2026-11-18\n";
    $lookup->respondWith('example.com', new DomainRegistrationResult(DomainRegistrationSource::Whois, $future, 'whois.verisign-grs.com'));
    executeDomainJob($check);
    expect(app(MonitorCountsReader::class)->read($owner)->domains)->toBe(0);

    $this->travel(1)->minutes();
    $edge = "Domain Name: EXAMPLE.COM\nRegistry Expiry Date: 2026-11-03\n";
    $lookup->respondWith('example.com', new DomainRegistrationResult(DomainRegistrationSource::Whois, $edge, 'whois.verisign-grs.com'));
    executeDomainJob($check);
    DB::statement("SET LOCAL TIME ZONE 'Pacific/Honolulu'");
    expect(DB::scalar("SELECT current_setting('TimeZone')"))->toBe('Pacific/Honolulu')
        ->and(app(MonitorCountsReader::class)->read($owner)->domains)->toBe(1);
});

it('preserves exact RDAP offsets in UTC instead of applying WHOIS day precision', function (): void {
    app()->instance(DomainRegistrationLookup::class, (new FakeDomainRegistrationLookup)->respondWith('example.com',
        new DomainRegistrationResult(DomainRegistrationSource::Rdap, domainRdap(expiry: '2028-01-01T00:30:00+02:00'), 'https://rdap.example.com/')));
    $run = executeDomainJob(domainCheckFor());
    expect($run->result)->toBe(RunResult::Succeeded)->and($run->evidence['expires_at'])->toBe('2027-12-31T22:30:00+00:00')
        ->and($run->evidence['date_precision'])->toBe('exact');
});

it('records a real WHOIS timeout as an engine error and restores the handler on failure', function (): void {
    $server = new Process([PHP_BINARY, base_path('addons/domain/tests/Support/whois-server.php'), 'timeout']);
    $server->start();
    $seen = [];
    set_error_handler(static function (int $severity) use (&$seen): bool {
        $seen[] = $severity;

        return true;
    });
    try {
        expect($server->waitUntil(static fn (string $type, string $output): bool => $type === Process::OUT && str_contains($output, "\n")))->toBeTrue();
        $address = trim(explode("\n", $server->getOutput())[0]);
        app()->instance(ServerRegistry::class, new ServerRegistry(tldServers: ['.at' => [$address, null]]));
        $run = executeDomainJob(domainCheckFor('https://example.at'));
        expect($run->result)->toBe(RunResult::Errored)->and($run->metrics)->toBeNull()
            ->and(json_decode($run->error, true, flags: JSON_THROW_ON_ERROR)['class'])->toBe(ExternalTimeoutException::class)
            ->and(DB::table('domain_snapshots')->count())->toBe(0);
        trigger_error('handler restored', E_USER_WARNING);
        expect($seen)->toBe([E_USER_WARNING]);
    } finally {
        restore_error_handler();
        $server->stop();
        expect($server->isRunning())->toBeFalse();
    }
});

it('stores an empty registry status map as a JSON object, not a list', function (): void {
    app()->instance(DomainRegistrationLookup::class, (new FakeDomainRegistrationLookup)->respondWith('example.com',
        new DomainRegistrationResult(DomainRegistrationSource::Rdap, domainRdap(statuses: []), 'https://rdap.example.com/')));
    expect(executeDomainJob(domainCheckFor())->result)->toBe(RunResult::Succeeded);
    expect(DB::scalar("SELECT jsonb_typeof(snapshot->'domain_statuses') FROM domain_snapshots"))->toBe('object');
    expect(DB::scalar("SELECT (snapshot->'domain_statuses')::text FROM domain_snapshots"))->toBe('{}');
});

it('notifies renewals rather than RDAP to WHOIS precision jitter', function (string $date, int $count): void {
    $fake = new FakeDomainRegistrationLookup;
    app()->instance(DomainRegistrationLookup::class, $fake);
    $check = domainCheckFor();
    $fake->respondWith('example.com', new DomainRegistrationResult(DomainRegistrationSource::Rdap,
        domainRdap(expiry: '2028-01-01T00:00:00Z', statuses: [], registrar: 'Example registrar'), 'https://rdap.example.com/'));
    executeDomainJob($check);
    $this->travel(1)->seconds();
    $raw = "Domain Name: EXAMPLE.COM\nRegistry Expiry Date: ".$date."\nRegistrar: Example registrar\n";
    $fake->respondWith('example.com', new DomainRegistrationResult(DomainRegistrationSource::Whois, $raw, 'whois.verisign-grs.com'));
    executeDomainJob($check);
    $this->travel(1)->seconds();
    executeDomainJob($check);
    expect(Event::query()->where('type', 'domain.expiry_changed')->count())->toBe($count);
    if ($count === 1) {
        expect(Event::query()->where('type', 'domain.expiry_changed')->sole()->payload['new'])->toEqual([
            'expires_at' => '2029-01-01', 'source' => 'whois', 'date_precision' => 'day',
        ]);
    }
})->with([
    'same calendar date' => ['2028-01-01', 0],
    'one-day UTC uncertainty' => ['2027-12-31', 0],
    'renewal' => ['2029-01-01', 1],
]);

function recordDomainAt(Check $check, CarbonImmutable $at): CheckRun
{
    $month = $at->utc()->startOfMonth();
    $name = 'check_runs_'.$month->format('Y_m');
    $until = $month->addMonth();
    DB::statement("CREATE TABLE IF NOT EXISTS {$name} PARTITION OF check_runs FOR VALUES FROM ('{$month->toIso8601String()}') TO ('{$until->toIso8601String()}')");
    expect(app(ClaimService::class)->runNow($check->id, RunOrigin::System))->not->toBeNull();
    $check->refresh();
    $runId = DB::scalar("SELECT nextval('check_runs_id_seq')");
    $check->forceFill(['run_started_at' => $at, 'active_run_id' => $runId])->save();
    $context = new CheckContext($check->site_id, $check->id, $runId, $check->claim_token, $check->site->url, $at, $at->addSeconds(120), $check->executionConfig(), $check->site->crawler_headers ?? [], null);
    $outcome = app(CheckRegistry::class)->get(CheckKey::Domain)->execute($context);
    expect(app(ResultRecorder::class)->record(new RecordableRun($check->id, $check->claim_token, $at, $at->addSecond(), $outcome))->accepted)->toBeTrue();

    return CheckRun::query()->whereKey($runId)->firstOrFail();
}

it('enforces the total WHOIS deadline while the registry slowly drips bytes', function (): void {
    $server = new Process([PHP_BINARY, base_path('addons/domain/tests/Support/whois-server.php'), 'slow-drip']);
    $server->start();
    try {
        expect($server->waitUntil(static fn (string $type, string $output): bool => $type === Process::OUT && str_contains($output, "\n")))->toBeTrue();
        $address = trim(explode("\n", $server->getOutput())[0]);
        $started = hrtime(true);
        $failure = null;
        try {
            app(BoundedWhoisClient::class)->query($address, 'example.at', timeout: 1);
        } catch (ClientTimeoutException $exception) {
            $failure = $exception;
        }
        $elapsed = (hrtime(true) - $started) / 1_000_000_000;
        expect($elapsed)->toBeLessThanOrEqual(2.0)->and($failure)->toBeInstanceOf(ClientTimeoutException::class);
    } finally {
        $server->stop();
    }
});

it('rejects oversized TCP WHOIS before the parser buffers the complete response', function (): void {
    $server = new Process([PHP_BINARY, base_path('addons/domain/tests/Support/whois-server.php'), 'oversized']);
    $server->start();
    try {
        expect($server->waitUntil(static fn (string $type, string $output): bool => $type === Process::OUT && str_contains($output, "\n")))->toBeTrue();
        $address = trim(explode("\n", $server->getOutput())[0]);
        app()->instance(ServerRegistry::class, new ServerRegistry(tldServers: ['.at' => [$address, null]]));
        $run = executeDomainJob(domainCheckFor('https://example.at'));
        $server->wait();
        preg_match('/sent=(\d+)/', $server->getOutput(), $match);
        expect((int) $match[1])->toBeLessThan(524288);
        expect($run->result)->toBe(RunResult::Errored)->and(DB::table('domain_snapshots')->count())->toBe(0);
    } finally {
        $server->stop();
    }
});

it('records terminal HTTP seam failures without replacing the accepted registration baseline', function (string $domain, FetchFailureKind $kind): void {
    $check = domainCheckFor('https://www.'.$domain);
    app()->instance(DomainRegistrationLookup::class, (new FakeDomainRegistrationLookup)->respondWith($domain,
        new DomainRegistrationResult(DomainRegistrationSource::Rdap, domainRdap($domain), 'https://rdap.example.com/')));
    $baseline = executeDomainJob($check);
    app()->forgetInstance(DomainRegistrationLookup::class);
    Cache::forget('laravel-rdap-servers');
    $url = $domain === 'example.com' ? 'https://data.iana.org/rdap/dns.json' : 'https://rdap.nic.ch/domain/example.ch';
    app(OutboundHttp::class)->respondWith(domainHttpRequest($url, 'www.'.$domain), new FetchResult(failure: new FetchFailure($kind)));
    $run = executeDomainJob($check);
    // Guard refusals name their policy class (be891f1e); other transport failures keep the lookup cause.
    expect($run->result)->toBe(RunResult::Errored)->and($run->summary)->toBe(match ($kind) {
        FetchFailureKind::RedirectBlocked => 'checks.policy.redirect_blocked',
        FetchFailureKind::PrivateTarget => 'checks.policy.private_network',
        default => 'domain.lookup_unavailable',
    })
        ->and($run->evidence['not_supported_reason'])->toBe('lookup_unavailable')
        ->and(json_decode($run->error, true, flags: JSON_THROW_ON_ERROR))->toMatchArray(['class' => FetchException::class, 'message' => 'Outbound HTTP failed: '.$kind->value.'.']);
    expect(DB::table('domain_snapshots')->pluck('run_id')->all())->toBe([$baseline->id])
        ->and(Event::query()->where('type', 'like', 'domain.%')->count())->toBe(0)
        ->and(app(OutboundHttp::class)->requests)->toHaveCount(1);
})->with([
    'RDAP exceeds decoded or wire limit' => ['example.ch', FetchFailureKind::TooLarge],
    'IANA bootstrap exceeds limit' => ['example.com', FetchFailureKind::TooLarge],
    'RDAP private redirect blocked' => ['example.ch', FetchFailureKind::RedirectBlocked],
    'IANA private target' => ['example.com', FetchFailureKind::PrivateTarget],
    'RDAP private target' => ['example.ch', FetchFailureKind::PrivateTarget],
    'invalid RDAP request' => ['example.ch', FetchFailureKind::InvalidRequest],
]);

it('classifies a malformed bootstrap endpoint as unavailable without replacing the accepted baseline', function (): void {
    $check = domainCheckFor();
    app()->instance(DomainRegistrationLookup::class, (new FakeDomainRegistrationLookup)->respondWith('example.com',
        new DomainRegistrationResult(DomainRegistrationSource::Rdap, domainRdap(), 'https://rdap.example.com/')));
    $baseline = executeDomainJob($check);
    app()->forgetInstance(DomainRegistrationLookup::class);
    Cache::forget('laravel-rdap-servers');
    app(OutboundHttp::class)->respondWith(domainHttpRequest('https://data.iana.org/rdap/dns.json'),
        new FetchResult(200, json_encode(['services' => [[['com'], ['ftp://registry.example/']]]], JSON_THROW_ON_ERROR)));
    $run = executeDomainJob($check);
    expect($run->result)->toBe(RunResult::Errored)->and($run->summary)->toBe('domain.lookup_unavailable')
        ->and($run->evidence['not_supported_reason'])->toBe('lookup_unavailable')
        ->and(json_decode($run->error, true, flags: JSON_THROW_ON_ERROR))->toMatchArray([
            'class' => FetchException::class, 'message' => 'Outbound HTTP failed: invalid_request.',
        ])
        ->and($run->error)->not->toContain('ftp://', 'registry.example');
    expect(DB::table('domain_snapshots')->pluck('run_id')->all())->toBe([$baseline->id])
        ->and(Event::query()->where('type', 'like', 'domain.%')->count())->toBe(0)
        ->and(app(OutboundHttp::class)->requests)->toHaveCount(1);
});

it('accepts a normal redirected RDAP observation supplied by the guarded HTTP seam', function (): void {
    app(OutboundHttp::class)->respondWith(domainHttpRequest('https://rdap.nic.ch/domain/example.ch', 'www.example.ch'),
        new FetchResult(200, json_encode(domainRdap('example.ch'), JSON_THROW_ON_ERROR), transfers: [['total_time' => 0.1], ['total_time' => 0.2]]));
    expect(executeDomainJob(domainCheckFor('https://www.example.ch'))->result)->toBe(RunResult::Succeeded);
});

it('rejects the bundled VN HTTP WHOIS route without invoking its HTTP client', function (): void {
    $registry = new ServerRegistry;
    expect($registry->resolve('example.vn')->httpUrl)->toBe('https://whois.net.vn');
    $client = new class extends HttpWhoisClient
    {
        public bool $called = false;

        public function query(string $httpUrl, string $query, string $httpQueryFormat, ?string $scraperName = null, int $timeout = 15, ?string $proxyUri = null): string
        {
            $this->called = true;
            throw new RuntimeException('HTTP WHOIS must not be called.');
        }
    };
    app()->instance(Whoeasy::class, new Whoeasy(registry: $registry, httpWhoisClient: $client));
    config(['domain.whois_only' => ['vn']]);
    $run = executeDomainJob(domainCheckFor('https://example.vn'));
    expect($client->called)->toBeFalse();
    expect($run->result)->toBe(RunResult::Errored)->and($run->summary)->toBe('domain.lookup_unavailable')
        ->and($run->evidence['not_supported_reason'])->toBe('lookup_unavailable')
        ->and(json_decode($run->error, true, flags: JSON_THROW_ON_ERROR)['message'])->toBe('registry only offers HTTP WHOIS (unsupported)')
        ->and(DB::table('domain_snapshots')->count())->toBe(0);
    expect(app(OutboundHttp::class)->requests)->toBe([]);
});

it('retains authoritative RDAP danger without expiry instead of contradictory WHOIS success', function (string $status): void {
    $server = new Process([PHP_BINARY, base_path('addons/domain/tests/Support/whois-server.php')]);
    $server->start();
    try {
        expect($server->waitUntil(static fn (string $type, string $output): bool => $type === Process::OUT && str_contains($output, "\n")))->toBeTrue();
        $address = trim(explode("\n", $server->getOutput())[0]);
        app()->instance(ServerRegistry::class, new ServerRegistry(tldServers: ['.ch' => [$address, null]]));
        app(OutboundHttp::class)->respondWith(domainHttpRequest('https://rdap.nic.ch/domain/example.ch', 'example.ch'), new FetchResult(200, json_encode(domainRdap('example.ch', null, [$status]), JSON_THROW_ON_ERROR)));
        $run = executeDomainJob(domainCheckFor('https://example.ch'));
        expect($run->result)->toBe(RunResult::Failed)->and($run->summary)->toBe('domain.registration_at_risk')
            ->and($run->evidence['source'])->toBe('rdap')->and($run->evidence['expires_at'])->toBeNull();
        expect(DB::table('domain_snapshots')->whereNotNull('run_ended_at')->count())->toBe(1);
    } finally {
        $server->stop();
    }
})->with(['pending delete', 'redemptionPeriod', 'clientHold', 'server hold']);

it('rejects known secrets in every evidence key and scalar before persistence and events', function (string $kind): void {
    $fake = new FakeDomainRegistrationLookup;
    app()->instance(DomainRegistrationLookup::class, $fake);
    $check = domainCheckFor();
    $check->site->forceFill(['crawler_headers' => [['name' => 'X-Secret', 'value' => '804617'], ['name' => 'Authorization', 'value' => 'SeCrEtToken']]])->save();
    $fake->respondWith('example.com', new DomainRegistrationResult(DomainRegistrationSource::Rdap, domainRdap(), 'https://rdap.example.com/'));
    executeDomainJob($check);
    $raw = domainRdap();
    match ($kind) {
        'numeric key' => $raw['remarks'] = [804617 => 'private'],
        'numeric value' => $raw['remarks'] = [804617],
        'case-varied value' => $raw['remarks'] = ['secretTOKEN'],
        'case-varied key' => $raw['remarks'] = ['secretTOKEN' => true],
        'registrar' => $raw = domainRdap(registrar: 'Registrar secrettoken'),
        'status' => $raw = domainRdap(statuses: ['active', 'SECRETTOKEN']),
        'server' => null,
    };
    $logs = [];
    Log::listen(static function (MessageLogged $log) use (&$logs): void {
        $logs[] = [$log->message, $log->context];
    });
    $fake->respondWith('example.com', new DomainRegistrationResult(DomainRegistrationSource::Rdap, $raw, $kind === 'server' ? 'https://secretTOKEN.example.com/' : 'https://rdap.example.com/'));
    $run = executeDomainJob($check);
    expect($run->result)->toBe(RunResult::Errored)->and($run->evidence)->toBe(['verified_nothing' => false])
        ->and(json_decode($run->error, true, flags: JSON_THROW_ON_ERROR)['message'])->toBe('lookup response contained a configured secret value');
    $all = strtolower(json_encode([$run->evidence, $run->error, DB::table('domain_snapshots')->pluck('snapshot')->all(), Event::query()->where('type', 'like', 'domain.%')->pluck('payload')->all(), $logs], JSON_THROW_ON_ERROR));
    expect($all)->not->toContain('804617')->not->toContain('secrettoken');
    expect(DB::table('domain_snapshots')->count())->toBe(1)
        ->and(Event::query()->where('type', 'like', 'domain.%')->count())->toBe(0);
})->with(['numeric key', 'numeric value', 'case-varied value', 'case-varied key', 'registrar', 'status', 'server']);

it('falls back to real WHOIS on unsuccessful or malformed bootstrap and empty cache', function (array|string $body, int $status): void {
    Cache::forget('laravel-rdap-servers');
    $server = new Process([PHP_BINARY, base_path('addons/domain/tests/Support/whois-server.php')]);
    $server->start();
    try {
        expect($server->waitUntil(static fn (string $type, string $output): bool => $type === Process::OUT && str_contains($output, "\n")))->toBeTrue();
        $address = trim(explode("\n", $server->getOutput())[0]);
        app()->instance(ServerRegistry::class, new ServerRegistry(tldServers: ['.com' => [$address, null]]));
        app(OutboundHttp::class)->respondWith(domainHttpRequest('https://data.iana.org/rdap/dns.json'), $status === 503
            ? new FetchResult(503, failure: new FetchFailure(FetchFailureKind::HttpStatus, 503)) : new FetchResult(200, is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR)));
        $run = executeDomainJob(domainCheckFor());
        expect($run->result)->toBe(RunResult::Succeeded)->and($run->evidence['source'])->toBe('whois');
        $server->wait();
        expect($server->getOutput())->toContain('query=example.com');
    } finally {
        $server->stop();
    }
})->with([
    '503 HTML' => ['<html>Unavailable</html>', 503],
    'malformed JSON' => ['<html>not JSON</html>', 200],
    'missing services' => [[], 200],
    'malformed services' => [['services' => [null]], 200],
]);

it('records exact expiry boundaries with explicit contexts instead of the PHP clock', function (int $offset, int $threshold, RunResult $expected): void {
    $at = CarbonImmutable::parse('2026-10-15T12:00:00Z');
    app()->instance(DomainRegistrationLookup::class, (new FakeDomainRegistrationLookup)->respondWith('example.com',
        new DomainRegistrationResult(DomainRegistrationSource::Rdap, domainRdap(expiry: $at->addSeconds($offset)->toIso8601String()), 'https://rdap.example.com/')));
    $run = recordDomainAt(domainCheckFor(config: ['expires_soon_threshold_in_days' => $threshold]), $at);
    expect($run->result)->toBe($expected)->and($run->started_at->equalTo($at))->toBeTrue();
})->with([
    'expired inclusive' => [0, 30, RunResult::Failed],
    'not expired one second later' => [1, 30, RunResult::Warning],
    'warning inclusive' => [30 * 86400, 30, RunResult::Warning],
    'outside warning one second later' => [30 * 86400 + 1, 30, RunResult::Succeeded],
    'custom threshold inclusive' => [5 * 86400, 5, RunResult::Warning],
    'custom threshold outside' => [5 * 86400 + 1, 5, RunResult::Succeeded],
]);

it('keeps custom threshold fixture valid on October 15 instead of relying on frozen PHP time', function (): void {
    DB::statement('DROP TABLE IF EXISTS check_runs_2026_10');
    $at = CarbonImmutable::parse('2026-10-15T12:00:00Z');
    app()->instance(DomainRegistrationLookup::class, (new FakeDomainRegistrationLookup)->respondWith('example.com',
        new DomainRegistrationResult(DomainRegistrationSource::Rdap, domainRdap(expiry: $at->addDays(10)->toIso8601String()), 'https://rdap.example.com/')));
    expect(recordDomainAt(domainCheckFor(config: ['expires_soon_threshold_in_days' => 5]), $at)->result)->toBe(RunResult::Succeeded);
});
