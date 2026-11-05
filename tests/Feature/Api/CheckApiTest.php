<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Actions\UpdateCheckConfig;
use Heptapod\Checks\Execution\CheckExecutor;
use Heptapod\Checks\History\RollupReader;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\Http\FetchRequest;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\Http\RedirectPolicy;
use Heptapod\Contracts\RunResult;
use Heptapod\Enums\IncidentCause;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Incident;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Testing\ApiSpec;
use Heptapod\Testing\FakeOutboundHttp;
use Illuminate\Validation\ValidationException;
use Spectator\Exceptions\ResponseValidationException;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-03T12:00:00Z'));
    app()->singleton(ApiSpec::class);
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $this->token = $owner->createToken('check-api', ['*'])->plainTextToken;
    $this->withToken($this->token);
});

it('preserves the Oh Dear check label independently of the UI label key', function (): void {
    $check = Check::factory()->create(['type' => CheckKey::CertificateHealth]);

    $this->getJson('/api/monitors/'.$check->site_id)->assertOk()->assertJsonPath('checks.0.label', 'Certificate health');
});

it('shows uninstalled snapshots rejects active writes with 422 and permits cleanup', function (): void {
    app()->instance(CheckRegistry::class, new CheckRegistry(app()));
    $check = Check::factory()->create(['type' => CheckKey::Domain, 'latest_run_result' => CheckResult::Errored]);
    $this->getJson('/api/monitors')->assertOk()->assertJsonPath('data.0.checks.0.type', 'domain');
    $this->postJson('/api/checks/'.$check->id.'/enable')->assertUnprocessable()->assertJsonValidationErrors('type');
    $this->postJson('/api/checks/'.$check->id.'/request-run')->assertUnprocessable()->assertJsonValidationErrors('type');
    $this->postJson('/api/checks/'.$check->id.'/snooze', ['minutes' => 5])->assertUnprocessable()->assertJsonValidationErrors('type');
    expect($check->snoozes()->count())->toBe(0);
    expect(fn () => app(UpdateCheckConfig::class)($check, []))->toThrow(ValidationException::class);
    $snooze = $check->snoozes()->create(['starts_at' => now(), 'ends_at' => now()->addHour(), 'snoozed_by' => 'operator', 'source' => 'api']);
    $this->postJson('/api/checks/'.$check->id.'/disable')->assertOk()->assertJsonPath('enabled', false);
    $this->postJson('/api/checks/'.$check->id.'/unsnooze')->assertOk()->assertJsonPath('active_snooze', null);
    expect($snooze->refresh()->unsnoozed_at)->not->toBeNull();
    $this->deleteJson('/api/monitors/'.$check->site_id)->assertNoContent();
    $this->assertModelMissing($check);
});

it('rejects wrong field types for object and array root responses without skipping validation', function (): void {
    $spec = app(ApiSpec::class);
    expect(fn () => $spec->validate('GET', '/api/monitors/1', response()->json(['id' => 'wrong'])))->toThrow(ResponseValidationException::class);
    expect(fn () => $spec->validate('GET', '/api/monitors/1/uptime', response()->json([['datetime' => '2026-10-01 00:00:00', 'uptime_percentage' => 'wrong']])))->toThrow(ResponseValidationException::class);
    expect($spec->validated)->toBeEmpty();
});

it('filters history and maps run and summary errors without changing check snapshots', function (): void {
    $check = Check::factory()->create(['latest_run_result' => CheckResult::Errored, 'summary' => 'checks.engine.errored']);
    CheckRun::factory()->create(['check_id' => $check->id, 'result' => RunResult::Succeeded]);
    $error = CheckRun::factory()->create(['check_id' => $check->id, 'result' => RunResult::Errored, 'metrics' => null]);
    $this->getJson('/api/monitors/'.$check->site_id.'/checks/uptime/runs?filter[result]=failed')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $error->id)->assertJsonPath('data.0.result', 'failed');
    $this->getJson('/api/monitors/'.$check->site_id.'/check-summary/uptime')->assertOk()->assertJsonPath('result', 'failed');
    expect($check->refresh()->latest_run_result)->toBe(CheckResult::Errored);
});

it('claims manual runs once applies encrypted one-run headers and preserves permanent configuration', function (): void {
    $id = $this->postJson('/api/monitors', ['team_id' => 1, 'type' => 'http', 'url' => 'https://headers.test', 'uptime_check_settings' => ['http_client_headers' => [['name' => 'Authorization', 'value' => 'permanent']]]])->assertCreated()->json('id');
    $check = Check::query()->where('site_id', $id)->where('type', CheckKey::Uptime)->firstOrFail();
    $this->postJson('/api/checks/'.$check->id.'/request-run', ['httpClientHeaders' => [['name' => 'Authorization', 'value' => 'one-run-secret']]])->assertOk();
    $check->refresh();
    $claim = $check->claim_token;
    expect($claim)->not->toBeNull()->and($check->getRawOriginal('run_headers'))->not->toContain('one-run-secret');
    $this->postJson('/api/checks/'.$check->id.'/request-run')->assertUnprocessable();
    expect($check->refresh()->claim_token)->toBe($claim);
    $http = (new FakeOutboundHttp)->respondWith(new FetchRequest('https://headers.test', headers: ['authorization' => ['one-run-secret']], maxWireBytes: 10485760, maxDecodedBytes: 10485760, redirects: RedirectPolicy::Operator, timeoutSeconds: 30.0, acceptedStatuses: range(100, 599), strictRedirects: false), new FetchResult(200, 'healthy'));
    app()->instance(OutboundHttp::class, $http);
    app(CheckExecutor::class)->execute($check->id, $claim, 60);
    expect($http->requests)->toHaveCount(1);
    expect($check->refresh()->executionConfig()['http_client_headers'][0]['value'])->toBe('permanent')->and($check->run_headers)->toBeNull();
    $this->postJson('/api/checks/'.$check->id.'/disable')->assertOk();
    $this->postJson('/api/checks/'.$check->id.'/request-run')->assertUnprocessable();
    $this->postJson('/api/checks/'.$check->id.'/snooze', ['minutes' => true])->assertUnprocessable();
    $this->postJson('/api/checks/'.$check->id.'/snooze', ['minutes' => 1.5])->assertUnprocessable();
});

it('reads count based rollups gaps and bounded recent minutes from the same samples', function (): void {
    $hour = CarbonImmutable::now('UTC')->startOfHour()->subHour();
    $this->travelTo($hour->addHour());
    $site = Site::factory()->create(['created_at' => $hour->subDay()]);
    $check = Check::factory()->for($site)->create(['created_at' => $hour->subDay(), 'first_result_at' => $hour->subDay()]);
    $performance = Check::factory()->for($site)->create(['type' => CheckKey::Performance]);
    $empty = $this->getJson('/api/monitors/'.$site->id)->assertOk();
    expect(collect($empty->json('checks'))->firstWhere('type', 'performance')['average_response_time_in_ms'])->toBeNull();
    $timings = ['dns_time_in_seconds' => 1, 'tcp_time_in_seconds' => 2, 'ssl_handshake_time_in_seconds' => 3, 'remote_server_processing_time_in_seconds' => 4, 'download_time_in_seconds' => 5, 'total_time_in_seconds' => 15, 'curl' => ['header_size' => 10, 'size_download' => 20, 'speed_download' => 30]];
    foreach ([[RunResult::Succeeded, 46], [RunResult::Warning, 47], [RunResult::Failed, 48], [RunResult::Errored, 49]] as [$result, $minute]) {
        $ended = $hour->addMinutes($minute);
        CheckRun::factory()->create(['check_id' => $check->id, 'result' => $result, 'started_at' => $ended->subSeconds($result === RunResult::Failed ? 10 : 1), 'ended_at' => $ended, 'metrics' => $result === RunResult::Errored ? null : $timings]);
    }
    // Both owner and time boundaries exclude these deliberately large outliers.
    CheckRun::factory()->create(['check_id' => $check->id, 'ended_at' => $hour->subMinute(), 'started_at' => $hour->subMinutes(2), 'metrics' => ['total_time_in_seconds' => 999]]);
    CheckRun::factory()->create(['check_id' => $check->id, 'ended_at' => $hour->addHour(), 'started_at' => $hour->addHour()->subSecond(), 'metrics' => ['total_time_in_seconds' => 999]]);
    CheckRun::factory()->create(['check_id' => $performance->id, 'ended_at' => $hour->addMinutes(46), 'started_at' => $hour->addMinutes(45), 'metrics' => ['total_time_in_seconds' => 999]]);
    $this->artisan('heptapod:maintain-check-data', ['--recompute-range' => $hour->toIso8601String().','.$hour->addHour()->toIso8601String()])->assertSuccessful();
    $reader = app(RollupReader::class);
    $minutes = $reader->recent($check->id, $hour, $hour->addHour());
    expect(array_column($minutes, 'uptime_percentage'))->toEqual([100, 100, 0, 0]);
    $rollup = $reader->read($check->id, 'hourly', $hour, $hour->addHour())[0];
    expect(array_sum(array_column($minutes, 'completed_count')))->toBe($rollup['completed_count']);
    foreach (array_keys($rollup['metric_sums']) as $name) {
        expect(array_sum(array_map(fn (array $bucket) => $bucket['metric_sums'][$name] ?? 0, $minutes)))->toEqual($rollup['metric_sums'][$name]);
        expect(array_sum(array_map(fn (array $bucket) => $bucket['metric_counts'][$name] ?? 0, $minutes)))->toEqual($rollup['metric_counts'][$name]);
    }
    $from = $hour->format('YmdHis');
    $until = $hour->addHour()->format('YmdHis');
    $this->getJson('/api/monitors/'.$site->id.'/uptime?filter[started_at]='.$from.'&filter[ended_at]='.$until)->assertOk()->assertJsonCount(1)->assertJsonPath('0.uptime_percentage', 100);
    $this->getJson('/api/monitors/'.$site->id.'/http-uptime-metrics?filter[start]='.$from.'&filter[end]='.$until.'&filter[group_by]=minute')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.total_time_in_seconds', 15);
    $response = $this->getJson('/api/monitors/'.$site->id)->assertOk();
    expect(collect($response->json('checks'))->firstWhere('type', 'performance')['average_response_time_in_ms'])->toEqual(15000);
    $this->getJson('/api/monitors/'.$site->id.'/http-uptime-metrics?filter[start]='.$hour->subDays(2)->format('YmdHis').'&filter[end]='.$until.'&filter[group_by]=minute')->assertUnprocessable();
    config(['monitoring.raw_retention_days' => 3]);
    $expired = $this->getJson('/api/monitors/'.$site->id.'/http-uptime-metrics?filter[start]='.$hour->subDays(31)->format('YmdHis').'&filter[end]='.$hour->subDays(31)->addHour()->format('YmdHis').'&filter[group_by]=minute')->assertUnprocessable();
    expect($expired->json('errors')['filter.start'][0])->toBe('minute granularity is only available for the last 3 days');
});

it('serializes open target downtime with null end and sanitizes Markdown without counting checker defects', function (): void {
    $check = Check::factory()->create();
    $incident = Incident::factory()->create(['site_id' => $check->site_id, 'check_id' => $check->id, 'cause' => IncidentCause::Failed, 'notes' => '<script>alert(1)</script> [bad](javascript:alert(1))']);
    Incident::factory()->create(['site_id' => $check->site_id, 'check_id' => $check->id, 'cause' => IncidentCause::Errored, 'opened_at' => now()->subMinutes(2), 'first_failure_at' => now()->subMinutes(2), 'resolved_at' => now()->subMinute()]);
    $response = $this->getJson('/api/monitors/'.$check->site_id.'/downtime')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.ended_at', null);
    expect($response->json('data.0.notes_html'))->not->toContain('<script', 'javascript:');
    expect($this->sdk($this->token)->downtime($check->site_id, now()->subDay()->format('Y-m-d H:i:s'), now()->addDay()->format('Y-m-d H:i:s'))[0]->endedAt)->toBeNull();
});

it('rejects enabling an installed check whose retained config no longer meets its schema', function (): void {
    $check = Check::factory()->create(['type' => CheckKey::ApplicationHealth, 'enabled' => false]);

    $this->postJson('/api/checks/'.$check->id.'/enable')->assertUnprocessable()->assertJsonValidationErrors(['values.result_url', 'values.secret']);

    expect($check->refresh()->enabled)->toBeFalse();
});

it('rejects short one-run credentials and ignores empty override rows without changing configured headers', function (): void {
    $id = $this->postJson('/api/monitors', ['team_id' => 1, 'type' => 'http', 'url' => 'https://override-policy.test', 'uptime_check_settings' => ['http_client_headers' => [['name' => 'Authorization', 'value' => 'configured-secret']]]])->assertCreated()->json('id');
    $check = Check::query()->where('site_id', $id)->where('type', CheckKey::Uptime)->firstOrFail();
    foreach (['a', 'ab', 'abc', '   ', '  ab  '] as $value) {
        $this->postJson('/api/checks/'.$check->id.'/request-run', ['httpClientHeaders' => [['name' => 'X-Override', 'value' => $value]]])->assertUnprocessable()->assertJsonValidationErrors('headers.0.value');
        expect($check->refresh()->claim_token)->toBeNull();
    }
    $this->postJson('/api/checks/'.$check->id.'/request-run', ['httpClientHeaders' => [['name' => 'X-Override', 'value' => '']]])->assertOk();
    expect($check->refresh()->run_headers)->toBeNull();
    $http = (new FakeOutboundHttp)->respondWith(new FetchRequest('https://override-policy.test', headers: ['authorization' => ['configured-secret']], maxWireBytes: 10485760, maxDecodedBytes: 10485760, redirects: RedirectPolicy::Operator, timeoutSeconds: 30.0, acceptedStatuses: range(100, 599), strictRedirects: false), new FetchResult(200, 'healthy'));
    app()->instance(OutboundHttp::class, $http);
    app(CheckExecutor::class)->execute($check->id, $check->claim_token, 60);
    expect($http->requests)->toHaveCount(1)->and($check->refresh()->latest_run_result)->toBe(CheckResult::Succeeded);
    expect($check->refresh()->executionConfig()['http_client_headers'][0]['value'])->toBe('configured-secret');
});

it('preserves surrounding spaces in a valid one-run secret', function (): void {
    $check = Check::factory()->create();
    $this->postJson('/api/checks/'.$check->id.'/request-run', ['httpClientHeaders' => [['name' => 'X-Override', 'value' => ' abcd ']]])->assertOk();
    expect($check->refresh()->run_headers[0]['value'])->toBe(' abcd ');
});

it('rejects malformed normalized string lists without coercing their types or shape', function (array $patterns, string $error): void {
    $check = Check::factory()->create(['type' => CheckKey::BrokenLinks]);
    $this->putJson('/api/monitors/'.$check->site_id, ['broken_links_check_settings' => ['do_not_crawl_urls' => $patterns]])
        ->assertUnprocessable()->assertJsonValidationErrors($error);
    expect($check->refresh()->executionConfig())->toBe([]);
})->with([
    'non-string item' => [[1234, ''], 'values.do_not_crawl_urls.0'],
    'associative list' => [['path' => ' /blocked/* '], 'values.do_not_crawl_urls'],
]);

it('rejects malformed whitelist URLs and patterns at the API boundary', function (string $entry): void {
    $check = Check::factory()->create(['type' => CheckKey::BrokenLinks]);
    $this->putJson('/api/monitors/'.$check->site_id, ['broken_links_check_settings' => ['whitelisted_urls' => [$entry]]])
        ->assertUnprocessable()->assertJsonValidationErrors('values.whitelisted_urls.0');
})->with(['relative without wildcard' => 'not-absolute', 'invalid port' => 'https://client-a.example:abc/x', 'space inside host' => 'https://client a.example/path', 'surrounding whitespace' => ' https://client-a.example/path ', 'control inside pattern' => "*/news/\nitem"]);

it('trims opted-in string lists and stores no blank patterns through monitor updates', function (): void {
    $check = Check::factory()->create(['type' => CheckKey::BrokenLinks]);
    $this->putJson('/api/monitors/'.$check->site_id, ['broken_links_check_settings' => [
        'do_not_crawl_urls' => [''], 'force_crawl_urls' => [' ', ' /forced/* '],
        'exclude_patterns' => [' /excluded/* ', ''], 'whitelisted_urls' => ['https://allowed.example/path', '*/news/sample?edition=*'],
    ]])->assertOk()->assertJsonPath('broken_links_check_settings.do_not_crawl_urls', [])
        ->assertJsonPath('broken_links_check_settings.force_crawl_urls', ['/forced/*'])
        ->assertJsonPath('broken_links_check_settings.exclude_patterns', ['/excluded/*'])
        ->assertJsonPath('broken_links_check_settings.whitelisted_urls', ['https://allowed.example/path', '*/news/sample?edition=*']);
    expect($check->refresh()->executionConfig()['do_not_crawl_urls'])->toBe([]);
});
