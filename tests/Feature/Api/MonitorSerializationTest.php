<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\Schema\Field;
use Heptapod\Contracts\Schema\FieldType;
use Heptapod\Contracts\Schema\Schema;
use Heptapod\Models\Check;
use Heptapod\Models\Incident;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Testing\ApiSpec;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-29T12:00:00Z'));
    app()->singleton(ApiSpec::class);
    $this->owner = User::factory()->create();
    $this->owner->assignRole('Owner');
    $this->withToken($this->owner->createToken('serialization', ['*'])->plainTextToken);
});

it('never returns plaintext secrets even to readers and preserves omitted masked and replaced writes', function (): void {
    $site = Site::factory()->create(['crawler_headers' => [['name' => 'Authorization', 'value' => 'crawler-private']]]);
    $schema = new Schema(['secret' => new Field(FieldType::Secret), 'headers' => new Field(FieldType::List, items: new Field(FieldType::Object, properties: ['name' => new Field(FieldType::String), 'value' => new Field(FieldType::Secret)]))]);
    Check::factory()->for($site)->create(['type' => CheckKey::ApplicationHealth, 'config' => $schema->validateConfig(['secret' => 'health-private', 'headers' => [['name' => 'Authorization', 'value' => 'health-header-private']]])]);
    $this->withToken($this->owner->createToken('write-only', ['monitors:write'])->plainTextToken);
    expect($this->putJson('/api/monitors/'.$site->id, ['uptime_check_settings' => ['http_client_headers' => [['name' => 'Authorization', 'value' => 'uptime-private']]]])->assertOk()->getContent())->not->toContain('crawler-private', 'health-private', 'health-header-private', 'uptime-private');
    $check = Check::query()->where('site_id', $site->id)->where('type', CheckKey::Uptime)->firstOrFail();
    $assertNoSecrets = function ($response): void {
        $response->assertOk();
        expect($response->getContent())->not->toContain('crawler-private', 'health-private', 'health-header-private', 'uptime-private');
    };
    $this->withToken($this->owner->createToken('reader-writer', ['*'])->plainTextToken);
    $assertNoSecrets($this->getJson('/api/monitors/'.$site->id));
    $assertNoSecrets($this->getJson('/api/monitors'));
    $assertNoSecrets($this->getJson('/api/monitors/url/'.urlencode($site->url)));
    $assertNoSecrets($this->postJson('/api/checks/'.$check->id.'/unsnooze'));
    foreach ([['monitors:write'], ['monitors:read', 'monitors:write']] as $abilities) {
        $this->withToken($this->owner->createToken('write', $abilities)->plainTextToken);
        $response = $this->putJson('/api/monitors/'.$site->id, []);
        $assertNoSecrets($response);
        $response->assertJsonPath('crawler_headers', null)->assertJsonPath('application_health_check_settings.secret', '********')->assertJsonPath('uptime_check_settings.http_client_headers.0.value', '********');
    }
    $this->withToken($this->owner->createToken('checks-only', ['checks:write'])->plainTextToken);
    $assertNoSecrets($this->postJson('/api/checks/'.$check->id.'/unsnooze'));
    $this->withToken($this->owner->createToken('writer', ['monitors:write'])->plainTextToken);
    $this->putJson('/api/monitors/'.$site->id, ['crawler_headers' => [['name' => 'Authorization', 'value' => '********']], 'uptime_check_settings' => ['http_client_headers' => [['name' => 'Authorization', 'value' => '********']]]])->assertOk();
    expect($check->refresh()->executionConfig()['http_client_headers'][0]['value'])->toBe('uptime-private');
    expect($site->refresh()->crawler_headers[0]['value'])->toBe('crawler-private');
    $this->putJson('/api/monitors/'.$site->id, ['crawler_headers' => [['name' => 'Authorization']], 'uptime_check_settings' => ['http_client_headers' => [['name' => 'Authorization']]]])->assertOk();
    expect($check->refresh()->executionConfig()['http_client_headers'][0]['value'])->toBe('uptime-private');
    expect($site->refresh()->crawler_headers[0]['value'])->toBe('crawler-private');
    $this->putJson('/api/monitors/'.$site->id, ['uptime_check_settings' => ['http_client_headers' => [['name' => 'X-New', 'value' => '********']]]])->assertUnprocessable()->assertJsonValidationErrors('http_client_headers.0.value');
    expect($check->refresh()->executionConfig()['http_client_headers'][0]['value'])->toBe('uptime-private');
    expect($this->putJson('/api/monitors/'.$site->id, ['crawler_headers' => [['name' => 'Authorization', 'value' => 'replacement-crawler']], 'uptime_check_settings' => ['http_client_headers' => [['name' => 'Authorization', 'value' => 'replacement-uptime']]]])->assertOk()->getContent())->not->toContain('replacement-crawler', 'replacement-uptime');
    expect($check->refresh()->executionConfig()['http_client_headers'][0]['value'])->toBe('replacement-uptime');
    expect($site->refresh()->crawler_headers[0]['value'])->toBe('replacement-crawler');
    $this->withToken($this->owner->createToken('reader', ['monitors:read'])->plainTextToken);
    expect($this->getJson('/api/monitors/'.$site->id)->assertOk()->getContent())->not->toContain('replacement-crawler', 'replacement-uptime');
    // Negative control: body-wide assertions would catch a secret at an unrelated nested path.
    expect(fn () => expect('{"nested":{"value":"uptime-private"}}')->not->toContain('uptime-private'))->toThrow('uptime-private');
});

it('round trips exact whitespace assertions and empty public values while clearing credential headers', function (string $assertion): void {
    $response = $this->postJson('/api/monitors', ['team_id' => 1, 'type' => 'http', 'url' => 'https://whitespace.test', 'uptime_check_settings' => ['look_for_string' => $assertion]])->assertCreated()->assertJsonPath('uptime_check_settings.look_for_string', $assertion);
    $this->putJson('/api/monitors/'.$response->json('id'), ['crawler_headers' => [['name' => 'X-Empty', 'value' => '']], 'uptime_check_settings' => ['http_client_headers' => [['name' => 'X-Empty', 'value' => '']], 'expected_response_headers' => [['name' => 'X-Empty', 'condition' => 'equals', 'value' => '']], 'payload' => [['name' => 'empty', 'value' => '']]]])->assertOk();
    $this->getJson('/api/monitors/'.$response->json('id'))->assertOk()->assertJsonPath('uptime_check_settings.look_for_string', $assertion)->assertJsonPath('uptime_check_settings.expected_response_headers.0.value', '')->assertJsonPath('uptime_check_settings.payload.0.value', '');
    $check = Check::query()->where('site_id', $response->json('id'))->where('type', CheckKey::Uptime)->firstOrFail();
    expect($check->executionConfig()['http_client_headers'])->toBe([]);
    expect(Site::query()->findOrFail($response->json('id'))->crawler_headers)->toBe([]);
})->with([' ', ' OK ', '']);

it('rejects oversized failure thresholds with field errors and unchanged state', function (): void {
    $check = Check::factory()->create(['failure_threshold' => 2]);
    foreach ([1001, 2147483648] as $threshold) {
        $this->putJson('/api/monitors/'.$check->site_id, ['uptime_check_settings' => ['failed_notification_threshold' => $threshold]])->assertUnprocessable()->assertJsonValidationErrors('failed_notification_threshold');
        expect($check->refresh()->failure_threshold)->toBe(2);
        expect($check->site->refresh()->alert_after_minutes)->toBeNull();
    }
    $this->putJson('/api/monitors/'.$check->site_id, ['uptime_check_settings' => ['failed_notification_threshold' => 1000]])->assertOk();
    // 2a4246189: uptime API thresholds are alert minutes on the site, not failed-check counts.
    expect($check->site->refresh()->alert_after_minutes)->toBe(1000);
    expect($check->refresh()->failure_threshold)->toBe(2);
});

it('serializes monitors with constant queries and preserves performance availability during an outage', function (int $count): void {
    $addSites = static function (int $siteCount): Collection {
        $sites = Site::factory()->count($siteCount)->create();
        foreach ($sites as $site) {
            $uptime = Check::factory()->for($site)->create();
            Incident::factory()->for($uptime)->create(['first_failure_at' => now()->subHour()]);
            Check::factory()->for($site)->create(['type' => CheckKey::Performance]);
        }

        return $sites;
    };
    $sites = $addSites(2);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->getJson('/api/monitors?per_page='.$count)->assertOk()->assertJsonCount(2, 'data');
    $fewQueries = count(DB::getQueryLog());
    DB::disableQueryLog();
    $addSites($count - 2);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $response = $this->getJson('/api/monitors?per_page='.$count)->assertOk()->assertJsonCount($count, 'data');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect(count($queries))->toBeLessThanOrEqual($fewQueries, 'monitor serialization grew from '.$fewQueries.' to '.count($queries).' queries as monitors grew from 2 to '.$count);
    // Upper budget: token auth, the monitor page and its batched checks, incidents and freshness; ded7eb579's
    // three performance-availability batch lookups replace per-monitor queries.
    expect($fewQueries)->toBeLessThanOrEqual(16, 'monitor serialization used '.$fewQueries.' queries, over its budget of 16');
    expect(collect($queries)->filter(fn (array $query): bool => str_contains($query['query'], 'FROM check_runs'))->count())->toBe(1);
    $performance = collect($response->json('data.0.checks'))->firstWhere('type', 'performance');
    expect($performance['average_response_time_in_ms'])->toBeNull();
    expect($performance['performance_unavailable'])->toBe([
        'key' => 'performance.no_data_while_down', 'params' => ['date' => '2026-10-29T11:00:00+00:00'],
    ]);
    // The unbatched single-monitor boundary must return exactly the same fields and values.
    $listed = $response->json('data.0');
    $single = $this->getJson('/api/monitors/'.$sites[0]->id)->assertOk()->json();
    $listed['checks'] = collect($listed['checks'])->sortBy('id')->values()->all();
    $single['checks'] = collect($single['checks'])->sortBy('id')->values()->all();
    expect($listed)->toEqual($single);
})->with([20, 200]);

it('lists tags with bounded queries without hydrating site models', function (): void {
    $sites = Site::factory()->count(20)->create();
    foreach ($sites as $index => $site) {
        $site->attachTag('tag-'.$index, 'sites');
    }
    $hydrated = 0;
    Site::retrieved(function () use (&$hydrated): void {
        $hydrated++;
    });
    DB::enableQueryLog();
    $response = $this->getJson('/api/tags')->assertOk()->assertJsonCount(20, 'data');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect(count($queries))->toBeLessThanOrEqual(10);
    expect($hydrated)->toBe(0);
    expect(collect($response->json('data'))->firstWhere('name', 'tag-0')['sites'])->toBe([$sites[0]->id]);
});
