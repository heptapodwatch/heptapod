<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Checks\Execution\ClaimService;
use Heptapod\Checks\Execution\RunOrigin;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\Http\FetchRequest;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\Http\RedirectPolicy;
use Heptapod\Jobs\RunCheck;
use Heptapod\Models\Check;
use Heptapod\Testing\FakeOutboundHttp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->beforeEach(function (): void {
    app()->instance(OutboundHttp::class, new FakeOutboundHttp);
})->in(__DIR__.'/Feature');

/** @param array<string, mixed>|string|FetchResult $payload */
function respondApplicationHealth(Check $check, array|string|FetchResult $payload): FakeOutboundHttp
{
    /** @var array{result_url: string, secret: string, headers?: list<array{name: string, value: string}>} $settings */
    $settings = $check->executionConfig();
    $headers = ['oh-dear-health-check-secret' => [$settings['secret']]];
    foreach ($settings['headers'] ?? [] as $header) {
        $headers[strtolower($header['name'])][] = $header['value'];
    }
    $headers['accept'] ??= ['application/json'];
    $http = app(OutboundHttp::class);
    assert($http instanceof FakeOutboundHttp);
    $http->respondWith(new FetchRequest($settings['result_url'], headers: $headers,
        maxWireBytes: config('application_health.max_response_bytes'), maxDecodedBytes: config('application_health.max_response_bytes'),
        redirects: RedirectPolicy::Off, timeoutSeconds: 30, acceptedStatuses: range(200, 399)),
        $payload instanceof FetchResult ? $payload : new FetchResult(200, is_array($payload) ? json_encode($payload, JSON_THROW_ON_ERROR) : $payload, transfers: [['total_time' => 0.125]]));

    return $http;
}

/** @param array<string, mixed> $config */
function applicationHealthCheck(array $config = []): Check
{
    $schema = app(CheckRegistry::class)->get(CheckKey::ApplicationHealth)->definition()->configSchema;

    return Check::factory()->create(['type' => CheckKey::ApplicationHealth, 'config' => $schema->validateConfig([
        'result_url' => 'http://fixtures.test/application-health/ok', 'secret' => 'fixture-secret', ...$config,
    ])]);
}

function runApplicationHealth(Check $check): void
{
    Queue::fake([RunCheck::class]);
    expect(app(ClaimService::class)->runNow($check->id, RunOrigin::System))->not->toBeNull();
    app()->call(new RunCheck($check->id, $check->refresh()->claim_token, 30, 'checks-fast')->handle(...));
}

/** @return array{finishedAt: int, checkResults: list<array<string, mixed>>} */
function applicationHealthPayload(string $status = 'ok', string $message = 'Disk usage at 70%'): array
{
    return ['finishedAt' => time(), 'checkResults' => [[
        'name' => 'DiskSpace', 'label' => 'Disk space', 'status' => $status,
        'notificationMessage' => $message, 'shortSummary' => '70%', 'meta' => ['disk_space_used_percentage' => 70],
    ]]];
}

function applicationHealthContext(Check $check, ?int $runId = null, ?string $token = null): CheckContext
{
    $at = CarbonImmutable::now();

    return new CheckContext($check->site_id, $check->id, $runId ?? (int) DB::scalar("SELECT nextval('check_runs_id_seq')"),
        $token ?? (string) Str::uuid(), $check->site->url, $at, $at->addSeconds(30), $check->executionConfig(), [], null);
}
