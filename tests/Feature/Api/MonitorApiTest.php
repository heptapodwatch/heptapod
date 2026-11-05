<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Addons\Uptime\UptimeCheck;
use Heptapod\Checks\Execution\CheckExecutor;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\Http\FetchRequest;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\Http\RedirectPolicy;
use Heptapod\Contracts\RunResult;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Testing\ApiSpec;
use Heptapod\Testing\FakeOutboundHttp;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use OhDear\PhpSdk\Enums\CheckType;
use OhDear\PhpSdk\Requests\Monitors\GetMonitorsRequest;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-03T12:00:00Z'));
    app()->singleton(ApiSpec::class);
    $this->owner = User::factory()->create();
    $this->owner->assignRole('Owner');
    $this->token = $this->owner->createToken('contract', ['*'])->plainTextToken;
    $this->withToken($this->token);
});

it('hydrates the SDK through real routes and validates every implemented Oh Dear operation', function (): void {
    $sdk = $this->sdk($this->token);
    expect($sdk->me()->email)->toBe($this->owner->email);
    $monitor = $sdk->createMonitor(['team_id' => 1, 'type' => 'http', 'url' => 'https://example.test/a+b?x=1&y=2', 'tags' => ['production']]);
    $site = Site::query()->findOrFail($monitor->id);
    $registry = app(CheckRegistry::class);
    expect($site->checks()->where('enabled', true)->pluck('type')->all())->toEqualCanonicalizing(array_values(array_filter($registry->keys(), fn (CheckKey $key): bool => $registry->get($key)->definition()->recommended)));
    expect($sdk->monitor($monitor->id)->url)->toBe($site->url)
        ->and($sdk->monitorByUrl($site->url)->id)->toBe($site->id);
    $sdk->updateMonitor($site->id, ['friendly_name' => 'Production', 'uptime_check_settings' => ['http_verb' => 'get', 'http_client_headers' => [['name' => 'Authorization', 'value' => 'secret-token']]]]);
    expect($sdk->monitor($site->id)->label)->toBe('Production');
    $second = $sdk->createMonitor(['team_id' => 1, 'type' => 'http', 'url' => 'https://second.test']);
    $this->getJson('/api/monitors?page=1&per_page=1')->assertOk()->assertJsonPath('meta.last_page', 2)->assertJsonCount(1, 'data');
    // The SDK's paginator must exhaust all pages, not silently stop after page one.
    $paginator = $sdk->paginate(new GetMonitorsRequest);
    $paginator->setPerPageLimit(1);
    expect(iterator_to_array($paginator->items()))->toHaveCount(2);
    $check = $site->checks()->where('type', CheckKey::Uptime)->firstOrFail();
    expect($sdk->disableCheck($check->id)->enabled)->toBeFalse()
        ->and($sdk->enableCheck($check->id)->enabled)->toBeTrue();
    $sdk->snoozeCheck($check->id, 10);
    expect($check->snoozes()->whereNull('unsnoozed_at')->count())->toBe(1);
    $sdk->unsnoozeCheck($check->id);
    expect($check->snoozes()->whereNotNull('unsnoozed_at')->count())->toBe(1);
    expect($sdk->checkSummary($site->id, CheckType::Uptime)->result)->toBeNull();
    $this->getJson('/api/monitors/'.$site->id.'/checks/uptime/runs')->assertOk()->assertJsonCount(0, 'data');
    expect($sdk->uptime($site->id, now()->subDay()->format('Y-m-d H:i:s'), now()->format('Y-m-d H:i:s')))->toBeEmpty();
    expect($sdk->downtime($site->id, now()->subDay()->format('Y-m-d H:i:s'), now()->format('Y-m-d H:i:s')))->toBeEmpty();
    expect($sdk->httpUptimeMetrics($site->id, now()->subDay()->format('Y-m-d H:i:s'), now()->format('Y-m-d H:i:s')))->toBeEmpty();
    $sdk->createTag(['team_id' => 1, 'name' => 'critical', 'monitors' => [$site->id]]);
    expect($sdk->tags())->toHaveCount(2);
    $sdk->requestCheckRun($check->id);
    expect($check->refresh()->claim_token)->not->toBeNull();
    $sdk->deleteMonitor($second->id);
    expect(Site::query()->find($second->id))->toBeNull();
    $cron = $sdk->createCronCheckDefinition($site->id, ['name' => 'SDK task', 'type' => 'simple', 'frequency_in_minutes' => 10, 'grace_time_in_minutes' => 5, 'description' => '']);
    expect(iterator_to_array($sdk->cronCheckDefinitions($site->id)))->toHaveCount(1);
    expect($sdk->updateCronCheckDefinition($cron->id, ['name' => 'SDK updated'])->name)->toBe('SDK updated');
    expect($sdk->snoozeCronCheckDefinition($cron->id, 10)->activeSnooze)->not->toBeNull();
    expect($sdk->unsnoozeCronCheckDefinition($cron->id)->activeSnooze)->toBeNull();
    // SDK 4.7 expects a data wrapper; the pinned spec and schedule-monitor require a bare array.
    $this->postJson('/api/monitors/'.$site->id.'/cron-checks/sync', ['cron_checks' => [['name' => 'SDK updated', 'type' => 'simple', 'frequency_in_minutes' => 15, 'grace_time_in_minutes' => 5, 'description' => '']]])->assertOk()->assertJsonPath('0.id', $cron->id);
    $sdk->deleteCronCheckDefinition($cron->id);
    $scheduled = $this->postJson('/api/maintenance-periods', ['monitor_id' => $site->id,
        'starts_at' => now()->addDay()->format('Y-m-d H:i'), 'ends_at' => now()->addDay()->addHour()->format('Y-m-d H:i')])->assertCreated()->json('id');
    $this->getJson('/api/monitors/'.$site->id.'/maintenance-periods')->assertOk();
    $this->putJson('/api/maintenance-periods/'.$scheduled, ['starts_at' => now()->addDay()->format('Y-m-d H:i'),
        'ends_at' => now()->addDay()->addHours(2)->format('Y-m-d H:i')])->assertOk();
    $this->deleteJson('/api/maintenance-periods/'.$scheduled)->assertNoContent();
    $this->postJson('/api/monitors/'.$site->id.'/start-maintenance')->assertOk();
    $this->postJson('/api/monitors/'.$site->id.'/stop-maintenance')->assertNoContent();
    $implemented = collect(app(ApiSpec::class)->operations())
        ->map(fn (array $entry): string => $entry['operation']->operationId)
        ->filter(fn (string $operation): bool => Route::has('api.'.$operation))
        ->sort()->values()->all();
    expect(collect(array_keys(app(ApiSpec::class)->validated))->sort()->values()->all())->toBe($implemented);
});

it('accounts for every registered API route in the Oh Dear spec or native allow-list', function (): void {
    $specRoutes = collect(app(ApiSpec::class)->operations())
        ->map(fn (array $entry): string => 'api.'.$entry['operation']->operationId)->all();
    $nativeRoutes = [
        'api.getOpenApiDocument',
        'api.listActivity',
        'api.listUpcoming',
        'api.findings',
        'api.listIncidents',
        'api.getIncident',
        'api.internal.getIncidentNote',
        'api.internal.saveIncidentNote',
        'api.internal.snoozeIncident',
        'api.internal.unsnoozeIncident',
        'api.createCheckRun',
        'api.getRun',
    ];
    $registered = collect(Route::getRoutes()->getRoutes())
        ->map(fn (Illuminate\Routing\Route $route): ?string => $route->getName())
        ->filter(fn (?string $name): bool => $name !== null && str_starts_with($name, 'api.'));

    expect($registered->diff([...$specRoutes, ...$nativeRoutes])->values()->all())->toBeEmpty();
    expect(collect($nativeRoutes)->diff($registered)->values()->all())->toBeEmpty();
});

it('returns a genuine empty 204 through the response validator', function (): void {
    $site = Site::factory()->create();
    $this->deleteJson('/api/monitors/'.$site->id)->assertNoContent()->assertContent('')->assertHeaderMissing('Content-Type');
    $this->assertModelMissing($site);
});

it('rejects duplicate create and update URLs as field errors and keeps state intact', function (): void {
    $first = $this->postJson('/api/monitors', ['team_id' => 1, 'type' => 'http', 'url' => 'https://unique.test'])->assertCreated()->json('id');
    $second = $this->postJson('/api/monitors', ['team_id' => 1, 'type' => 'http', 'url' => 'https://other.test'])->assertCreated()->json('id');
    $this->postJson('/api/monitors', ['team_id' => 1, 'type' => 'http', 'url' => 'https://unique.test'])->assertUnprocessable()->assertJsonValidationErrors('url');
    $this->putJson('/api/monitors/'.$second, ['url' => 'https://unique.test'])->assertUnprocessable()->assertJsonValidationErrors('url');
    expect(Site::query()->count())->toBe(2)->and(Site::query()->findOrFail($second)->url)->toBe('https://other.test');
    $this->putJson('/api/monitors/'.$first, ['url' => 'https://unique.test'])->assertOk();
});

it('preserves omitted settings encrypts secrets and rejects unsupported configuration', function (): void {
    $registry = new CheckRegistry(app());
    $registry->register(UptimeCheck::class);
    app()->instance(CheckRegistry::class, $registry);
    expect($registry->keys())->not->toContain(CheckKey::Dns);
    $id = $this->postJson('/api/monitors', ['team_id' => 1, 'type' => 'http', 'url' => 'https://settings.test', 'uptime_check_settings' => ['timeout' => 5, 'http_client_headers' => [['name' => 'Authorization', 'value' => 'private-token']]]])->assertCreated()->json('id');
    $this->putJson('/api/monitors/'.$id, ['uptime_check_settings' => ['look_for_string' => 'hello']])->assertOk()->assertJsonPath('uptime_check_settings.timeout', 5)->assertJsonPath('uptime_check_settings.http_client_headers.0.value', '********');
    $check = Check::query()->where('site_id', $id)->where('type', CheckKey::Uptime)->firstOrFail();
    expect($check->getRawOriginal('config_secrets'))->not->toContain('private-token');
    foreach ([['type' => 'tcp'], ['checks' => ['ai']], ['dns_check_settings' => []], ['uptime_check_settings' => ['location' => 'paris']], ['uptime_check_settings' => ['tcp_send_string' => 'hello']]] as $unsupported) {
        $this->putJson('/api/monitors/'.$id, $unsupported)->assertUnprocessable();
    }
    $this->getJson('/api/monitors/'.$id)->assertOk()->assertJsonPath('uptime_check_settings.look_for_string', 'hello');
});

it('supports both pagination dialects preserves links and rejects mixed raw query names', function (): void {
    $this->getJson('/api/monitors')->assertOk()->assertJsonPath('meta.from', null)->assertJsonPath('meta.to', null);
    foreach (['https://page1.test', 'https://page2.test'] as $url) {
        $this->postJson('/api/monitors', ['team_id' => 1, 'type' => 'http', 'url' => $url])->assertCreated();
    }
    $page = $this->getJson('/api/monitors?page[number]=2&page[size]=1&filter[team_id]=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.current_page', 2);
    expect(urldecode($page->json('links.prev')))->toContain('page[number]=1', 'page[size]=1', 'filter[team_id]=1');
    foreach (['page=1&page[number]=2', 'page[number]=2&page=1', 'page[size]=1&per_page=1', 'page=0', 'per_page=201', 'filter[team_id]=2'] as $query) {
        $this->getJson('/api/monitors?'.$query)->assertUnprocessable();
    }
});

it('creates monitors without setup-only checks and configures health strictly before enabling it', function (): void {
    $id = $this->postJson('/api/monitors', ['team_id' => 1, 'type' => 'http', 'url' => 'https://health-setup.test'])->assertCreated()->json('id');
    expect(Check::query()->where('site_id', $id)->whereIn('type', [CheckKey::ApplicationHealth, CheckKey::Cron])->count())->toBe(0);
    $count = Check::query()->where('site_id', $id)->count();
    $this->putJson('/api/monitors/'.$id, ['friendly_name' => 'Needs setup'])->assertOk();
    expect(Check::query()->where('site_id', $id)->count())->toBe($count);
    foreach ([
        [[], ['values.result_url', 'values.secret']],
        [['result_url' => 'https://health-setup.test/health'], ['values.secret']],
        [['secret' => 'health-private'], ['values.result_url']],
    ] as [$settings, $errors]) {
        $this->putJson('/api/monitors/'.$id, ['checks' => ['application_health'], 'application_health_check_settings' => $settings])->assertUnprocessable()->assertJsonValidationErrors($errors);
        expect(Check::query()->where('site_id', $id)->where('type', CheckKey::ApplicationHealth)->exists())->toBeFalse();
    }
    $this->putJson('/api/monitors/'.$id, ['checks' => ['application_health']])->assertUnprocessable()->assertJsonValidationErrors(['values.result_url', 'values.secret']);
    $this->putJson('/api/monitors/'.$id, [
        'checks' => ['application_health'],
        'application_health_check_settings' => ['result_url' => 'https://health-setup.test/health', 'secret' => 'health-private'],
    ])->assertOk()->assertDontSee('health-private');
    $check = Check::query()->where('site_id', $id)->where('type', CheckKey::ApplicationHealth)->sole();
    expect($check->enabled)->toBeTrue()->and($check->executionConfig()['secret'])->toBe('health-private');
    $before = $check->getRawOriginal();
    $this->travel(1)->minutes();
    $this->putJson('/api/monitors/'.$id, ['friendly_name' => 'Configured'])->assertOk();
    expect($check->refresh()->getRawOriginal())->toBe($before);
});

it('bounds monitor URLs at the source on create and update', function (int $length): void {
    $url = 'https://length.example/'.str_repeat('a', $length - strlen('https://length.example/'));
    $site = Site::factory()->create();
    if ($length === 2048) {
        $createdUrl = str_replace('length.example', 'create.example', $url);
        $this->postJson('/api/monitors', ['team_id' => 1, 'type' => 'http', 'url' => $createdUrl, 'checks' => []])->assertCreated()->assertJsonPath('url', $createdUrl);
        $this->putJson('/api/monitors/'.$site->id, ['url' => $url])->assertOk()->assertJsonPath('url', $url);
    } else {
        $this->postJson('/api/monitors', ['team_id' => 1, 'type' => 'http', 'url' => $url])->assertUnprocessable()->assertJsonValidationErrors('url');
        $this->putJson('/api/monitors/'.$site->id, ['url' => $url])->assertUnprocessable()->assertJsonValidationErrors('url');
        expect($site->fresh()->url)->toBe($site->url)->and(Site::query()->where('url', $url)->exists())->toBeFalse();
    }
})->with([2048, 2049]);

it('rejects short credentials but permits explicit header clearing and preserves null or masked values', function (string $field): void {
    $check = Check::factory()->create(['config' => app(CheckRegistry::class)->get(CheckKey::Uptime)->definition()->configSchema->validateConfig(['http_client_headers' => [['name' => 'Authorization', 'value' => 'stored-secret']]])]);
    $check->site->update(['crawler_headers' => [['name' => 'Authorization', 'value' => 'stored-secret']]]);
    $path = $field === 'crawler_headers' ? 'crawler_headers' : 'uptime_check_settings.http_client_headers';
    foreach (['a', 'ab', 'abc', '   ', '  ab  '] as $secret) {
        $body = [];
        Arr::set($body, $path, [['name' => 'Authorization', 'value' => $secret]]);
        $error = $field === 'crawler_headers' ? 'crawler_headers.0.value' : 'values.http_client_headers.0.value';
        $this->putJson('/api/monitors/'.$check->site_id, $body)->assertUnprocessable()->assertJsonValidationErrors($error);
    }
    foreach ([null, '********'] as $secret) {
        $body = [];
        Arr::set($body, $path, [['name' => 'Authorization', 'value' => $secret]]);
        $this->putJson('/api/monitors/'.$check->site_id, $body)->assertOk();
    }
    expect($check->refresh()->executionConfig()['http_client_headers'][0]['value'])->toBe('stored-secret')
        ->and($check->site->refresh()->crawler_headers[0]['value'])->toBe('stored-secret');
    $body = [];
    Arr::set($body, $path, [['name' => 'Authorization', 'value' => '']]);
    $this->putJson('/api/monitors/'.$check->site_id, $body)->assertOk();
    expect($field === 'crawler_headers' ? $check->site->refresh()->crawler_headers : $check->refresh()->executionConfig()['http_client_headers'])->toBe([]);
})->with(['crawler_headers', 'http_client_headers']);

it('rejects enabling a check with a legacy short crawler credential', function (): void {
    $check = Check::factory()->create(['enabled' => false]);
    $check->site->update(['crawler_headers' => [['name' => 'X-Legacy', 'value' => 'abc']]]);
    $this->postJson('/api/checks/'.$check->id.'/enable')->assertUnprocessable()->assertJsonValidationErrors('crawler_headers.0.value');
    expect($check->refresh()->enabled)->toBeFalse();
});

it('rejects URL credentials on create and update without changing stored state', function (string $url): void {
    $check = Check::factory()->create();
    $site = $check->site;
    $before = $site->refresh()->getRawOriginal();
    $count = Site::query()->count();
    $message = 'Credentials in the URL are not supported; use an Authorization header.';
    $created = $this->postJson('/api/monitors', ['team_id' => 1, 'type' => 'http', 'url' => $url])->assertUnprocessable()->assertJsonValidationErrors('url');
    $updated = $this->putJson('/api/monitors/'.$site->id, ['url' => $url, 'friendly_name' => 'Must not persist'])->assertUnprocessable()->assertJsonValidationErrors('url');
    expect($created->json('errors.url'))->toContain($message)->and($updated->json('errors.url'))->toContain($message);
    expect($site->refresh()->getRawOriginal())->toBe($before)->and(Site::query()->count())->toBe($count);
})->with(['https://user:pass@host.example/', 'https://user@host.example/', 'https://@host.example/']);

it('preserves surrounding spaces in a valid saved crawler secret', function (): void {
    $check = Check::factory()->create();
    $this->putJson('/api/monitors/'.$check->site_id, ['crawler_headers' => [['name' => 'Authorization', 'value' => ' abcd ']]])->assertOk();
    expect($check->site->refresh()->crawler_headers[0]['value'])->toBe(' abcd ');
});
it('round trips bounded minute hour and day schedules for every configured check', function (int $value, string $unit, int $seconds): void {
    $settings = ['frequency_value' => $value, 'frequency_unit' => $unit, 'failed_notification_threshold' => 3];
    $id = $this->postJson('/api/monitors', [
        'team_id' => 1, 'type' => 'http', 'url' => 'https://schedule.test', 'checks' => ['uptime', 'domain'],
        'uptime_check_settings' => $settings, 'domain_check_settings' => $settings,
    ])->assertCreated()->json('id');
    $checks = Check::query()->where('site_id', $id)->get();
    foreach ($checks as $check) {
        expect($check->interval_seconds)->toBe($seconds);
        if ($check->type === CheckKey::Uptime) {
            expect($check->site->alert_after_minutes)->toBe(3)
                ->and($check->site->alert_recovery_checks)->toBe(3);
        } else {
            expect($check->failure_threshold)->toBe(3);
        }
    }
    $wire = $this->getJson('/api/monitors/'.$id)->assertOk()->json();
    foreach (['uptime', 'domain'] as $type) {
        $projected = $wire[$type.'_check_settings'];
        expect($projected['failed_notification_threshold'])->toBe(3);
        $this->putJson('/api/monitors/'.$id, [$type.'_check_settings' => $projected])->assertOk()
            ->assertJsonPath($type.'_check_settings.failed_notification_threshold', 3);
    }
    expect($checks->map(fn ($check): int => $check->refresh()->interval_seconds)->all())->toBe([$seconds, $seconds]);
})->with([
    'minute lower bound' => [1, 'minute', 60], 'minute upper bound' => [60, 'minute', 3600],
    'hour lower bound' => [1, 'hour', 3600], 'hour upper bound' => [60, 'hour', 216000],
    'day lower bound' => [1, 'day', 86400], 'day upper bound' => [60, 'day', 5184000],
]);

it('inherits uptime alert timing when a blank threshold is saved through the API', function (string $blank): void {
    $check = Check::factory()->create(['type' => CheckKey::Uptime]);
    $site = $check->site;
    $site->update(['alert_after_minutes' => 5, 'alert_recovery_checks' => 3]);

    $this->putJson('/api/monitors/'.$site->id, ['uptime_check_settings' => ['failed_notification_threshold' => $blank]])->assertOk();

    expect($site->refresh()->alert_after_minutes)->toBeNull()
        ->and($site->alert_recovery_checks)->toBeNull();
})->with(['empty' => '', 'whitespace' => " \t\n "]);

it('rejects invalid uptime thresholds with exact errors and leaves alert timing unchanged', function (int|string $value, array $messages): void {
    $check = Check::factory()->create(['type' => CheckKey::Uptime]);
    $site = $check->site;
    $site->update(['alert_after_minutes' => 5, 'alert_recovery_checks' => 3]);

    $this->putJson('/api/monitors/'.$site->id, ['uptime_check_settings' => ['failed_notification_threshold' => $value]])
        ->assertUnprocessable()->assertJsonPath('errors.failed_notification_threshold', $messages);

    expect($site->refresh()->alert_after_minutes)->toBe(5)
        ->and($site->alert_recovery_checks)->toBe(3);
})->with([
    'zero' => [0, ['The failed notification threshold field must be at least 1.']],
    'negative' => [-1, ['The failed notification threshold field must be at least 1.']],
    'non-numeric' => ['invalid', ['The failed notification threshold field must be an integer.']],
]);

it('rejects blank frequency values without changing the stored schedule', function (string $blank): void {
    $check = Check::factory()->create(['type' => CheckKey::Uptime, 'interval_seconds' => 300]);

    $this->putJson('/api/monitors/'.$check->site_id, ['uptime_check_settings' => ['frequency_value' => $blank]])
        ->assertUnprocessable()->assertJsonPath('errors.frequency_value', ['The frequency value field must have a value.']);

    expect($check->refresh()->interval_seconds)->toBe(300);
})->with(['empty' => '', 'whitespace' => " \t\n "]);

it('rejects out of range schedules with all fields and keeps stored settings unchanged', function (): void {
    $id = $this->postJson('/api/monitors', ['team_id' => 1, 'type' => 'http', 'url' => 'https://schedule.test', 'checks' => ['uptime']])->assertCreated()->json('id');
    $check = Check::query()->where('site_id', $id)->sole();
    $before = $check->getRawOriginal();
    foreach ([0, 61] as $value) {
        $this->putJson('/api/monitors/'.$id, ['uptime_check_settings' => ['frequency_value' => $value, 'frequency_unit' => 'week', 'failed_notification_threshold' => 1001]])
            ->assertUnprocessable()->assertJsonValidationErrors(['frequency_value', 'frequency_unit', 'failed_notification_threshold']);
        expect($check->refresh()->getRawOriginal())->toBe($before);
    }
});

it('round trips native status patterns through the SDK API and executes their real uptime evaluation', function (int $status, RunResult $result): void {
    $sdk = $this->sdk($this->token);
    $patterns = ['2*', '30*', '404'];
    $monitor = $sdk->createMonitor(['team_id' => 1, 'type' => 'http', 'url' => 'https://patterns.test', 'checks' => ['uptime'], 'uptime_check_settings' => ['http_verb' => 'get', 'valid_status_codes' => $patterns, 'look_for_string' => '', 'absent_string' => null]]);
    expect($sdk->monitor($monitor->id)->uptimeCheckSettings['valid_status_codes'])->toBe($patterns);
    $sdk->updateMonitor($monitor->id, ['uptime_check_settings' => $sdk->monitor($monitor->id)->uptimeCheckSettings]);
    $check = Check::query()->where('site_id', $monitor->id)->sole();
    $this->getJson('/api/monitors/'.$monitor->id)->assertOk()->assertJsonPath('checks.0.settings.uptime_check_settings.valid_status_codes', $patterns);
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)->respondWith(new FetchRequest(
        'https://patterns.test', maxWireBytes: 10485760, maxDecodedBytes: 10485760,
        redirects: RedirectPolicy::Operator, timeoutSeconds: 30, acceptedStatuses: range(100, 599), strictRedirects: false,
    ), new FetchResult($status, '')));
    $this->postJson('/api/checks/'.$check->id.'/request-run')->assertOk();
    $check->refresh();
    app(CheckExecutor::class)->execute($check->id, $check->claim_token, 60);
    $run = CheckRun::query()->where('check_id', $check->id)->latest('id')->firstOrFail();
    expect($run->result)->toBe($result)
        ->and($run->evidence['issues'])->toBe($result === RunResult::Failed ? ['status'] : []);
})->with(['prefix wildcard' => [301, RunResult::Succeeded], 'exact code' => [404, RunResult::Succeeded], 'rejected status' => [410, RunResult::Failed]]);

it('round trips known unpolled DNS types in uppercase and rejects duplicates and unknown names', function (): void {
    $id = $this->postJson('/api/monitors', ['team_id' => 1, 'type' => 'http', 'url' => 'https://ignored.test', 'checks' => ['dns'], 'dns_check_settings' => ['ignored_record_types' => ['soa', 'srv', 'ptr']]])
        ->assertCreated()->assertJsonPath('dns_check_settings.ignored_record_types', ['SOA', 'SRV', 'PTR'])->json('id');
    $sdk = $this->sdk($this->token);
    $settings = $sdk->monitor($id)->dnsCheckSettings;
    $sdk->updateMonitor($id, ['dns_check_settings' => $settings]);
    expect($sdk->monitor($id)->dnsCheckSettings['ignored_record_types'])->toBe(['SOA', 'SRV', 'PTR']);
    $this->putJson('/api/monitors/'.$id, ['dns_check_settings' => ['ignored_record_types' => ['SOA', 'soa']]])
        ->assertUnprocessable()->assertJsonValidationErrors(['values.ignored_record_types.0', 'values.ignored_record_types.1']);
    $this->putJson('/api/monitors/'.$id, ['dns_check_settings' => ['ignored_record_types' => ['TYPO']]])
        ->assertUnprocessable()->assertJsonValidationErrors('values.ignored_record_types.0');
    $this->getJson('/api/monitors/'.$id)->assertOk()->assertJsonPath('dns_check_settings.ignored_record_types', ['SOA', 'SRV', 'PTR']);
});
