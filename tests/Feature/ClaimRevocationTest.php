<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Checks\Execution\ClaimService;
use Heptapod\Checks\Execution\RunOrigin;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\RunResult;
use Heptapod\Jobs\RunCheck;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Event;
use Heptapod\Models\User;
use Heptapod\Testing\ApiSpec;
use Heptapod\Testing\FakeOutboundHttp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    app()->singleton(ApiSpec::class);
    Queue::fake();
    app()->instance(OutboundHttp::class, new FakeOutboundHttp);
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $this->withToken($owner->createToken('configuration', ['*'])->plainTextToken);
});

it('discards a result from before a configuration edit and executes the replacement settings', function (array $attributes, string $url, string $header): void {
    $check = Check::factory()->create();
    $definition = app(CheckRegistry::class)->get(CheckKey::Uptime)->definition();
    $claims = app(ClaimService::class);
    expect($claims->runNow($check->id, RunOrigin::System))->not->toBeNull();
    $old = new RunCheck($check->id, $check->refresh()->claim_token, $definition->timeout, $definition->queue);
    $edited = false;
    $http = app(OutboundHttp::class);
    $http->respondUsing(function () use ($check, $attributes, &$edited): FetchResult {
        if ($edited) {
            return new FetchResult(200, 'new result');
        }
        expect($check->fresh()->active_run_id)->not->toBeNull();
        $this->putJson('/api/monitors/'.$check->site_id, $attributes)->assertOk();
        $edited = true;

        return new FetchResult(503, 'late result');
    });
    app()->call($old->handle(...));
    $old->failed(new RuntimeException('late worker failure'));
    expect(CheckRun::query()->where('check_id', $check->id)->count())->toBe(0)
        ->and(Event::query()->where('check_id', $check->id)->count())->toBe(0)
        ->and($check->refresh()->claim_token)->toBeNull()
        ->and($check->active_run_id)->toBeNull()
        ->and($check->next_run_at->isPast())->toBeTrue();

    expect($claims->runNow($check->id, RunOrigin::System))->not->toBeNull();
    $new = new RunCheck($check->id, $check->refresh()->claim_token, $definition->timeout, $definition->queue);
    app()->call($new->handle(...));
    expect($http->requests)->toHaveCount(2)
        ->and($http->requests[1]['request']->url)->toBe($url === '' ? $check->site->url : $url);
    if ($header !== '') {
        expect($http->requests[1]['request']->headers['x-config'])->toBe([$header]);
    }
    expect(CheckRun::query()->where('check_id', $check->id)->sole()->result)->toBe(RunResult::Succeeded);
})->with([
    'URL' => [['url' => 'https://replacement.example'], 'https://replacement.example', ''],
    'crawler headers' => [['crawler_headers' => [['name' => 'X-Config', 'value' => 'new-crawler']]], '', ''],
    'check config' => [['uptime_check_settings' => ['http_client_headers' => [['name' => 'X-Config', 'value' => 'new-check']]]], '', 'new-check'],
]);

it('preserves ownership for no-op configuration updates and display-only edits', function (): void {
    $schema = app(CheckRegistry::class)->get(CheckKey::Uptime)->definition()->configSchema;
    $check = Check::factory()->create(['config' => $schema->validateConfig(['http_client_headers' => [['name' => 'Authorization', 'value' => 'unchanged-secret']]])]);
    $check->site->update(['crawler_headers' => [['name' => 'X-Crawler', 'value' => 'unchanged-crawler']]]);
    expect(app(ClaimService::class)->runNow($check->id, RunOrigin::System))->not->toBeNull();
    $token = $check->refresh()->claim_token;
    $this->putJson('/api/monitors/'.$check->site_id, ['url' => $check->site->url, 'friendly_name' => 'Display only',
        'crawler_headers' => [['name' => 'X-Crawler', 'value' => '********']],
        'uptime_check_settings' => ['http_client_headers' => [['name' => 'Authorization', 'value' => '********']]],
    ])->assertOk();
    expect($check->refresh()->claim_token)->toBe($token);
});

it('discards staged addon data belonging to a revoked claim', function (): void {
    $check = Check::factory()->create(['type' => CheckKey::Dns]);
    expect(app(ClaimService::class)->runNow($check->id, RunOrigin::System))->not->toBeNull();
    $check->refresh();
    $at = CarbonImmutable::now();
    $runId = (int) DB::scalar("SELECT nextval('check_runs_id_seq')");
    $check->update(['active_run_id' => $runId, 'run_started_at' => $at]);
    $context = new CheckContext($check->site_id, $check->id, $runId, $check->claim_token, $check->site->url, $at, $at->addMinute(), $check->executionConfig(), [], null);
    app(CheckRegistry::class)->get(CheckKey::Dns)->persistence()->stage($context, 'snapshot', [[
        'dns_records' => [], 'raw_dns_records' => [], 'normalized_records' => [], 'issues' => [],
        'authoritative_nameservers' => [], 'baseline_generation' => 0, 'expected' => [],
    ]]);
    expect(DB::table('dns_observations')->where('run_id', $runId)->exists())->toBeTrue();
    $this->putJson('/api/monitors/'.$check->site_id, ['url' => 'https://updated.example'])->assertOk();
    expect(DB::table('dns_observations')->where('run_id', $runId)->exists())->toBeFalse();
});
