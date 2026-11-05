<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\ReclassifiedCheckError;
use Heptapod\Contracts\RunResult;
use Heptapod\Contracts\SuppressionComponent;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\CheckSnooze;
use Heptapod\Models\Event;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as LaravelEvent;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../Pest.php';

it('fetches decrypted secrets through the engine and records reported component data', function (): void {
    $check = applicationHealthCheck(['headers' => [['name' => 'X-Token', 'value' => 'private-token'], ['name' => 'X-Token', 'value' => 'second-token']]]);
    $http = respondApplicationHealth($check, applicationHealthPayload());

    runApplicationHealth($check);

    expect($check->refresh()->latest_run_result)->toBe(CheckResult::Succeeded)
        ->and(CheckRun::query()->sole()->result)->toBe(RunResult::Succeeded);
    $component = DB::table('application_health_components')->sole();
    $snapshot = json_decode($component->snapshot, true, flags: JSON_THROW_ON_ERROR);
    expect($snapshot)->toMatchArray(['name' => 'DiskSpace', 'status' => 'ok', 'notificationMessage' => 'Disk usage at 70%', 'shortSummary' => '70%', 'meta' => ['disk_space_used_percentage' => 70]])
        ->and($component->active)->toBeTrue();
    expect(DB::table('checks')->where('id', $check->id)->value('config'))->not->toContain('fixture-secret', 'private-token', 'second-token');
    expect($http->requests)->toHaveCount(1)
        ->and($http->requests[0]['request']->headers)->toBe(['oh-dear-health-check-secret' => ['fixture-secret'], 'x-token' => ['private-token', 'second-token'], 'accept' => ['application/json']])
        ->and(CheckRun::query()->sole()->metrics['response_time_in_seconds'])->toBe(0.125);
});

it('preserves raw statuses while aggregating component severity', function (string $status, CheckResult $result): void {
    $check = applicationHealthCheck();
    respondApplicationHealth($check, applicationHealthPayload($status));

    runApplicationHealth($check);

    expect($check->refresh()->latest_run_result)->toBe($result);
    $snapshot = json_decode(DB::table('application_health_components')->value('snapshot'), true, flags: JSON_THROW_ON_ERROR);
    expect($snapshot['status'])->toBe($status);
})->with([
    'healthy' => ['ok', CheckResult::Succeeded],
    'warning' => ['warning', CheckResult::Warning],
    'failed' => ['failed', CheckResult::Failed],
    'crashed' => ['crashed', CheckResult::Failed],
    'skipped' => ['skipped', CheckResult::Pending],
]);

it('takes the worst component result and succeeds when no checks are reported', function (array $statuses, CheckResult $result): void {
    $payload = applicationHealthPayload();
    $payload['checkResults'] = array_map(static fn (string $status, int $index): array => [
        'name' => 'Component'.$index, 'status' => $status,
    ], $statuses, array_keys($statuses));
    $check = applicationHealthCheck();
    respondApplicationHealth($check, $payload);

    runApplicationHealth($check);

    expect($check->refresh()->latest_run_result)->toBe($result)
        ->and(DB::table('application_health_components')->where('active', true)->count())->toBe(count($statuses));
})->with([
    'empty' => [[], CheckResult::Succeeded],
    'mixed warning' => [['ok', 'warning', 'skipped'], CheckResult::Warning],
    'mixed failure' => [['ok', 'warning', 'failed'], CheckResult::Failed],
]);

it('records endpoint failures without replacing accepted component baselines', function (string $variant, string $reason): void {
    $payload = applicationHealthPayload();
    $response = match ($variant) {
        'invalid_json' => '{invalid',
        'root_list' => '[]',
        'missing' => ['finishedAt' => time()],
        'wrong_timestamp' => ['finishedAt' => (string) time(), 'checkResults' => []],
        'invalid_component' => ['finishedAt' => time(), 'checkResults' => [['name' => 'DiskSpace', 'status' => 42]]],
        'unknown_status' => ['finishedAt' => time(), 'checkResults' => [['name' => 'DiskSpace', 'status' => 'custom-status']]],
        'duplicate' => ['finishedAt' => time(), 'checkResults' => [$payload['checkResults'][0], $payload['checkResults'][0]]],
        'stale' => ['finishedAt' => time() - 301, 'checkResults' => []],
        'future' => ['finishedAt' => time() + 3600, 'checkResults' => []],
        'oversized' => new FetchResult(200, failure: new FetchFailure(FetchFailureKind::TooLarge, 200)),
        'too_many' => ['finishedAt' => time(), 'checkResults' => array_map(static fn (int $id): array => ['name' => 'Component'.$id, 'status' => 'ok'], range(1, 1001))],
        'http_status' => new FetchResult(503, failure: new FetchFailure(FetchFailureKind::HttpStatus, 503)),
        'unreachable' => new FetchResult(failure: new FetchFailure(FetchFailureKind::Connect)),
        'timeout', 'tls', 'dns', 'redirect_blocked', 'too_many_redirects', 'private_target', 'invalid_request' => new FetchResult(failure: new FetchFailure(FetchFailureKind::from($variant))),
        'redirect' => new FetchResult(302, headers: ['lOcAtIoN' => ['http://external.fixtures.test/private?token=fixture-secret']]),
        'redirect_secret' => new FetchResult(308, headers: ['Location' => ['http://PRIVATE-TOKEN.test/private?token=fixture-secret']]),
        'redirect_relative' => new FetchResult(304, headers: ['Location' => ['/private?token=fixture-secret']]),
        default => throw new InvalidArgumentException('Unknown failure fixture.'),
    };
    $check = applicationHealthCheck(['headers' => [['name' => 'Authorization', 'value' => 'private-token']]]);
    $http = respondApplicationHealth($check, $payload);
    runApplicationHealth($check);
    $baseline = DB::table('application_health_components')->sole();
    respondApplicationHealth($check, $response);

    runApplicationHealth($check);

    $run = CheckRun::query()->orderByDesc('id')->firstOrFail();
    expect($check->refresh()->latest_run_result)->toBe(CheckResult::Errored)
        ->and($run->result)->toBe(RunResult::Errored)
        ->and(json_decode($run->error, true, flags: JSON_THROW_ON_ERROR))->toBe($reason === 'unknown_status'
            ? ['class' => UnexpectedValueException::class, 'message' => 'Application health reported an unknown component status.']
            : ['class' => ReclassifiedCheckError::class, 'message' => 'Application health result unavailable: '.$reason])
        ->and($run->evidence['components'])->toBe([])
        ->and($run->evidence['reason'])->toBe($reason)
        ->and(DB::table('application_health_components')->sole()->run_id)->toBe($baseline->run_id)
        ->and(DB::table('application_health_snapshots')->count())->toBe(1);
    if (str_starts_with($variant, 'redirect') && $variant !== 'redirect_blocked') {
        expect($run->evidence['redirect_host'])->toBe(match ($variant) {
            'redirect_secret' => '[redacted]', 'redirect_relative' => 'fixtures.test', default => 'external.fixtures.test',
        });
        foreach (['fixture-secret', 'private-token', '/private'] as $secret) {
            expect(strtolower(json_encode($run->evidence)))->not->toContain($secret);
        }
    }
    if ($response instanceof FetchResult) {
        expect($run->evidence['failure_kind'])->toBe($response->failure?->kind->value)
            ->and($run->evidence['http_status'])->toBe($response->status);
    }
    expect($http->requests)->toHaveCount(2);
})->with([
    ['invalid_json', 'invalid_json'], ['root_list', 'invalid_payload'], ['missing', 'invalid_payload'],
    ['wrong_timestamp', 'invalid_payload'], ['invalid_component', 'invalid_payload'], ['duplicate', 'invalid_payload'],
    ['stale', 'stale'], ['future', 'future_finished_at'], ['oversized', 'response_too_large'],
    ['too_many', 'invalid_payload'], ['http_status', 'http_status'], ['unreachable', 'unreachable'],
    ['timeout', 'unreachable'], ['tls', 'unreachable'], ['dns', 'unreachable'], ['redirect_blocked', 'unreachable'],
    ['too_many_redirects', 'unreachable'], ['private_target', 'unreachable'], ['invalid_request', 'unreachable'],
    ['redirect', 'redirect'], ['redirect_secret', 'redirect'], ['redirect_relative', 'redirect'], ['unknown_status', 'unknown_status'],
]);

it('applies inclusive timestamp boundaries without depending on probe latency', function (int $offset, RunResult $result): void {
    $check = applicationHealthCheck();
    $context = applicationHealthContext($check);
    respondApplicationHealth($check, ['finishedAt' => $context->startedAt->getTimestamp() + $offset, 'checkResults' => [['name' => 'DiskSpace', 'status' => 'ok']]]);

    $outcome = app(CheckRegistry::class)->get(CheckKey::ApplicationHealth)->execute($context);

    expect($outcome->result)->toBe($result);
})->with([
    'stale boundary included' => [-300, RunResult::Succeeded],
    'stale boundary exceeded' => [-301, RunResult::Errored],
    'clock skew included' => [60, RunResult::Succeeded],
    'clock skew exceeded' => [61, RunResult::Errored],
]);

it('emits accepted status or message changes once with component suppression and secret-free notification text', function (): void {
    $check = applicationHealthCheck(['secret' => '123456', 'headers' => [
        ['name' => 'Authorization', 'value' => 'private-token'],
        ['name' => 'X-Other-Secret', 'value' => 'fixture-secret'],
    ]]);
    $logs = [];
    LaravelEvent::listen(MessageLogged::class, function (MessageLogged $event) use (&$logs): void {
        $logs[] = [$event->message, $event->context];
    });
    $a = applicationHealthPayload('warning', 'Disk usage at 90%');
    $b = applicationHealthPayload('warning', 'Disk usage at 91%');
    $c = applicationHealthPayload('warning', 'PRIVATE-TOKEN leaked');
    $c['checkResults'][0]['shortSummary'] = 'Fixture-Secret';
    $c['checkResults'][0]['meta'] = ['private-token' => ['nested' => 'fixture-secret'], 'value' => 'PRIVATE-TOKEN', '123456' => 'value'];
    $d = $c;
    $d['checkResults'][0]['label'] = 'New label';
    $d['checkResults'][0]['meta'] = ['safe' => 42];
    $e = $d;
    $e['checkResults'][0]['status'] = 'failed';
    respondApplicationHealth($check, $a);
    runApplicationHealth($check);
    expect(Event::query()->where('type', 'application_health.component_changed')->count())->toBe(0);
    $componentId = DB::table('application_health_components')->value('id');
    CheckSnooze::factory()->create(['check_id' => $check->id, 'component_type' => SuppressionComponent::ApplicationHealth,
        'component_id' => $componentId, 'starts_at' => now()->subMinute(), 'ends_at' => now()->addHour()]);

    respondApplicationHealth($check, $b);
    runApplicationHealth($check);
    runApplicationHealth($check);

    $event = Event::query()->where('type', 'application_health.component_changed')->sole();
    expect($event->from_result)->toBe(CheckResult::Warning)->and($event->to_result)->toBe(CheckResult::Warning)
        ->and($event->payload)->toMatchArray(['component_id' => $componentId, 'name' => 'DiskSpace', 'old_status' => 'warning', 'status' => 'warning', 'notificationMessage' => 'Disk usage at 91%', 'shortSummary' => '70%'])
        ->and($event->suppressed)->toBeTrue();
    respondApplicationHealth($check, $c);
    runApplicationHealth($check);
    $secretEvent = Event::query()->where('type', 'application_health.component_changed')->orderByDesc('id')->firstOrFail();
    expect($secretEvent->payload)->not->toHaveKeys(['notificationMessage', 'shortSummary', 'meta']);
    respondApplicationHealth($check, $d);
    runApplicationHealth($check);
    expect(Event::query()->where('type', 'application_health.component_changed')->count())->toBe(2);
    respondApplicationHealth($check, $e);
    runApplicationHealth($check);
    respondApplicationHealth($check, $a);
    runApplicationHealth($check);
    expect(Event::query()->where('type', 'application_health.component_changed')->count())->toBe(4)
        ->and($event->component_type)->toBe(SuppressionComponent::ApplicationHealth)
        ->and($event->component_id)->toBe($componentId);
    foreach (['application_health_components', 'application_health_snapshots', 'events', 'check_runs'] as $table) {
        $stored = strtolower(json_encode(DB::table($table)->get(), JSON_THROW_ON_ERROR));
        foreach (['fixture-secret', 'private-token', '123456'] as $secret) {
            expect($stored)->not->toContain($secret);
        }
    }
    foreach (['fixture-secret', 'private-token', '123456'] as $secret) {
        expect(strtolower(json_encode($logs, JSON_THROW_ON_ERROR)))->not->toContain($secret);
    }
});

it('keeps vanished component identities and change baselines after raw retention', function (): void {
    $check = applicationHealthCheck();
    respondApplicationHealth($check, applicationHealthPayload());
    runApplicationHealth($check);
    $componentId = DB::table('application_health_components')->value('id');
    respondApplicationHealth($check, ['finishedAt' => time(), 'checkResults' => []]);
    runApplicationHealth($check);
    expect(DB::table('application_health_components')->value('active'))->toBeFalse();
    app(CheckRegistry::class)->get(CheckKey::ApplicationHealth)->persistence()->prune(CarbonImmutable::now()->addDay());
    expect(DB::table('application_health_snapshots')->count())->toBe(0);
    respondApplicationHealth($check, applicationHealthPayload('ok', 'Disk usage at 71%'));

    runApplicationHealth($check);

    expect(DB::table('application_health_components')->sole()->id)->toBe($componentId)
        ->and(DB::table('application_health_components')->value('active'))->toBeTrue()
        ->and(Event::query()->where('type', 'application_health.component_changed')->count())->toBe(1);
});

it('emits summary changes at unchanged healthy severity but not skipped or first-seen components', function (): void {
    $a = applicationHealthPayload();
    $b = $a;
    $b['checkResults'][0]['shortSummary'] = '71%';
    $b['checkResults'][] = ['name' => 'NewComponent', 'status' => 'ok'];
    $c = $b;
    $c['checkResults'][0]['status'] = 'skipped';
    $check = applicationHealthCheck();
    foreach ([$a, $b, $b, $c] as $payload) {
        respondApplicationHealth($check, $payload);
        runApplicationHealth($check);
    }

    $events = Event::query()->where('type', 'application_health.component_changed')->orderBy('id')->get();
    expect($events)->toHaveCount(1)
        ->and($events[0]->from_result)->toBe(CheckResult::Succeeded)
        ->and($events[0]->to_result)->toBe(CheckResult::Succeeded)
        ->and($events[0]->payload)->toMatchArray(['old_status' => 'ok', 'status' => 'ok', 'shortSummary' => '71%'])
        ->and(DB::table('application_health_components')->where('active', true)->count())->toBe(2);
});

it('bounds notification text without losing change identity beyond the display limit', function (): void {
    $a = applicationHealthPayload('warning', str_repeat('a', 255).'first');
    $b = applicationHealthPayload('warning', str_repeat('a', 255).'second');
    $check = applicationHealthCheck();
    foreach ([$a, $b, $b] as $payload) {
        respondApplicationHealth($check, $payload);
        runApplicationHealth($check);
    }

    expect(Event::query()->where('type', 'application_health.component_changed')->sole()->payload['notificationMessage'])->toBe(str_repeat('a', 255));
});

it('fails loudly for invalid operator limits and honors configured freshness and component caps', function (): void {
    $check = applicationHealthCheck();
    $context = applicationHealthContext($check);
    $type = app(CheckRegistry::class)->get(CheckKey::ApplicationHealth);
    config(['application_health.max_components' => 1001]);
    expect(fn (): ?\Heptapod\Contracts\CheckOutcome => $type->execute($context))->toThrow(InvalidArgumentException::class, 'max_components');
    config(['application_health.max_components' => 1, 'application_health.stale_after_seconds' => 5, 'application_health.max_response_bytes' => 256]);
    respondApplicationHealth($check, ['finishedAt' => $context->startedAt->getTimestamp() - 6, 'checkResults' => []]);
    expect($type->execute($context)->summary)->toBe('application_health.stale');
    respondApplicationHealth($check, ['finishedAt' => time(), 'checkResults' => [['name' => 'One', 'status' => 'ok'], ['name' => 'Two', 'status' => 'ok']]]);
    expect($type->execute($context)->summary)->toBe('application_health.invalid_payload');
});

it('rejects invalid settings before they can be persisted', function (array $settings): void {
    expect(fn (): Check => applicationHealthCheck($settings))->toThrow(ValidationException::class);
    expect(Check::query()->count())->toBe(0);
})->with([
    'unsupported URL scheme' => [['result_url' => 'ftp://fixtures.test']],
    'header injection' => [['secret' => "secret\r\nX-Other: value"]],
    'reserved secret header' => [['headers' => [['name' => 'Oh-Dear-Health-Check-Secret', 'value' => 'override']]]],
    'header name injection' => [['headers' => [['name' => "X-Test\r\n", 'value' => 'value']]]],
    'header value injection' => [['headers' => [['name' => 'X-Test', 'value' => "value\r\n"]]]],
]);

it('accepts real-shaped keyed and empty health collections with no incident or notification', function (string $fixture, int $components, string $summary): void {
    $payload = json_decode(file_get_contents(__DIR__.'/../Fixtures/'.$fixture.'.json'), true, flags: JSON_THROW_ON_ERROR);
    $payload['finishedAt'] = time();
    $check = applicationHealthCheck();
    respondApplicationHealth($check, $payload);

    runApplicationHealth($check);

    $run = CheckRun::query()->sole();
    expect($run->result)->toBe(RunResult::Succeeded);
    expect($run->summary)->toBe($summary);
    expect($run->evidence['components'])->toHaveCount($components);
    expect(DB::table('application_health_components')->where('active', true)->count())->toBe($components);
    expect(DB::table('incidents')->count())->toBe(0);
    expect(Event::query()->whereIn('to_result', [CheckResult::Warning, CheckResult::Failed])->count())->toBe(0);
    expect(Event::query()->where('type', 'application_health.component_changed')->count())->toBe(0);
    expect(DB::table('deliveries')->count())->toBe(0);
    if ($components === 0) {
        foreach (['en', 'de'] as $locale) {
            expect(__($summary, locale: $locale))->not->toBe($summary);
        }
    }
})->with([
    'numeric-key object' => ['numeric-keys', 6, 'application_health.summary'],
    'empty list' => ['empty', 0, 'application_health.no_checks'],
]);
