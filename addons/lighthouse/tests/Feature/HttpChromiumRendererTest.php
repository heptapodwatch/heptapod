<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\Http\FetchException;
use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\Http\FetchRequest;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\RunResult;
use Heptapod\Models\CheckRun;
use Heptapod\Testing\FakeOutboundHttp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../Pest.php';

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05T12:00:00Z'));
});

/** @param array<string, mixed>|string $body
 * @param  array<string, mixed>|null  $options
 */
function lighthouseHttp(array|string $body, ?FetchFailure $failure = null, ?array $options = null, ?FetchFailure $scopeFailure = null, ?FetchFailure $revokeFailure = null): FakeOutboundHttp
{
    config(['lighthouse.service_url' => 'http://lighthouse.test:3000', 'lighthouse.proxy_control_url' => 'http://proxy-control.test:3129']);
    $fake = new FakeOutboundHttp;
    $token = str_repeat('a', 64);
    $fake->respondWith(new FetchRequest('http://proxy-control.test:3129/scopes', method: 'POST',
        headers: ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
        body: json_encode(['siteHost' => 'example.com'], JSON_THROW_ON_ERROR),
        maxWireBytes: 4096, maxDecodedBytes: 4096, timeoutSeconds: 5),
        new FetchResult(status: 200, body: $scopeFailure instanceof FetchFailure ? '' : json_encode(['token' => $token], JSON_THROW_ON_ERROR), failure: $scopeFailure));
    $fake->respondWith(new FetchRequest('http://proxy-control.test:3129/scopes/'.$token, method: 'DELETE',
        maxWireBytes: 4096, maxDecodedBytes: 4096, timeoutSeconds: 5), new FetchResult(status: 204, failure: $revokeFailure));
    $responseBody = is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR);
    $fake->respondWith(new FetchRequest('http://lighthouse.test:3000/lighthouse', method: 'POST',
        headers: ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
        body: json_encode(['url' => 'https://example.com/', 'options' => $options ?? [
            'desktop' => false, 'cpuSlowdownMultiplier' => 4, 'extraHeaders' => (object) [],
        ], 'proxyToken' => $token], JSON_THROW_ON_ERROR), maxWireBytes: 8388608, maxDecodedBytes: 8388608, timeoutSeconds: 155),
        new FetchResult(status: $failure instanceof FetchFailure ? $failure->status : 200,
            body: $failure instanceof FetchFailure ? '' : $responseBody, failure: $failure));
    app()->instance(OutboundHttp::class, $fake);

    return $fake;
}

it('sends native browser options with the execution deadline through the bounded HTTP seam', function (): void {
    $fake = lighthouseHttp(lighthouseFixture('desktop'), options: [
        'desktop' => true, 'cpuSlowdownMultiplier' => 2.5, 'extraHeaders' => (object) ['Authorization' => 'private-token'],
    ]);
    $check = lighthouseCheck(['device_emulation' => 'desktop', 'cpu_slowdown_modifier' => 2.5,
        'http_client_headers' => [['name' => 'Authorization', 'value' => 'private-token']]]);
    $run = runLighthouse($check);

    expect($run->result)->toBe(RunResult::Succeeded)->and($fake->requests)->toHaveCount(3);
    $context = $fake->requests[1]['context'];
    expect($context->runId)->toBe($run->id)->and($context->deadline->diffInSeconds($context->startedAt, true))->toBe(170.0);
});

it('records browser transport and report failures as errors rather than score zero', function (string $problem): void {
    [$body, $failure] = match ($problem) {
        'unavailable' => ['', new FetchFailure(FetchFailureKind::HttpStatus, 503)],
        'connection' => ['', new FetchFailure(FetchFailureKind::Connect)],
        'bad json' => ['not JSON', null],
        'missing URL' => [['categories' => []], null],
    };
    $fake = lighthouseHttp($body, $failure);
    $run = runLighthouse(lighthouseCheck());

    expect($run->result)->toBe(RunResult::Errored)->and($run->metrics)->toBeNull()
        ->and($run->error)->not->toContain('private-service-details')->and($fake->requests)->toHaveCount(3);
    expect(CheckRun::query()->count())->toBe(1);
})->with(['unavailable', 'connection', 'bad json', 'missing URL']);

it('fails closed on proxy scope creation and revocation failures', function (bool $creation): void {
    $failure = new FetchFailure(FetchFailureKind::Connect);
    $fake = lighthouseHttp(lighthouseFixture('desktop'), scopeFailure: $creation ? $failure : null, revokeFailure: $creation ? null : $failure);
    $run = runLighthouse(lighthouseCheck());
    expect($run->result)->toBe(RunResult::Errored)->and($run->metrics)->toBeNull()
        ->and($fake->requests)->toHaveCount($creation ? 1 : 3);
})->with([true, false]);

it('rejects oversized decoded chunked responses before the response tail is downloaded', function (string $mode): void {
    // Native cURL compares its deadline to the wall clock.
    $this->travelBack();
    $input = new InputStream;
    $server = new Process(['node', __DIR__.'/../Support/response-server.mjs', $mode]);
    $server->setInput($input);
    $server->setTimeout(30);
    $server->start();
    try {
        expect($server->waitUntil(fn (string $type, string $output): bool => preg_match('/^\d+\n$/', $output) === 1))->toBeTrue();
        $port = (int) strtok($server->getOutput(), "\n");
        config(['lighthouse.service_url' => 'http://127.0.0.1:'.$port, 'lighthouse.proxy_control_url' => 'http://127.0.0.1:'.$port]);
        $run = runLighthouse(lighthouseCheck());

        expect($server->getOutput())->not->toContain('tail sent');
        expect($run->result)->toBe(RunResult::Errored)->and($run->metrics)->toBeNull();
        expect(json_decode($run->error, true, flags: JSON_THROW_ON_ERROR))->toBe([
            'class' => FetchException::class,
            'message' => 'Outbound HTTP failed: too_large (HTTP '.($mode === 'error' ? '503' : '200').').',
        ]);
        $input->write("tail\n");
        expect($server->waitUntil(fn (string $type, string $output): bool => str_contains($output, "tail sent\n")))->toBeTrue();
    } finally {
        $server->stop();
        expect($server->isRunning())->toBeFalse();
    }
})->with(['chunked', 'error', 'gzip']);

it('archives object and list identity while redacting numeric and mixed-case secrets without rewriting masks', function (): void {
    $original = json_decode(file_get_contents(__DIR__.'/../Fixtures/mobile.json'), flags: JSON_THROW_ON_ERROR);
    $original->configSettings->extraHeaders = (object) ['123456' => '123456', 'X-Key' => 'AbCdSeCrEt', 'X-Mask' => 'dact'];
    $original->archiveProbe = (object) [
        '123456' => '123456', 'ABCDSECRET' => 'aBcDsEcReT',
        'numeric' => 123456, 'children' => (object) [], 'list' => [], 'mask' => '[redacted]',
    ];
    lighthouseHttp(json_encode($original, JSON_THROW_ON_ERROR), options: [
        'desktop' => false, 'cpuSlowdownMultiplier' => 4, 'extraHeaders' => (object) ['123456' => '123456', 'X-Key' => 'AbCdSeCrEt', 'X-Mask' => 'dact'],
    ]);
    $check = lighthouseCheck(['http_client_headers' => [
        ['name' => '123456', 'value' => '123456'], ['name' => 'X-Key', 'value' => 'AbCdSeCrEt'], ['name' => 'X-Mask', 'value' => 'dact'],
    ]]);

    expect(runLighthouse($check)->result)->toBe(RunResult::Succeeded);
    $json = gzdecode(base64_decode(DB::table('lighthouse_reports')->value('compressed_report'), true));
    expect($json)->not->toContain('123456');
    expect(strtolower($json))->not->toContain('abcdsecret');
    $expected = clone $original;
    $expected->configSettings->extraHeaders = (object) ['[redacted]' => '[redacted]', 'X-Key' => '[redacted]', 'X-Mask' => '[redacted]'];
    $expected->archiveProbe = (object) [
        '[redacted]' => '[redacted]', 'numeric' => '[redacted]', 'children' => (object) [], 'list' => [], 'mask' => '[redacted]',
    ];
    expect(json_decode($json, flags: JSON_THROW_ON_ERROR))->toEqual($expected);
});

it('retries only internal busy responses with fresh scopes and the original shortened deadline', function (): void {
    Sleep::fake();
    $audits = 0;
    $scopes = 0;
    $contexts = [];
    $this->freezeTime();
    Sleep::whenFakingSleep(fn ($duration) => $this->travelTo(CarbonImmutable::now()->add($duration)));
    $fake = (new FakeOutboundHttp)->respondUsing(function (FetchRequest $request, CheckContext $context) use (&$audits, &$scopes, &$contexts): FetchResult {
        $contexts[] = $context;
        if (str_ends_with($request->url, '/scopes')) {
            $scopes++;

            return new FetchResult(200, json_encode(['token' => str_repeat($scopes === 1 ? 'a' : 'b', 64)], JSON_THROW_ON_ERROR));
        }
        if ($request->method === 'DELETE') {
            return new FetchResult(204);
        }
        $audits++;
        if ($audits === 1) {
            $this->travelTo($context->deadline->subSeconds(30));

            return new FetchResult(429, failure: new FetchFailure(FetchFailureKind::HttpStatus, 429));
        }
        expect($request->timeoutSeconds)->toBeGreaterThan(0)->toBeLessThanOrEqual(24.0);

        return new FetchResult(200, json_encode(lighthouseFixture(), JSON_THROW_ON_ERROR));
    });
    app()->instance(OutboundHttp::class, $fake);
    $run = runLighthouse(lighthouseCheck());

    expect($run->result)->toBe(RunResult::Succeeded)->and(CheckRun::query()->count())->toBe(1);
    expect(array_column(array_column($fake->requests, 'request'), 'method'))->toBe(['POST', 'POST', 'DELETE', 'POST', 'POST', 'DELETE']);
    expect($fake->requests[2]['request']->url)->toEndWith(str_repeat('a', 64));
    expect($fake->requests[5]['request']->url)->toEndWith(str_repeat('b', 64));
    expect(array_unique(array_map(fn (CheckContext $context): string => $context->deadline->format('U.u'), $contexts)))->toHaveCount(1);
    Sleep::assertSleptTimes(1);
});

it('records a locale-independent busy error and translated cause without resetting the original deadline', function (string $locale, string $message, int $remaining): void {
    app()->setLocale($locale);
    Sleep::fake();
    Sleep::whenFakingSleep(fn ($duration) => $this->travelTo(CarbonImmutable::now()->add($duration)));
    $audits = 0;
    $fake = (new FakeOutboundHttp)->respondUsing(function (FetchRequest $request, CheckContext $context) use (&$audits, $remaining): FetchResult {
        if (str_ends_with($request->url, '/scopes')) {
            return new FetchResult(200, json_encode(['token' => str_repeat('a', 64)], JSON_THROW_ON_ERROR));
        }
        if ($request->method === 'DELETE') {
            return new FetchResult(204);
        }
        if ($audits++ === 0) {
            $this->travelTo($context->deadline->subSeconds($remaining));
        }

        return new FetchResult(429, failure: new FetchFailure(FetchFailureKind::HttpStatus, 429));
    });
    app()->instance(OutboundHttp::class, $fake);
    $run = runLighthouse(lighthouseCheck());

    expect($run->result)->toBe(RunResult::Errored)->and($run->metrics)->toBeNull()
        ->and(json_decode($run->error, true, flags: JSON_THROW_ON_ERROR)['message'])->toBe('Lighthouse was busy; the audit did not run')
        ->and($run->summary)->toBe('lighthouse.busy')
        ->and(__($run->summary, $run->summary_params))->toBe($message);
    expect($audits)->toBeGreaterThan(0)->toBeLessThanOrEqual($remaining - 5);
    expect($fake->requests)->toHaveCount(3 * $audits)->and(CheckRun::query()->count())->toBe(1);
    foreach ($fake->requests as $call) {
        expect($call['request']->timeoutSeconds)->toBeGreaterThan(0);
    }
    expect(CarbonImmutable::now()->lessThanOrEqualTo($fake->requests[0]['context']->deadline))->toBeTrue();
})->with([
    ['en', 'Lighthouse was busy; the audit did not run', 6],
    ['de', 'Lighthouse war ausgelastet; das Audit wurde nicht ausgeführt', 8],
]);

it('does not retry a scope busy response or a non-busy audit failure after contention', function (string $problem): void {
    Sleep::fake();
    $audits = 0;
    $fake = (new FakeOutboundHttp)->respondUsing(function (FetchRequest $request) use ($problem, &$audits): FetchResult {
        if (str_ends_with($request->url, '/scopes')) {
            return $problem === 'scope busy'
                ? new FetchResult(429, failure: new FetchFailure(FetchFailureKind::HttpStatus, 429))
                : new FetchResult(200, json_encode(['token' => str_repeat('a', 64)], JSON_THROW_ON_ERROR));
        }
        if ($request->method === 'DELETE') {
            return new FetchResult(204);
        }
        $audits++;
        if ($audits === 1) {
            return new FetchResult(429, failure: new FetchFailure(FetchFailureKind::HttpStatus, 429));
        }

        return $problem === 'audit timeout'
            ? new FetchResult(failure: new FetchFailure(FetchFailureKind::Timeout))
            : new FetchResult(503, failure: new FetchFailure(FetchFailureKind::HttpStatus, 503));
    });
    app()->instance(OutboundHttp::class, $fake);
    $run = runLighthouse(lighthouseCheck());

    expect($run->result)->toBe(RunResult::Errored)->and($run->metrics)->toBeNull()
        ->and(json_decode($run->error, true, flags: JSON_THROW_ON_ERROR)['message'])->toBe(match ($problem) {
            'scope busy' => 'Lighthouse audit did not complete.',
            'audit timeout' => 'Lighthouse service could not be reached.',
            default => 'Lighthouse service returned HTTP 503.',
        });
    expect($audits)->toBe($problem === 'scope busy' ? 0 : 2)
        ->and($fake->requests)->toHaveCount($problem === 'scope busy' ? 1 : 6);
})->with(['scope busy', 'audit timeout', 'audit unavailable']);
