<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\ReclassifiedCheckError;
use Heptapod\Contracts\RunResult;
use Heptapod\Models\CheckRun;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event as LaravelEvent;

require_once __DIR__.'/../Pest.php';

it('records a real offline health response and curl timing through the HTTP seam and engine', function (): void {
    app()->forgetInstance(OutboundHttp::class);
    $base = getenv('APPLICATION_HEALTH_FIXTURE_URL');
    $check = applicationHealthCheck(['result_url' => ($base === false ? 'http://fixtures.test' : $base).'/application-health/ok']);

    runApplicationHealth($check);

    $run = CheckRun::query()->sole();
    expect($check->refresh()->latest_run_result)->toBe(CheckResult::Succeeded)
        ->and($run->evidence['reason'])->toBeNull()
        ->and($run->metrics['response_time_in_seconds'])->toBeFloat()->toBeGreaterThan(0);
});

it('maps bodyless truncated HTTP failures without leaking configured secrets', function (): void {
    $logs = [];
    LaravelEvent::listen(MessageLogged::class, function (MessageLogged $event) use (&$logs): void {
        $logs[] = [$event->message, $event->context];
    });
    $check = applicationHealthCheck(['headers' => [['name' => 'Authorization', 'value' => 'private-token']]]);
    respondApplicationHealth($check, new FetchResult(503, failure: new FetchFailure(FetchFailureKind::Connect, 503, 'ResponseTransferException')));

    runApplicationHealth($check);

    $run = CheckRun::query()->sole();
    expect(strtolower(json_encode(['error' => $run->error, 'evidence' => $run->evidence, 'logs' => $logs], JSON_THROW_ON_ERROR)))->not->toContain('private-token');
    expect($run->result)->toBe(RunResult::Errored)
        ->and(json_decode($run->error, true, flags: JSON_THROW_ON_ERROR))->toBe(['class' => ReclassifiedCheckError::class, 'message' => 'Application health result unavailable: unreachable'])
        ->and($run->evidence)->toMatchArray(['reason' => 'unreachable', 'http_status' => 503, 'components' => [], 'failure_kind' => 'connect']);
});

it('passes the remaining deadline to the seam and maps its timeout', function (): void {
    $check = applicationHealthCheck();
    $original = applicationHealthContext($check);
    $started = CarbonImmutable::now();
    $context = new CheckContext($original->siteId, $original->checkId, $original->runId, $original->claimToken,
        $original->url, $started, $started->addMilliseconds(150), $original->config, [], null);
    $http = respondApplicationHealth($check, new FetchResult(failure: new FetchFailure(FetchFailureKind::Timeout)));

    $outcome = app(CheckRegistry::class)->get(CheckKey::ApplicationHealth)->execute($context);

    expect($outcome->result)->toBe(RunResult::Errored)
        ->and($outcome->evidence->toArray())->toMatchArray(['reason' => 'unreachable', 'failure_kind' => 'timeout'])
        ->and($http->requests[0]['context'])->toBe($context);
});
