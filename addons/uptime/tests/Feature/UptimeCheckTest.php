<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Message;
use GuzzleHttp\Psr7\Response;
use Heptapod\Checks\Execution\CheckExecutor;
use Heptapod\Checks\Execution\ClaimService;
use Heptapod\Checks\Execution\RunOrigin;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\Http\FetchException;
use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\Http\FetchRequest;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\Http\RedirectPolicy;
use Heptapod\Contracts\RunResult;
use Heptapod\Jobs\RunCheck;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Incident;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Queries\IncidentsReader;
use Heptapod\Testing\FakeOutboundHttp;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\ClosedGuardedHttp;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05T12:00:00Z'));
});

/** @param array<string, mixed> $config */
function runUptimeAddon(array $config = [], string $url = 'https://probe.test/start', ?FetchResult $response = null): CheckRun
{
    if (! $response instanceof FetchResult) {
        // Native transport deadlines use the wall clock, including middleware before a mock terminal handler.
        test()->travelBack();
    }
    if ($response instanceof FetchResult) {
        $headers = [];
        foreach ($config['http_client_headers'] ?? [] as $header) {
            $headers[strtolower($header['name'])][] = $header['value'];
        }
        $body = $config['raw_payload'] ?? null;
        if ($body !== null) {
            $headers['content-type'] = ['application/json'];
        } elseif (($config['payload'] ?? []) !== []) {
            $body = implode('&', array_map(static fn (array $pair): string => urlencode($pair['name']).'='.urlencode($pair['value']), $config['payload']));
            $headers['content-type'] = ['application/x-www-form-urlencoded'];
        }
        app()->instance(OutboundHttp::class, (new FakeOutboundHttp)->respondWith(new FetchRequest($url, $config['http_verb'] ?? 'GET', $headers, $body, 10485760, 10485760, RedirectPolicy::Operator, $config['max_redirect_count'] ?? 5, timeoutSeconds: (float) ($config['timeout'] ?? 30), acceptedStatuses: range(100, 599), strictRedirects: false), $response));
    }
    $check = Check::factory()->for(Site::factory()->state(['url' => $url]))->create([
        'config' => app(CheckRegistry::class)->get(CheckKey::Uptime)->definition()->configSchema->validateConfig($config),
    ]);
    Bus::fake([RunCheck::class]);
    expect(app(ClaimService::class)->runNow($check->id, RunOrigin::System))->not->toBeNull();
    app(CheckExecutor::class)->execute($check->id, $check->refresh()->claim_token, 60);

    return CheckRun::query()->where('check_id', $check->id)->firstOrFail();
}

it('records response requirements through the real engine', function (array $config, string $body, int $status, array $headers, RunResult $result, array $issues): void {
    $run = runUptimeAddon($config, response: new FetchResult($status, $body, array_map(static fn (string $value): array => [$value], $headers)));
    expect($run->result)->toBe($result)
        ->and($run->evidence['issues'])->toBe($issues)
        ->and($run->metrics['total_time_in_seconds'])->toBeNull();
})->with([
    'wildcard status' => [['valid_status_codes' => ['2*', '304']], '', 204, [], RunResult::Succeeded, []],
    'explicit status' => [['valid_status_codes' => ['2*', '304']], '', 304, [], RunResult::Succeeded, []],
    'status mismatch' => [[], '', 503, [], RunResult::Failed, ['status']],
    'required keyword' => [['look_for_string' => 'ready'], 'not yet', 200, [], RunResult::Failed, ['required_string']],
    'absent keyword' => [['absent_string' => 'error'], 'an error', 200, [], RunResult::Failed, ['absent_string']],
    'case insensitive header' => [['expected_response_headers' => [['name' => 'x-ready', 'condition' => 'equals', 'value' => 'yes']]], '', 200, ['X-Ready' => 'yes'], RunResult::Succeeded, []],
    'missing negative header' => [['expected_response_headers' => [['name' => 'X-Ready', 'condition' => 'not equals', 'value' => 'no']]], '', 200, [], RunResult::Failed, ['header']],
    'header contains' => [['expected_response_headers' => [['name' => 'Content-Type', 'condition' => 'contains', 'value' => 'json']]], '', 200, ['Content-Type' => 'application/json'], RunResult::Succeeded, []],
    'header not contains' => [['expected_response_headers' => [['name' => 'X-Ready', 'condition' => 'not contains', 'value' => 'no']]], '', 200, ['X-Ready' => 'yes'], RunResult::Succeeded, []],
    'header not equals' => [['expected_response_headers' => [['name' => 'X-Ready', 'condition' => 'not equals', 'value' => 'no']]], '', 200, ['X-Ready' => 'yes'], RunResult::Succeeded, []],
    'header mismatch' => [['expected_response_headers' => [['name' => 'X-Ready', 'condition' => 'equals', 'value' => 'yes']]], '', 200, ['X-Ready' => 'no'], RunResult::Failed, ['header']],
]);

it('evaluates JSON assertions with wildcard and missing-value semantics through execution', function (string $path, string $operator, ?string $value, string $body, RunResult $result, bool $omitValue = false): void {
    $assertion = ['path' => $path, 'operator' => $operator];
    if (! $omitValue) {
        $assertion['value'] = $value;
    }
    $run = runUptimeAddon(['response_body_assertions' => [$assertion]], response: new FetchResult(200, $body));
    expect($run->result)->toBe($result)
        ->and(Check::query()->findOrFail($run->check_id)->executionConfig()['response_body_assertions'])->toEqual([$assertion]);
    if ($body === 'not JSON') {
        expect($run->summary)->toBe('uptime.body_not_json')->and($run->summary_params)->toBe([])
            ->and($run->evidence['issues'])->toBe(['body_not_json']);
    }
})->with([
    ['ready', 'equals', 'true', '{"ready":true}', RunResult::Succeeded],
    ['ready', 'not equals', 'false', '{"ready":true}', RunResult::Succeeded],
    ['count', 'greater than', '2', '{"count":3}', RunResult::Succeeded],
    ['count', 'less than', '2', '{"count":3}', RunResult::Failed],
    ['message', 'contains', 'ready', '{"message":"is ready"}', RunResult::Succeeded],
    ['optional', 'exists', null, '{"optional":null}', RunResult::Succeeded],
    ['missing', 'does not exist', null, '{}', RunResult::Succeeded],
    'exists without value' => ['optional', 'exists', null, '{"optional":null}', RunResult::Succeeded, true],
    'missing exists without value' => ['missing', 'exists', null, '{}', RunResult::Failed, true],
    'does not exist without value' => ['missing', 'does not exist', null, '{}', RunResult::Succeeded, true],
    'present does not exist without value' => ['optional', 'does not exist', null, '{"optional":null}', RunResult::Failed, true],
    ['missing', 'not equals', 'wrong', '{}', RunResult::Failed],
    ['items.*.id', 'greater than', '0', '{"items":[{"id":1},{"id":0}]}', RunResult::Failed],
    ['items.*.id', 'exists', null, '{"items":[{"id":1},{}]}', RunResult::Failed],
    ['items.*.id', 'exists', null, '{"items":[]}', RunResult::Failed],
    ['ready', 'equals', 'true', 'not JSON', RunResult::Failed],
]);

it('keeps verbatim raw JSON precedence and ordered duplicate headers secret in storage and evidence', function (): void {
    $run = runUptimeAddon([
        'http_verb' => 'POST', 'payload' => [['name' => 'wrong', 'value' => 'ignored']],
        'raw_payload' => '{"nested":{"count":3}}', 'look_for_string' => 'ready',
        'http_client_headers' => [['name' => 'Authorization', 'value' => 'secret-one'], ['name' => 'authorization', 'value' => 'secret-two']],
    ], response: new FetchResult(200, 'ready'));
    expect(app(OutboundHttp::class)->requests[0]['request']->body)->toBe('{"nested":{"count":3}}');
    expect($run->result)->toBe(RunResult::Succeeded)
        ->and($run->toJson())->not->toContain('secret-one', 'secret-two');
    $stored = DB::table('checks')->find($run->check_id);
    expect($stored->config)->not->toContain('secret-one', 'secret-two')
        ->and($stored->config_secrets)->not->toContain('secret-one', 'secret-two');
});

it('sends ordered repeated form pairs without losing values', function (): void {
    expect(runUptimeAddon(['http_verb' => 'PUT', 'payload' => [['name' => 'tag', 'value' => 'one'], ['name' => 'tag', 'value' => 'two words']]], response: new FetchResult(200))->result)->toBe(RunResult::Succeeded);
    expect(app(OutboundHttp::class)->requests[0]['request']->body)->toBe('tag=one&tag=two+words');
});

it('aggregates phase durations across redirects and preserves final-hop curl stats without slow uptime warnings', function (): void {
    $stats = ['namelookup_time' => 0.1, 'connect_time' => 0.3, 'appconnect_time' => 0.6, 'pretransfer_time' => 0.7, 'starttransfer_time' => 1.5, 'total_time' => 3.0, 'redirect_time' => 0.0, 'header_size' => 10, 'size_download' => 20.0, 'speed_download' => 30.0];
    $run = runUptimeAddon(['expected_final_redirect_url' => 'https://probe.test/final'], response: new FetchResult(200, transfers: [$stats, $stats], effectiveUrl: 'https://probe.test/final'));
    expect($run->result)->toBe(RunResult::Succeeded)
        ->and($run->metrics['dns_time_in_seconds'])->toBe(0.2)
        ->and($run->metrics['tcp_time_in_seconds'])->toEqualWithDelta(0.4, 0.00001)
        ->and($run->metrics['ssl_handshake_time_in_seconds'])->toEqualWithDelta(0.6, 0.00001)
        ->and($run->metrics['remote_server_processing_time_in_seconds'])->toBe(1.6)
        ->and($run->metrics['download_time_in_seconds'])->toBe(3)
        ->and($run->metrics['total_time_in_seconds'])->toBe(6)
        ->and($run->metrics['curl']['total_time'])->toBe(3)
        ->and($run->metrics['curl']['size_download'])->toBe(20);
});

it('keeps missing timings null and observed non-TLS handshake zero', function (): void {
    $metrics = runUptimeAddon([], 'http://probe.test/start', new FetchResult(200, transfers: [['connect_time' => 0.3, 'appconnect_time' => 0.0, 'total_time' => 1.0]]))->metrics;
    expect($metrics['dns_time_in_seconds'])->toBeNull()
        ->and($metrics['tcp_time_in_seconds'])->toBeNull()
        ->and($metrics['ssl_handshake_time_in_seconds'])->toBe(0)
        ->and($metrics['remote_server_processing_time_in_seconds'])->toBeNull()
        ->and($metrics['curl']['namelookup_time'])->toBeNull();
});

it('records connection failures as target failures without credential diagnostics', function (): void {
    $run = runUptimeAddon(response: new FetchResult(failure: new FetchFailure(FetchFailureKind::Connect)));
    expect($run->result)->toBe(RunResult::Failed)
        ->and($run->evidence['issues'])->toBe(['connection'])
        ->and($run->metrics['total_time_in_seconds'])->toBeNull()
        ->and($run->error)->toBeNull()
        ->and($run->toJson())->not->toContain('secret transport diagnostics');
});

it('records redirect limits and wrong final destinations through real transport', function (array $config, string $path, string $issue): void {
    $run = runUptimeAddon($config, 'http://fixtures.test/http-seam/'.$path);
    expect($run->result)->toBe(RunResult::Failed)
        ->and($run->evidence['issues'])->toBe([$issue]);
})->with([
    'redirects disabled' => [['max_redirect_count' => 0], 'same-origin', 'status'],
    'unexpected destination' => [['expected_final_redirect_url' => 'http://fixtures.test/wrong'], 'same-origin', 'redirect_url'],
    'redirect loop' => [['max_redirect_count' => 1], 'loop', 'redirect_limit'],
]);

it('rejects invalid imported settings at their exact schema field', function (array $config, string $field): void {
    $schema = app(CheckRegistry::class)->get(CheckKey::Uptime)->definition()->configSchema;
    try {
        $schema->validateConfig($config);
        $this->fail('Invalid uptime settings accepted.');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe([$field]);
    }
})->with([
    [['expected_response_headers' => [['name' => 'X-Test', 'condition' => 'unknown', 'value' => 'yes']]], 'values.expected_response_headers.0.condition'],
    [['response_body_assertions' => array_fill(0, 11, ['path' => 'id', 'operator' => 'exists', 'value' => null])], 'values.response_body_assertions'],
    [['response_body_assertions' => [['path' => 'id', 'operator' => 'unknown', 'value' => null]]], 'values.response_body_assertions.0.operator'],
    [['response_body_assertions' => [['path' => 'id', 'operator' => 'equals']]], 'values.response_body_assertions.0.value'],
    [['raw_payload' => 'invalid'], 'values.raw_payload'],
    [['http_client_headers' => [['name' => 'X-Test', 'value' => "bad\r\nInjected: yes"]]], 'values.http_client_headers.0.value'],
    [['valid_status_codes' => [200]], 'values.valid_status_codes.0'],
    [['timeout' => 0], 'values.timeout'],
    [['frequency_value' => 1], 'values'],
    [['ttl' => 3], 'values'],
]);

it('loads addon translations in both locales with matching keys', function (): void {
    $en = json_decode(file_get_contents(__DIR__.'/../../lang/en.json'), true, flags: JSON_THROW_ON_ERROR);
    $de = json_decode(file_get_contents(__DIR__.'/../../lang/de.json'), true, flags: JSON_THROW_ON_ERROR);
    expect(array_keys($en))->toBe(array_keys($de));
    foreach (['en' => $en, 'de' => $de] as $locale => $keys) {
        app()->setLocale($locale);
        foreach ($keys as $key => $value) {
            expect(__($key))->toBe($value);
        }
    }
});

it('bounds oversized and compressed bodies through engine execution', function (string $path, string $locale, string $text): void {
    app()->setLocale($locale);
    $run = runUptimeAddon([], 'http://fixtures.test/http-seam/'.$path.'/200');
    expect($run->result)->toBe(RunResult::Errored)->and($run->evidence['issues'])->toBe(['response_too_large'])
        ->and($run->summary)->toBe('uptime.response_too_large')->and($run->summary_params)->toBe(['limit' => '10 MiB'])
        ->and(__($run->summary, $run->summary_params))->toBe($text);
})->with(['oversized', 'gzip-oversized'])->with([
    ['en', 'Response exceeded the 10 MiB limit'],
    ['de', 'Antwort überschreitet das Limit von 10 MiB'],
]);

it('never sends caller secrets across origins or TLS downgrades', function (string $scheme): void {
    $this->travelBack();
    $config = ['http_client_headers' => [['name' => 'X-Private-Key', 'value' => 'SECRET-REDIRECT']], 'absent_string' => 'SECRET-REDIRECT'];
    $check = Check::factory()->for(Site::factory()->state(['url' => $scheme.'://fixtures.test/http-seam/cross-host']))->create([
        'config' => app(CheckRegistry::class)->get(CheckKey::Uptime)->definition()->configSchema->validateConfig($config),
    ]);
    Bus::fake([RunCheck::class]);
    expect(app(ClaimService::class)->runNow($check->id, RunOrigin::System))->not->toBeNull();
    $check->refresh();
    $requests = [];
    $transport = new MockHandler([
        new Response(302, ['Location' => 'http://external.fixtures.test/http-seam/headers']), new Response(200, [], 'ready'),
    ], static function () use (&$transport, &$requests): void {
        $requests[] = $transport->getLastRequest();
    });
    ClosedGuardedHttp::run(['fixtures.test' => [['8.8.8.8']], 'external.fixtures.test' => [['1.1.1.1']]], $transport, function () use ($check, $transport, &$requests): void {
        app(CheckExecutor::class)->execute($check->id, $check->claim_token, 60);
        $run = CheckRun::query()->where('check_id', $check->id)->sole();

        expect($run->result)->toBe(RunResult::Succeeded)
            ->and($run->summary)->toBe('uptime.succeeded')->and($run->evidence['issues'])->toBe([])
            ->and($run->toJson())->not->toContain('SECRET-REDIRECT');
        expect($requests)->toHaveCount(2);
        expect($requests[0]->getHeaderLine('X-Private-Key'))->toBe('SECRET-REDIRECT');
        expect($transport->getLastRequest()->hasHeader('X-Private-Key'))->toBeFalse()
            ->and(Message::toString($transport->getLastRequest()))->not->toContain('SECRET-REDIRECT')
            ->and($transport->count())->toBe(0);
    });
})->with(['http', 'https']);

it('records a private cross-host fixture redirect as a policy check error', function (): void {
    $run = runUptimeAddon([], 'http://fixtures.test/http-seam/cross-host');

    expect($run->result)->toBe(RunResult::Errored)
        ->and($run->summary)->toBe('checks.policy.redirect_private_network_address')
        // The fixture network allocates this address dynamically; resolve it the way the guard does.
        ->and($run->summary_params)->toBe(['address' => gethostbyname('external.fixtures.test')])
        ->and($run->evidence['issues'])->toBe(['private_target'])
        ->and($run->evidence['failure_kind'])->toBe('private_target')
        ->and($run->metrics)->toBeNull()
        ->and(json_decode($run->error, true, flags: JSON_THROW_ON_ERROR))->toBe([
            'class' => FetchException::class, 'message' => 'Outbound HTTP failed: private_target.',
        ]);
});

it('checks effective redirect URLs without persisting query secrets', function (): void {
    $run = runUptimeAddon(['expected_final_redirect_url' => 'http://fixtures.test/status/200?token=URL-SECRET'], 'http://fixtures.test/http-seam/final-query');
    expect($run->result)->toBe(RunResult::Succeeded)->and($run->toJson())->not->toContain('URL-SECRET');
});

it('preserves browser-style POST redirects while retaining bodies for 307 and 308', function (int $status, string $method, string $body): void {
    $run = runUptimeAddon([
        'http_verb' => 'POST', 'raw_payload' => '{"private":true}',
        'response_body_assertions' => [
            ['path' => 'method', 'operator' => 'equals', 'value' => $method],
            ['path' => 'body', 'operator' => 'equals', 'value' => $body],
        ],
    ], 'http://fixtures.test/http-seam/same-origin-body/'.$status);
    expect($run->result)->toBe(RunResult::Succeeded)->and($run->evidence['issues'])->toBe([]);
})->with([[301, 'GET', ''], [302, 'GET', ''], [307, 'POST', '{"private":true}'], [308, 'POST', '{"private":true}']]);

it('blocks cross-origin POST redirects before browser-style body rewriting', function (string $locale, string $text): void {
    app()->setLocale($locale);
    $run = runUptimeAddon(['http_verb' => 'POST', 'raw_payload' => '{"private":true}'], 'http://fixtures.test/http-seam/body-redirect/302');
    expect($run->result)->toBe(RunResult::Errored)->and($run->evidence['issues'])->toBe(['redirect_blocked'])
        ->and($run->summary)->toBe('checks.policy.redirect_blocked')->and($run->summary_params)->toBe([])
        ->and(__($run->summary, $run->summary_params))->toBe($text);
})->with([
    ['en', "Could not check: the redirect is not allowed by the outbound safety policy. Check the site's redirect configuration."],
    ['de', 'Prüfung nicht möglich: Die Weiterleitung ist durch die Sicherheitsrichtlinie nicht erlaubt. Prüfen Sie die Weiterleitungskonfiguration der Website.'],
]);

it('accepts case-insensitive HTTP schemes through real uptime execution', function (string $scheme): void {
    config(['certificate_health.ca_file' => base_path('docker/fixtures/tls/ca.crt')]);
    $run = runUptimeAddon([], $scheme.'://fixtures.test/status/200');
    expect($run->result)->toBe(RunResult::Succeeded)->and($run->error)->toBeNull();
})->with(['HTTP', 'HTTPS', 'hTtPs']);

it('keeps large healthy pages available but errors when JSON assertions cannot be evaluated above 2 MiB', function (): void {
    $run = runUptimeAddon([], 'http://fixtures.test/http-seam/healthy-large');
    expect($run->result)->toBe(RunResult::Succeeded);
    $body = '{"ready":false,"padding":"'.str_repeat('A', 3 * 1024 * 1024).'"}';
    $run = runUptimeAddon(['response_body_assertions' => [['path' => 'ready', 'operator' => 'equals', 'value' => 'true']]], response: new FetchResult(200, $body));
    expect($run->result)->toBe(RunResult::Errored)
        ->and($run->summary)->toBe('uptime.assertion_body_too_large')->and($run->summary_params)->toBe([])
        ->and($run->evidence['issues'])->toBe(['assertion_body_too_large'])
        ->and($run->toJson())->not->toContain('padding');
});

it('honors the instance response cap and reports its configured limit', function (string $locale, string $limit, string $text): void {
    app()->setLocale($locale);
    config(['heptapod.uptime.max_response_bytes' => 2621440]);
    $run = runUptimeAddon([], 'http://fixtures.test/http-seam/healthy-large');
    expect($run->result)->toBe(RunResult::Errored)->and($run->evidence['issues'])->toBe(['response_too_large'])
        ->and($run->summary)->toBe('uptime.response_too_large')->and($run->summary_params)->toBe(['limit' => $limit])
        ->and(__($run->summary, $run->summary_params))->toBe($text);
    config(['heptapod.uptime.max_response_bytes' => 20 * 1024 * 1024]);
    expect(runUptimeAddon([], 'http://fixtures.test/http-seam/oversized/200')->result)->toBe(RunResult::Succeeded);
})->with([
    ['en', '2.5 MiB', 'Response exceeded the 2.5 MiB limit'],
    ['de', '2,5 MiB', 'Antwort überschreitet das Limit von 2,5 MiB'],
]);

it('reports non-standard HTTP status codes as status failures rather than connection failures', function (int $status, string $locale, string $text): void {
    app()->setLocale($locale);
    $run = runUptimeAddon(response: new FetchResult($status, failure: new FetchFailure(FetchFailureKind::HttpStatus, $status)));
    expect($run->result)->toBe(RunResult::Failed)->and($run->evidence['issues'])->toBe(['status'])
        ->and($run->evidence['status_code'])->toBe($status)
        ->and($run->summary)->toBe('causes.http')->and($run->summary_params)->toBe(['status' => $status])
        ->and(__($run->summary, $run->summary_params))->toBe($text)
        ->and($run->error)->toBeNull();
})->with([
    [599, 'en', 'HTTP 599'],
    [599, 'de', 'HTTP 599'],
    [600, 'en', 'HTTP 600'],
    [600, 'de', 'HTTP 600'],
    [999, 'en', 'HTTP 999'],
    [999, 'de', 'HTTP 999'],
]);

it('records an out-of-range HTTP observation as an engine error without inventing status evidence', function (int $status): void {
    $run = runUptimeAddon(response: new FetchResult($status, failure: new FetchFailure(FetchFailureKind::HttpStatus, $status)));

    expect($run->result)->toBe(RunResult::Errored)
        ->and($run->summary)->toBe('causes.error')->and($run->summary_params)->toBe(['message' => 'HTTP summary requires a valid integer status.'])
        ->and($run->metrics)->toBeNull()
        ->and($run->evidence)->toBe(['verified_nothing' => false])
        ->and(json_decode($run->error, true, flags: JSON_THROW_ON_ERROR))->toBe([
            'class' => LogicException::class, 'message' => 'HTTP summary requires a valid integer status.',
        ]);
})->with([99, 1000]);

it('stores uptime payload, raw_payload and expected_final_redirect_url as secrets, masks them, preserves on edit and migrates legacy rows', function (): void {
    $owner = User::factory()->owner()->create();
    $member = User::factory()->create();
    $member->assignRole('Member');
    $writeToken = $owner->createToken('uptime-write', ['monitors:write', 'monitors:read'])->plainTextToken;
    $readToken = $owner->createToken('uptime-read', ['monitors:read'])->plainTextToken;

    $created = $this->withToken($writeToken)->postJson('/api/monitors', [
        'team_id' => 1,
        'type' => 'http',
        'url' => 'https://payload-secret.example.test',
        'uptime_check_settings' => [
            'http_verb' => 'POST',
            'payload' => [['name' => 'api_key', 'value' => 'form-secret-val']],
            'raw_payload' => '{"secret":"json-secret-val"}',
            'expected_final_redirect_url' => 'https://payload-secret.example.test/done?token=redirect-secret-val',
        ],
    ])->assertCreated();
    $created->assertDontSee('form-secret-val')
        ->assertDontSee('json-secret-val')
        ->assertDontSee('redirect-secret-val');

    $siteId = $created->json('id');
    $check = Check::query()->where('site_id', $siteId)->where('type', CheckKey::Uptime)->sole();
    $rawConfig = (string) DB::table('checks')->where('id', $check->id)->value('config');
    expect($rawConfig)->not->toContain('form-secret-val', 'json-secret-val', 'redirect-secret-val');
    expect($check->executionConfig())->toMatchArray([
        'payload' => [['name' => 'api_key', 'value' => 'form-secret-val']],
        'raw_payload' => '{"secret":"json-secret-val"}',
        'expected_final_redirect_url' => 'https://payload-secret.example.test/done?token=redirect-secret-val',
    ]);

    // Read-scoped token and Member UI see masked placeholders, never plaintext.
    $this->app['auth']->forgetGuards();
    $read = $this->withToken($readToken)->getJson('/api/monitors/'.$siteId)->assertOk();
    $read->assertDontSee('form-secret-val')->assertDontSee('json-secret-val')->assertDontSee('redirect-secret-val')
        ->assertJsonPath('uptime_check_settings.payload.0.value', '********')
        ->assertJsonPath('uptime_check_settings.raw_payload', '********')
        ->assertJsonPath('uptime_check_settings.expected_final_redirect_url', '********');

    $this->app['auth']->forgetGuards();
    $memberProps = $this->actingAs($member)->get('/sites/'.$siteId.'/checks/'.$check->id)
        ->assertOk()
        ->assertDontSee('form-secret-val')
        ->assertDontSee('json-secret-val')
        ->assertDontSee('redirect-secret-val')
        ->inertiaProps();
    expect($memberProps['check_settings']['settings']['payload'][0]['value'])->toBe('********')
        ->and($memberProps['check_settings']['settings']['raw_payload'])->toBe('********')
        ->and($memberProps['check_settings']['settings']['expected_final_redirect_url'])->toBe('********');

    // Keep-stored-value mask on edit (both '********' and omitted fields).
    $this->app['auth']->forgetGuards();
    $this->actingAs($owner)->patch('/sites/'.$siteId.'/checks/'.$check->id, [
        'timeout' => 15,
        'payload' => [['name' => 'api_key', 'value' => '********']],
        'raw_payload' => '********',
        'expected_final_redirect_url' => '********',
    ])->assertRedirect();
    expect($check->refresh()->executionConfig())->toMatchArray([
        'timeout' => 15,
        'payload' => [['name' => 'api_key', 'value' => 'form-secret-val']],
        'raw_payload' => '{"secret":"json-secret-val"}',
        'expected_final_redirect_url' => 'https://payload-secret.example.test/done?token=redirect-secret-val',
    ]);

    // Clearing nullable secrets leaves null (not '********') in masked projection.
    $this->actingAs($owner)->patch('/sites/'.$siteId.'/checks/'.$check->id, [
        'payload' => [],
        'raw_payload' => '',
        'expected_final_redirect_url' => '',
    ])->assertRedirect();
    $check->refresh();
    expect($check->executionConfig()['payload'])->toBe([])
        ->and($check->executionConfig()['raw_payload'] ?? null)->toBeNull()
        ->and($check->executionConfig()['expected_final_redirect_url'] ?? null)->toBeNull();
    $this->app['auth']->forgetGuards();
    $cleared = $this->withToken($readToken)->getJson('/api/monitors/'.$siteId)->assertOk();
    expect($cleared->json('uptime_check_settings.raw_payload'))->toBeNull()
        ->and($cleared->json('uptime_check_settings.expected_final_redirect_url'))->toBeNull();

    // Short non-credential request bodies still work through validation and real engine execution.
    expect(runUptimeAddon(['http_verb' => 'POST', 'raw_payload' => '{}'], response: new FetchResult(200))->result)->toBe(RunResult::Succeeded);

    // Migration moves legacy plaintext values into encrypted config_secrets idempotently and down() restores them without disturbing existing encrypted headers.
    $existingHeaders = Crypt::encryptString(json_encode(['http_client_headers' => [['value' => 'Bearer keep-header-secret']]], JSON_THROW_ON_ERROR));
    DB::table('checks')->where('id', $check->id)->update([
        'config' => json_encode([
            'http_verb' => 'POST',
            'http_client_headers' => [['name' => 'Authorization', 'value' => null]],
            'payload' => [
                ['name' => 'k1', 'value' => 'legacy-form-secret-1'],
                ['name' => 'k2', 'value' => 'legacy-form-secret-2'],
            ],
            'raw_payload' => '{"k":"legacy-raw-secret"}',
            'expected_final_redirect_url' => 'https://payload-secret.example.test/legacy?token=legacy-redir',
        ], JSON_THROW_ON_ERROR),
        'config_secrets' => $existingHeaders,
    ]);
    $migration = require database_path('migrations/2026_11_21_130000_move_uptime_request_secrets_to_config_secrets.php');
    $migration->up();
    $migration->up();

    $migratedRaw = (string) DB::table('checks')->where('id', $check->id)->value('config');
    expect($migratedRaw)->not->toContain('legacy-form-secret-1', 'legacy-form-secret-2', 'legacy-raw-secret', 'legacy-redir');
    expect($check->refresh()->executionConfig())->toMatchArray([
        'http_client_headers' => [['name' => 'Authorization', 'value' => 'Bearer keep-header-secret']],
        'payload' => [
            ['name' => 'k1', 'value' => 'legacy-form-secret-1'],
            ['name' => 'k2', 'value' => 'legacy-form-secret-2'],
        ],
        'raw_payload' => '{"k":"legacy-raw-secret"}',
        'expected_final_redirect_url' => 'https://payload-secret.example.test/legacy?token=legacy-redir',
    ]);

    $migration->down();
    $rolledBackConfig = json_decode((string) DB::table('checks')->where('id', $check->id)->value('config'), true, flags: JSON_THROW_ON_ERROR);
    $rolledBackSecrets = json_decode(Crypt::decryptString((string) DB::table('checks')->where('id', $check->id)->value('config_secrets')), true, flags: JSON_THROW_ON_ERROR);
    expect($rolledBackConfig)->toMatchArray([
        'payload' => [
            ['name' => 'k1', 'value' => 'legacy-form-secret-1'],
            ['name' => 'k2', 'value' => 'legacy-form-secret-2'],
        ],
        'raw_payload' => '{"k":"legacy-raw-secret"}',
        'expected_final_redirect_url' => 'https://payload-secret.example.test/legacy?token=legacy-redir',
    ])->and($rolledBackSecrets)->toBe(['http_client_headers' => [['value' => 'Bearer keep-header-secret']]]);
});

it('requires re-entry when removing a payload row shifts or makes a masked row ambiguous', function (): void {
    $owner = User::factory()->owner()->create();
    $schema = app(CheckRegistry::class)->get(CheckKey::Uptime)->definition()->configSchema;
    $check = Check::factory()->create([
        'type' => CheckKey::Uptime,
        'config' => $schema->validateConfig([
            'http_verb' => 'POST',
            'payload' => [
                ['name' => 'first', 'value' => 'secret-first'],
                ['name' => 'second', 'value' => 'secret-second'],
            ],
        ]),
    ]);
    $url = '/sites/'.$check->site_id.'/checks/'.$check->id;

    // Removing the first distinct-name row shifts position and requires re-entry, matching header lists.
    $this->actingAs($owner)->patchJson($url, [
        'payload' => [['name' => 'second', 'value' => '********']],
    ])->assertUnprocessable()->assertJsonValidationErrors('payload.0.value');
    expect($check->refresh()->executionConfig()['payload'])->toBe([
        ['name' => 'first', 'value' => 'secret-first'],
        ['name' => 'second', 'value' => 'secret-second'],
    ]);

    // Clearing the first row in place through the API drops it while preserving the second row at its existing position.
    $writeToken = $owner->createToken('payload-write', ['monitors:write'])->plainTextToken;
    $this->app['auth']->forgetGuards();
    $this->withToken($writeToken)->putJson('/api/monitors/'.$check->site_id, [
        'uptime_check_settings' => [
            'payload' => [
                ['name' => 'first', 'value' => ''],
                ['name' => 'second', 'value' => '********'],
            ],
        ],
    ])->assertOk();
    expect($check->refresh()->executionConfig()['payload'])->toBe([
        ['name' => 'second', 'value' => 'secret-second'],
    ]);
    $this->app['auth']->forgetGuards();

    // Store two duplicate-name rows.
    $this->actingAs($owner)->patchJson($url, [
        'payload' => [
            ['name' => 'tag', 'value' => 'secret-one'],
            ['name' => 'tag', 'value' => 'secret-two'],
        ],
    ])->assertRedirect();
    expect($check->refresh()->executionConfig()['payload'])->toBe([
        ['name' => 'tag', 'value' => 'secret-one'],
        ['name' => 'tag', 'value' => 'secret-two'],
    ]);

    // Editing another field with both duplicate-name rows masked preserves order.
    $this->actingAs($owner)->patchJson($url, [
        'timeout' => 20,
        'payload' => [
            ['name' => 'tag', 'value' => '********'],
            ['name' => 'tag', 'value' => '********'],
        ],
    ])->assertRedirect();
    expect($check->refresh()->executionConfig()['payload'])->toBe([
        ['name' => 'tag', 'value' => 'secret-one'],
        ['name' => 'tag', 'value' => 'secret-two'],
    ]);

    // Sol's same-count case: removing the first of two duplicate-name rows and adding a new same-name row in one edit must refuse the mask.
    $mixedDuplicate = $this->actingAs($owner)->patchJson($url, [
        'payload' => [
            ['name' => 'tag', 'value' => '********'],
            ['name' => 'tag', 'value' => 'secret-new'],
        ],
    ])->assertUnprocessable()->assertJsonValidationErrors('payload.0.value');
    expect($mixedDuplicate->json('errors')['payload.0.value'][0] ?? '')
        ->toBe(__('validation.secret_reentry_rows_changed', locale: 'en'))
        ->not->toContain('payload.0.value');

    // Removing one of two duplicate-name rows while leaving the surviving row masked must fail loudly with a clear message, not swap secrets.
    $removedDuplicate = $this->actingAs($owner)->patchJson($url, [
        'payload' => [['name' => 'tag', 'value' => '********']],
    ])->assertUnprocessable()->assertJsonValidationErrors('payload.0.value');
    expect($removedDuplicate->json('errors')['payload.0.value'][0] ?? '')
        ->toBe(__('validation.secret_reentry_rows_changed', locale: 'en'))
        ->not->toContain('payload.0.value');

    $owner->update(['locale' => 'de']);
    $deRefusal = $this->actingAs($owner)->patchJson($url, [
        'payload' => [['name' => 'tag', 'value' => '********']],
    ])->assertUnprocessable()->assertJsonValidationErrors('payload.0.value');
    expect($deRefusal->json('errors')['payload.0.value'][0] ?? '')
        ->toBe(__('validation.secret_reentry_rows_changed', locale: 'de'))
        ->not->toContain('payload.0.value');
    $owner->update(['locale' => 'en']);

    expect($check->refresh()->executionConfig()['payload'])->toBe([
        ['name' => 'tag', 'value' => 'secret-one'],
        ['name' => 'tag', 'value' => 'secret-two'],
    ]);

    // Re-entering the surviving value succeeds.
    $this->actingAs($owner)->patchJson($url, [
        'payload' => [['name' => 'tag', 'value' => 'secret-two']],
    ])->assertRedirect();
    expect($check->refresh()->executionConfig()['payload'])->toBe([
        ['name' => 'tag', 'value' => 'secret-two'],
    ]);
});

it('redacts uptime diagnostic URLs without rewriting protocol keys, issue codes or numeric facts', function (): void {
    $run = runUptimeAddon([
        'http_verb' => 'POST',
        'timeout' => 1234,
        'payload' => [
            ['name' => 'a', 'value' => 'issues'],
            ['name' => 'b', 'value' => 'status'],
            ['name' => 'c', 'value' => 'timeout'],
            ['name' => 'd', 'value' => '1234'],
            ['name' => 'e', 'value' => 'secret-echo-99'],
        ],
    ], response: new FetchResult(503, effectiveUrl: 'http://fixtures.test/fail?echo=secret-echo-99'));

    expect($run->result)->toBe(RunResult::Failed)
        ->and($run->evidence['status_code'])->toBe(503)
        ->and($run->evidence['issues'])->toBe(['status'])
        ->and($run->evidence['timeout_seconds'])->toBe(1234)
        ->and($run->evidence['effective_url'])->toBe('http://fixtures.test/fail?[redacted]');

    $history = app(CheckRegistry::class)->get(CheckKey::Uptime)->history($run->check_id);
    expect($history->runs[0]['reasons'])->toBe(['uptime.status']);

    $check = Check::query()->findOrFail($run->check_id);
    $incident = Incident::factory()->for($check)->create(['evidence' => $run->evidence]);
    $incidentEvidence = app(IncidentsReader::class)->evidence($incident);
    expect($incidentEvidence->facts['issues'])->toBe(['status'])
        ->and($incidentEvidence->facts['timeout_seconds'])->toBe(1234)
        ->and($incidentEvidence->facts['effective_url'])->toBe('http://fixtures.test/fail?[redacted]');
});
