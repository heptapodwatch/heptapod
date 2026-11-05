<?php

use Carbon\CarbonImmutable;
use Heptapod\Actions\ImportOhDearAccount;
use Heptapod\Actions\UpdateCheckConfig;
use Heptapod\Checks\Recording\RecordableRun;
use Heptapod\Checks\Recording\TransactionalResultRecorder;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\Http\FetchRequest;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\OhDearImportMapping;
use Heptapod\Exceptions\UndeclaredImportNotice;
use Heptapod\Models\RecurringMaintenancePeriod;
use Heptapod\Models\Site;
use Heptapod\Testing\ClaimedRun;
use Heptapod\Testing\FakeOutboundHttp;
use Heptapod\Testing\OhDearAccountTransport;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Saloon\Http\Faking\MockClient;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05T12:00:00Z'));
});

/** @return array<string, mixed> */
function realOhDearAccount(): array
{
    $load = static fn (string $file): array => json_decode(file_get_contents(base_path("tests/Fixtures/ohdear/real/{$file}.json")), true, flags: JSON_THROW_ON_ERROR);
    $responses = ['/api/me' => $load('me'), '/api/monitors' => $load('monitors')];
    $details = [];
    foreach ([501, 502, 503, 504, 505] as $id) {
        foreach (['cron-checks' => 'cron-checks', 'maintenance-periods' => 'maintenance', 'recurring-maintenance-periods' => 'recurring-maintenance'] as $endpoint => $file) {
            $responses["/api/monitors/{$id}/{$endpoint}"] = $load("{$file}-{$id}");
        }
        $details[$id] = ['cron' => [], 'maintenance' => [], 'recurring' => []];
    }

    return ['me' => $responses['/api/me'], 'monitors' => $load('monitors')['data'], 'monitor_details' => $details, 'cron' => [], 'maintenance' => [], 'recurring' => [], 'responses' => $responses];
}

/** @param list<string> $excluded
 * @return list<string>
 */
function expectedRealOhDearChecks(int $id = 501, array $excluded = []): array
{
    $registry = app(CheckRegistry::class);
    $monitor = collect(realOhDearAccount()['monitors'])->firstWhere('id', $id);
    $excluded = [...$excluded, 'cron', ...(! in_array($id, [501, 503, 504], true) ? ['application_health'] : [])];

    return array_values(array_filter(array_column($monitor['checks'], 'type'), static fn (string $type): bool => ! in_array($type, $excluded, true) && ($key = CheckKey::tryFrom($type)) !== null && $registry->importMapping($key) !== null));
}

/** @param array<string, mixed> $monitor */
function expectedRealOhDearSkipped(array $monitor): string
{
    $registry = app(CheckRegistry::class);
    $mapping = static fn (string $type): ?OhDearImportMapping => ($key = CheckKey::tryFrom($type)) === null ? null : $registry->importMapping($key);
    $skipped = array_values(array_filter(array_column($monitor['checks'], 'type'), static fn (string $type): bool => ! $mapping($type) instanceof OhDearImportMapping));
    foreach (['uptime', 'performance', 'certificate_health', 'dns', 'broken_links', 'application_health', 'domain', 'sitemap', 'lighthouse', 'ports', 'dns_blocklist', 'ai'] as $type) {
        $values = $monitor[$type.'_check_settings'] ?? [];
        if ($type === 'broken_links') {
            $nested = collect($monitor['checks'])->firstWhere('type', $type)['settings'];
            $values = array_replace(Arr::except($nested, ['crawler_headers']), $values);
        }
        $supported = $mapping($type);
        if (! $supported instanceof OhDearImportMapping && $values !== []) {
            $skipped[] = $type.'_check_settings';
        } elseif ($supported instanceof OhDearImportMapping) {
            foreach (array_diff(array_keys($values), $supported->settings) as $field) {
                $skipped[] = $type.'_check_settings.'.$field;
            }
        }
    }
    foreach ($monitor['include_check_types_in_report'] as $type) {
        if (! $mapping($type) instanceof OhDearImportMapping) {
            $skipped[] = 'include_check_types_in_report';
        }
    }

    return implode(', ', array_unique($skipped));
}

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('imports all five real-shaped monitors without rejecting default nulls', function (bool $dryRun): void {
    app(OhDearAccountTransport::class)->fake(realOhDearAccount());
    $before = DB::table('activity_log')->count();
    $exit = Artisan::call('heptapod:import-ohdear', ['--token' => 'fake-real-account-token', '--dry-run' => $dryRun]);
    $output = Artisan::output();
    expect($exit)->toBe(0, $output);
    expect($output)->not->toContain('Invalid fields', 'fake-real-account-token', 'fake-health-secret-');
    preg_match_all('/^\|[ \t]*(\d+)[ \t]*\|([^|]+)\|([^|]+)\|[ \t]*$/m', $output, $matches, PREG_SET_ORDER);
    $rows = [];
    foreach ($matches as $match) {
        $rows[(int) $match[1]] = [trim($match[2]), trim($match[3])];
    }
    expect($matches)->toHaveCount(5);
    $monitors = collect(realOhDearAccount()['monitors'])->keyBy('id');
    foreach ([501 => 'cron', 502 => 'application_health, cron', 503 => 'cron', 504 => 'cron', 505 => 'application_health, cron'] as $id => $setupRequired) {
        $details = 'Cron definitions: 0; maintenance periods: 0; recurring periods: 0'
            .'; Not imported: setup required: '.$setupRequired
            .'; Cron is disabled in Oh Dear and has no task definitions; not imported (setup required).'
            .'; Not yet supported, skipped: '.expectedRealOhDearSkipped($monitors[$id]);
        expect($rows[$id] ?? null)->toBe(['Create', $details], "Monitor {$id} output row");
    }
    if ($dryRun) {
        expect(Site::query()->count())->toBe(0)
            ->and(DB::table('checks')->count())->toBe(0)
            ->and(DB::table('activity_log')->count())->toBe($before);

        return;
    }
    expect(Site::query()->count())->toBe(5)->and(DB::table('cron_checks')->count())->toBe(0);
    foreach ([501, 502, 503, 504, 505] as $id) {
        $site = Site::query()->where('oh_dear_id', $id)->sole();
        $checks = $site->checks()->get()->keyBy(fn ($check): string => $check->type->value);
        expect($checks->keys()->all())->toEqualCanonicalizing(expectedRealOhDearChecks($id));
        expect($checks['sitemap']->enabled)->toBeTrue()
            ->and($checks['sitemap']->executionConfig())->toMatchArray(['path' => '/fixture-sitemap.xml', 'speed' => 'default'])
            ->and($checks['sitemap']->interval_seconds)->toBe(86400)
            ->and($checks['sitemap']->failure_threshold)->toBe(1);
        expect($checks['uptime']->executionConfig()['http_verb'])->toBe('GET')
            ->and($checks['uptime']->executionConfig()['valid_status_codes'])->toBe(['2*'])
            ->and($checks['uptime']->executionConfig()['look_for_string'])->toBeNull()
            ->and($checks['uptime']->executionConfig()['absent_string'])->toBeNull()
            ->and($checks['uptime']->interval_seconds)->toBe(60)
            ->and($checks['uptime']->failure_threshold)->toBe(2)
            ->and($checks['certificate_health']->executionConfig()['expires_soon_threshold_in_days'])->toBe(14)
            ->and($checks['certificate_health']->interval_seconds)->toBe(1800)
            ->and($checks['performance']->failure_threshold)->toBe(1)
            ->and($checks['performance']->executionConfig()['change_percentage'])->toBe($id === 503 ? 100 : 50)
            ->and($checks['dns']->interval_seconds)->toBe(7200)
            ->and($checks['dns']->enabled)->toBe($id !== 504)
            ->and($checks['domain']->executionConfig()['expires_soon_threshold_in_days'])->toBe(30)
            ->and($checks['domain']->interval_seconds)->toBe(14400)
            ->and($checks['domain']->enabled)->toBe(! in_array($id, [501, 504], true));
        if (isset($checks['application_health'])) {
            $health = $checks['application_health'];
            expect($health->executionConfig()['secret'])->toBe("fake-health-secret-{$id}")
                ->and($health->failure_threshold)->toBe(1);
            expect(DB::table('checks')->where('id', $health->id)->value('config_secrets'))->not->toContain('fake-health-secret-');
        }
    }
    $ids = DB::table('checks')->orderBy('id')->pluck('id')->all();
    app(OhDearAccountTransport::class)->fake(realOhDearAccount());
    expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fake-real-account-token']))->toBe(0);
    expect(DB::table('checks')->orderBy('id')->pluck('id')->all())->toBe($ids);
    expect(DB::table('activity_log')->get()->toJson())->not->toContain('fake-health-secret-', 'fake-real-account-token');
})->with(['dry-run' => true, 'real import' => false])->group('ohdear-real');

test('rerun adds a previously absent sitemap check once without duplicating the site', function (): void {
    $account = realOhDearAccount();
    $account['monitors'] = [$account['monitors'][0]];
    $account['monitors'][0]['checks'] = array_values(array_filter($account['monitors'][0]['checks'], static fn (array $check): bool => $check['type'] !== 'sitemap'));
    unset($account['monitors'][0]['sitemap_check_settings']);
    app(OhDearAccountTransport::class)->fake($account);
    expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fake-real-account-token']))->toBe(0, Artisan::output());
    $site = Site::query()->where('oh_dear_id', 501)->sole();
    expect($site->checks()->where('type', 'sitemap')->count())->toBe(0);

    $account['monitors'] = [realOhDearAccount()['monitors'][0]];
    $account['monitors'][0]['sitemap_check_settings']['frequency_value'] = 2;
    $account['monitors'][0]['sitemap_check_settings']['frequency_unit'] = 'hour';
    app(OhDearAccountTransport::class)->fake($account);
    expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fake-real-account-token']))->toBe(0, Artisan::output());
    $check = $site->checks()->where('type', 'sitemap')->sole();
    expect($check->enabled)->toBeTrue()->and($check->executionConfig()['path'])->toBe('/fixture-sitemap.xml')
        ->and($check->interval_seconds)->toBe(7200);
    app(OhDearAccountTransport::class)->fake($account);
    expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fake-real-account-token']))->toBe(0, Artisan::output());
    expect(Site::query()->where('oh_dear_id', 501)->count())->toBe(1)
        ->and($site->checks()->where('type', 'sitemap')->sole()->id)->toBe($check->id);
});

test('imports sitemap defaults while preserving its disabled state', function (bool $omit): void {
    $account = realOhDearAccount();
    $account['monitors'] = [$account['monitors'][0]];
    app(OhDearAccountTransport::class)->fake($account);
    expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fake-real-account-token']))->toBe(0, Artisan::output());
    $check = Site::query()->where('oh_dear_id', 501)->sole()->checks()->where('type', 'sitemap')->sole();
    app(UpdateCheckConfig::class)($check, ['path' => '/local.xml', 'speed' => 'slow']);
    foreach (['path', 'speed'] as $field) {
        if ($omit) {
            unset($account['monitors'][0]['sitemap_check_settings'][$field]);
        } else {
            $account['monitors'][0]['sitemap_check_settings'][$field] = null;
        }
    }
    foreach ($account['monitors'][0]['checks'] as &$sourceCheck) {
        if ($sourceCheck['type'] === 'sitemap') {
            $sourceCheck['enabled'] = false;
        }
    }
    unset($sourceCheck);
    app(OhDearAccountTransport::class)->fake($account);
    $exit = Artisan::call('heptapod:import-ohdear', ['--token' => 'fake-real-account-token']);
    $output = Artisan::output();
    expect($exit)->toBe(0, $output);
    expect($check->refresh()->executionConfig())->toMatchArray(['path' => '/sitemap.xml', 'speed' => 'default'])
        ->and($check->enabled)->toBeFalse();
})->with(['null' => false, 'absent' => true]);

test('reports every invalid field per check while importing the valid remainder', function (bool $dryRun): void {
    $account = realOhDearAccount();
    $account['monitors'] = [$account['monitors'][0]];
    $account['monitors'][0]['uptime_check_settings'] = ['http_verb' => 'invalid', 'timeout' => 0, 'valid_status_codes' => ['bad'], 'frequency_value' => 0, 'frequency_unit' => 'week', 'failed_notification_threshold' => 1001];
    $account['monitors'][0]['domain_check_settings'] = ['expires_soon_threshold_in_days' => -1, 'frequency_value' => 61, 'frequency_unit' => 'week'];
    $account['monitors'][0]['sitemap_check_settings'] = ['path' => 'https://other.example/sitemap.xml', 'speed' => 'invalid'];
    app(OhDearAccountTransport::class)->fake($account);
    $exit = Artisan::call('heptapod:import-ohdear', ['--token' => 'fake-real-account-token', '--dry-run' => $dryRun]);
    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain(
        'uptime_check_settings.http_verb', 'uptime_check_settings.timeout', 'uptime_check_settings.valid_status_codes.0',
        'uptime_check_settings.frequency_value', 'uptime_check_settings.frequency_unit', 'uptime_check_settings.failed_notification_threshold',
        'domain_check_settings.expires_soon_threshold_in_days', 'domain_check_settings.frequency_value', 'domain_check_settings.frequency_unit',
        'sitemap_check_settings.path', 'sitemap_check_settings.speed',
    )->not->toContain('fake-real-account-token', 'fake-health-secret-');
    if ($dryRun) {
        expect(Site::query()->count())->toBe(0);
    } else {
        $site = Site::query()->where('oh_dear_id', 501)->sole();
        expect($site->checks()->pluck('type')->map(fn ($type): string => $type->value)->all())->toEqualCanonicalizing(expectedRealOhDearChecks(excluded: ['uptime', 'domain', 'sitemap']));
    }
})->with(['dry-run' => true, 'real import' => false]);

test('source null and absent settings restore schema and schedule defaults after local deviations', function (bool $omit): void {
    $account = realOhDearAccount();
    $account['monitors'] = [$account['monitors'][0]];
    app(OhDearAccountTransport::class)->fake($account);
    expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fake-real-account-token']))->toBe(0, Artisan::output());
    $site = Site::query()->where('oh_dear_id', 501)->sole();
    foreach (['certificate_health', 'domain'] as $type) {
        $check = $site->checks()->where('type', $type)->sole();
        app(UpdateCheckConfig::class)($check, ['expires_soon_threshold_in_days' => 7]);
        if ($omit) {
            unset($account['monitors'][0][$type.'_check_settings']['expires_soon_threshold_in_days']);
        }
    }
    $performance = $site->checks()->where('type', 'performance')->sole();
    app(UpdateCheckConfig::class)($performance, ['threshold_in_ms' => 999, 'frequency_value' => 2, 'frequency_unit' => 'day', 'failed_notification_threshold' => 9]);
    $account['monitors'][0]['performance_check_settings'] = $omit ? [] : ['threshold_in_ms' => null, 'change_percentage' => null, 'frequency_value' => null, 'frequency_unit' => null, 'failed_notification_threshold' => null];
    app(OhDearAccountTransport::class)->fake($account);
    expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fake-real-account-token']))->toBe(0, Artisan::output());
    expect($site->checks()->where('type', 'certificate_health')->sole()->executionConfig()['expires_soon_threshold_in_days'])->toBe(14)
        ->and($site->checks()->where('type', 'domain')->sole()->executionConfig()['expires_soon_threshold_in_days'])->toBe(30)
        ->and($performance->refresh()->executionConfig()['threshold_in_ms'])->toBe(2000)
        ->and($performance->interval_seconds)->toBe(60)
        ->and($performance->failure_threshold)->toBe(1);
})->with(['null' => false, 'absent' => true]);

test('invalid source check on rerun preserves existing config and enabled state', function (): void {
    $account = realOhDearAccount();
    $account['monitors'] = [$account['monitors'][0]];
    app(OhDearAccountTransport::class)->fake($account);
    expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fake-real-account-token']))->toBe(0);
    $site = Site::query()->where('oh_dear_id', 501)->sole();
    $uptime = $site->checks()->where('type', 'uptime')->sole();
    $before = $uptime->executionConfig();
    $beforeCount = $site->checks()->count();
    $account['monitors'][0]['uptime_check_settings']['timeout'] = 0;
    $account['monitors'][0]['checks'][0]['enabled'] = false;
    app(OhDearAccountTransport::class)->fake($account);
    expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fake-real-account-token']))->toBe(1);
    expect($uptime->refresh()->executionConfig())->toBe($before)
        ->and($uptime->enabled)->toBeTrue()
        ->and($site->checks()->count())->toBe(count(expectedRealOhDearChecks()));
});

test('reports invalid fields in every cron definition without importing any task', function (): void {
    $account = realOhDearAccount();
    $account['monitors'] = [$account['monitors'][0]];
    $spec = json_decode(file_get_contents(base_path('tests/Fixtures/OhDear/import-account.json')), true, flags: JSON_THROW_ON_ERROR);
    $definitions = $spec['cron'];
    $definitions[0]['name'] = '';
    $definitions[0]['grace_time_in_minutes'] = -1;
    $definitions[1]['frequency_in_minutes'] = 0;
    $definitions[1]['grace_time_in_minutes'] = -1;
    $account['monitor_details'][501]['cron'] = $definitions;
    $account['responses']['/api/monitors/501/cron-checks']['data'] = $definitions;
    app(OhDearAccountTransport::class)->fake($account);
    expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fake-real-account-token']))->toBe(1);
    expect(Artisan::output())->toContain('cron_check_settings.definitions.0.name', 'cron_check_settings.definitions.0.grace_time_in_minutes', 'cron_check_settings.definitions.1.frequency_in_minutes', 'cron_check_settings.definitions.1.grace_time_in_minutes')
        ->not->toContain('fake-health-secret-', 'fixture-ping-secret');
    expect(Site::query()->count())->toBe(1)
        ->and(DB::table('checks')->where('type', 'cron')->count())->toBe(0)
        ->and(DB::table('cron_checks')->count())->toBe(0);
});

test('resolves incomplete source frequency pairs to the defined default or minute unit', function (array $frequency, int $seconds): void {
    $account = realOhDearAccount();
    $account['monitors'] = [$account['monitors'][0]];
    $account['monitors'][0]['certificate_health_check_settings'] = $frequency;
    app(OhDearAccountTransport::class)->fake($account);
    expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fake-real-account-token']))->toBe(0, Artisan::output());
    $check = Site::query()->where('oh_dear_id', 501)->sole()->checks()->where('type', 'certificate_health')->sole();
    expect($check->interval_seconds)->toBe($seconds);
})->with([
    'both absent' => [[], 3600], 'both null' => [['frequency_value' => null, 'frequency_unit' => null], 3600],
    'orphan unit' => [['frequency_unit' => 'hour'], 3600], 'null value' => [['frequency_value' => null, 'frequency_unit' => 'day'], 3600],
    'absent unit' => [['frequency_value' => 2], 120], 'null unit' => [['frequency_value' => 2, 'frequency_unit' => null], 120],
]);

test('rejects blank source fields and imports unaffected checks', function (bool $unitOnly, string $blank, bool $dryRun): void {
    $account = realOhDearAccount();
    $account['monitors'] = [$account['monitors'][0]];
    $fields = $unitOnly ? ['frequency_unit'] : ['timeout', 'frequency_value'];
    foreach ($fields as $field) {
        $account['monitors'][0]['uptime_check_settings'][$field] = $blank;
    }
    if (! $unitOnly) {
        $account['monitors'][0]['dns_check_settings']['monitor_main_domain'] = $blank;
    }
    app(OhDearAccountTransport::class)->fake($account);
    $exit = Artisan::call('heptapod:import-ohdear', ['--token' => 'fake-real-account-token', '--dry-run' => $dryRun]);
    $output = Artisan::output();
    expect($exit)->toBe(1, $output);
    foreach ($fields as $field) {
        expect($output)->toContain('uptime_check_settings.'.$field);
    }
    if (! $unitOnly) {
        expect($output)->toContain('dns_check_settings.monitor_main_domain');
    }
    expect($output)->not->toContain('fake-health-secret-', 'fake-real-account-token');
    if ($dryRun) {
        expect(Site::query()->count())->toBe(0);
    } else {
        $site = Site::query()->where('oh_dear_id', 501)->sole();
        $expected = expectedRealOhDearChecks(excluded: ['uptime', ...($unitOnly ? [] : ['dns'])]);
        expect($site->checks()->pluck('type')->map(fn ($type): string => $type->value)->all())->toEqualCanonicalizing($expected);
    }
})->with(['numeric and boolean' => false, 'unit only' => true])->with(['empty' => '', 'whitespace' => ' '])->with(['dry-run' => true, 'import' => false]);

test('diagnoses short imported credentials and keeps the monitor and other checks', function (string $secret, bool $dryRun): void {
    $account = realOhDearAccount();
    $account['monitors'] = [$account['monitors'][0]];
    $account['monitors'][0]['application_health_check_settings']['secret'] = $secret;
    app(OhDearAccountTransport::class)->fake($account);
    $exit = Artisan::call('heptapod:import-ohdear', ['--token' => 'fake-real-account-token', '--dry-run' => $dryRun]);
    $output = Artisan::output();
    expect($exit)->toBe(1, $output);
    expect($output)->toContain('application_health_check_settings.secret', 'Not imported: secret too short (renew it in Oh Dear or after the import)')
        ->not->toContain($secret, 'fake-health-secret-', 'fake-real-account-token');
    if ($dryRun) {
        expect(Site::query()->count())->toBe(0);
    } else {
        $site = Site::query()->where('oh_dear_id', 501)->sole();
        expect($site->checks()->pluck('type')->map(fn ($type): string => $type->value)->all())->toEqualCanonicalizing(expectedRealOhDearChecks(excluded: ['application_health']));
    }
})->with(['three characters' => 'abc', 'trimmed two characters' => '  ab  '])->with(['dry-run' => true, 'import' => false]);

test('imports Lighthouse schema defaults for nulls and reports invalid settings', function (string $case): void {
    $account = realOhDearAccount();
    $account['monitors'] = [$account['monitors'][0]];
    $account['monitors'][0]['lighthouse_check_settings'] = match ($case) {
        'defaults' => array_fill_keys(['continent', 'device_emulation', 'cpu_slowdown_modifier', 'notification_settings', 'http_client_headers', 'frequency_value', 'frequency_unit', 'failed_notification_threshold'], null),
        'invalid' => ['cpu_slowdown_modifier' => 0.5],
    };
    app(OhDearAccountTransport::class)->fake($account);

    $exit = Artisan::call('heptapod:import-ohdear', ['--token' => 'fake-real-account-token']);
    $output = Artisan::output();
    expect($exit)->toBe($case === 'invalid' ? 1 : 0, $output);
    $site = Site::query()->where('oh_dear_id', 501)->sole();
    if ($case === 'invalid') {
        expect($output)->toContain('lighthouse_check_settings.cpu_slowdown_modifier');
        expect($site->checks()->where('type', 'lighthouse')->count())->toBe(0);
    } else {
        $check = $site->checks()->where('type', 'lighthouse')->sole();
        expect($check->executionConfig())->toEqual(['continent' => null, 'device_emulation' => 'mobile', 'cpu_slowdown_modifier' => null, 'notification_settings' => null, 'http_client_headers' => []]);
        expect($check->enabled)->toBeTrue()->and($check->interval_seconds)->toBe(86400)->and($check->failure_threshold)->toBe(1);
    }
    expect($site->checks()->where('type', 'uptime')->count())->toBe(1);
})->with(['defaults', 'invalid']);

test('isolates unexpected monitor exceptions and withholds source messages', function (string $kind, bool $dryRun): void {
    $account = realOhDearAccount();
    $endpoint = '/api/monitors/501/recurring-maintenance-periods';
    $statuses = [];
    $failures = [];
    if ($kind === 'sdk') {
        $account['responses'][$endpoint] = ['message' => 'fixture-remote-secret fake-real-account-token'];
        $statuses[$endpoint] = 500;
    } elseif ($kind === 'transport') {
        $statuses[$endpoint] = 0;
    } else {
        $failures[$endpoint] = new TypeError('fixture-remote-secret fake-real-account-token');
    }
    app(OhDearAccountTransport::class)->fake($account, endpointStatuses: $statuses, endpointFailures: $failures);
    expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fake-real-account-token', '--dry-run' => $dryRun]))->toBe(1);
    $output = Artisan::output();
    expect($output)->toContain('501', '502', '503', '504', '505', 'Failed')
        ->not->toContain('fixture-remote-secret', 'fake-real-account-token', 'fake-health-secret-');
    if ($kind === 'sdk') {
        expect($output)->toContain('OhDearException', 'GET monitors/{id}/recurring-maintenance-periods', '500');
    } elseif ($kind === 'transport') {
        expect($output)->toContain('FatalRequestException', 'GET monitors/{id}/recurring-maintenance-periods', 'no response');
    } else {
        expect($output)->toContain('TypeError', 'Monitor import failed; source details withheld.');
    }
    expect(Site::query()->where('oh_dear_id', 501)->count())->toBe(0);
    expect(Site::query()->whereIn('oh_dear_id', [502, 503, 504, 505])->count())->toBe($dryRun ? 0 : 4);
})->with(['sdk', 'transport', 'unexpected'])->with(['dry-run' => true, 'import' => false]);

test('rejects malformed recurring envelopes without stopping later monitors', function (mixed $payload): void {
    $account = realOhDearAccount();
    $account['responses']['/api/monitors/501/recurring-maintenance-periods'] = $payload;
    app(OhDearAccountTransport::class)->fake($account);
    expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fake-real-account-token']))->toBe(1);
    $output = Artisan::output();
    expect($output)->toContain('InvalidOhDearRecurringResponse', 'Recurring maintenance response must be a list or an object containing a list in data.')
        ->not->toContain('fixture-remote-secret', 'fake-real-account-token');
    expect(Site::query()->where('oh_dear_id', 501)->count())->toBe(0)
        ->and(Site::query()->whereIn('oh_dear_id', [502, 503, 504, 505])->count())->toBe(4);
})->with(['malformed JSON' => ['{'], 'null' => ['null'], 'scalar' => ['"fixture-remote-secret"'], 'empty object' => ['{}'], 'null data' => [['data' => null]], 'object data' => ['{"data":{}}'], 'non-list' => [['data' => ['bad' => []]]], 'scalar row' => [[null]]]);

test('imports a nonempty recurring list in either supported response shape', function (bool $wrapped): void {
    $account = realOhDearAccount();
    $periods = json_decode(file_get_contents(base_path('tests/Fixtures/OhDear/import-account.json')), true, flags: JSON_THROW_ON_ERROR)['recurring'];
    $periods = [$periods[0]];
    $periods[0]['monitor_id'] = 501;
    $account['responses']['/api/monitors/501/recurring-maintenance-periods'] = $wrapped ? ['data' => $periods] : $periods;
    app(OhDearAccountTransport::class)->fake($account);
    expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fake-real-account-token']))->toBe(0);
    $site = Site::query()->where('oh_dear_id', 501)->sole();
    $period = RecurringMaintenancePeriod::query()->where('site_id', $site->id)->sole();
    expect($period->oh_dear_id)->toBe($periods[0]['id'])
        ->and($period->name)->toBe($periods[0]['name'])
        ->and(Site::query()->count())->toBe(5);
})->with(['bare' => false, 'wrapped' => true]);

test('rejects sitemap traversal while preserving valid monitor checks', function (string $path): void {
    $account = realOhDearAccount();
    $account['monitors'] = [$account['monitors'][0]];
    $account['monitors'][0]['sitemap_check_settings']['path'] = $path;
    app(OhDearAccountTransport::class)->fake($account);
    expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fake-real-account-token']))->toBe(1)
        ->and(Artisan::output())->toContain('sitemap_check_settings.path');
    expect(Site::query()->where('oh_dear_id', 501)->sole()->checks()->where('type', 'sitemap')->exists())->toBeFalse();
})->with(['../sitemap.xml', '/a/../sitemap.xml', '//other.example/sitemap.xml', 'http://other.example/sitemap.xml']);

test('imported full-URL whitelist suppresses matching findings after fragment and origin normalization', function (): void {
    // The crawler budgets against microtime(), so the run deadline must be wall-clock based, not the file's fixed date.
    $this->travelTo(CarbonImmutable::createFromTimestamp(microtime(true), 'UTC'));
    config(['certificate_health.ca_file' => base_path('docker/fixtures/tls/ca.crt')]);
    $account = realOhDearAccount();
    $account['monitors'] = [$account['monitors'][0]];
    $account['monitors'][0]['url'] = 'https://fixtures.test/crawl-addon/whitelist';
    $account['monitors'][0]['broken_links_check_settings']['whitelisted_urls'] = [
        'HTTPS://FIXTURES.TEST/demo/#section',
        '*/news/sample?edition=*',
        'https://fixtures.test/news/issue?edition=1#section',
        '*/other-missing?edition=*',
    ];
    $account['monitors'][0]['broken_links_check_settings']['types'] = ['link'];
    $account['monitors'][0]['broken_links_check_settings']['respect_robots'] = false;
    app(OhDearAccountTransport::class)->fake($account);
    expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fake-real-account-token']))->toBe(0, Artisan::output());
    $check = Site::query()->where('oh_dear_id', 501)->sole()->checks()->where('type', 'broken_links')->sole();
    expect($check->site->url)->toBe('https://fixtures.test/crawl-addon/whitelist')
        ->and($check->executionConfig()['whitelisted_urls'])->toBe($account['monitors'][0]['broken_links_check_settings']['whitelisted_urls']);
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)->respondUsing(static fn (FetchRequest $request): FetchResult => match ($request->url) {
        'https://fixtures.test/crawl-addon/whitelist' => new FetchResult(200, '<a href="/demo">Demo</a><a href="/news/sample?edition=1">News</a><a href="/news/issue?edition=1">Issue</a><a href="/cdn-cgi/l/email-protection#hash">Protected</a><a href="/other-missing">Other</a>', ['Content-Type' => ['text/html']]),
        'https://fixtures.test/demo', 'https://fixtures.test/news/sample?edition=1', 'https://fixtures.test/news/issue?edition=1', 'https://fixtures.test/other-missing' => new FetchResult(404, failure: new FetchFailure(FetchFailureKind::HttpStatus, 404)),
        default => throw new LogicException('Unexpected whitelist request'),
    }));
    $context = ClaimedRun::start($check, CarbonImmutable::now());
    $outcome = app(CheckRegistry::class)->get(CheckKey::BrokenLinks)->execute($context);
    expect(app(TransactionalResultRecorder::class)->record(new RecordableRun($check->id, $context->claimToken, $context->startedAt, $context->startedAt->addSecond(), $outcome))->accepted)->toBeTrue();
    expect(DB::table('crawl_findings')->where('crawled_url', 'https://fixtures.test/demo')->exists())->toBeTrue()
        ->and(DB::table('crawl_findings')->where('crawled_url', 'https://fixtures.test/news/sample?edition=1')->exists())->toBeTrue()
        ->and(DB::table('crawl_findings')->where('crawled_url', 'https://fixtures.test/news/issue?edition=1')->exists())->toBeTrue()
        ->and($outcome->metrics->toArray()['broken_internal'])->toBe(1)
        ->and($outcome->evidence->finding_ids)->toHaveCount(1)
        ->and(DB::table('crawl_findings')->pluck('crawled_url')->all())->not->toContain('https://fixtures.test/cdn-cgi/l/email-protection');
});

test('skips a malformed source whitelist entry with a visible notice', function (string $invalid): void {
    $account = realOhDearAccount();
    $account['monitors'] = [$account['monitors'][0]];
    $account['monitors'][0]['broken_links_check_settings']['whitelisted_urls'] = [$invalid, '*://client-a.example/*'];
    app(OhDearAccountTransport::class)->fake($account);
    expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fake-real-account-token']))->toBe(0)
        ->and(Artisan::output())->toContain('Not yet supported, skipped: ', 'broken_links_check_settings.whitelisted_urls.0');
    expect(Site::query()->where('oh_dear_id', 501)->sole()->checks()->where('type', 'broken_links')->sole()->executionConfig()['whitelisted_urls'])->toBe(['*://client-a.example/*']);
})->with(['relative' => 'not-an-absolute-url', 'invalid port' => 'https://client-a.example:abc/x']);

test('restores crawl schema defaults from null or absent source settings on reimport', function (bool $omit): void {
    $account = realOhDearAccount();
    $account['monitors'] = [$account['monitors'][0]];
    app(OhDearAccountTransport::class)->fake($account);
    expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fake-real-account-token']))->toBe(0, Artisan::output());
    $site = Site::query()->where('oh_dear_id', 501)->sole();
    $check = $site->checks()->where('type', 'broken_links')->sole();
    app(UpdateCheckConfig::class)($check, ['max_depth' => 0, 'max_pages' => 1, 'respect_robots' => false, 'types' => ['link']]);
    foreach (['max_depth', 'max_pages', 'respect_robots', 'types'] as $field) {
        if ($omit) {
            unset($account['monitors'][0]['broken_links_check_settings'][$field]);
            foreach ($account['monitors'][0]['checks'] as &$sourceCheck) {
                if ($sourceCheck['type'] === 'broken_links') {
                    unset($sourceCheck['settings'][$field]);
                }
            }
            unset($sourceCheck);
        } else {
            $account['monitors'][0]['broken_links_check_settings'][$field] = null;
        }
    }
    app(OhDearAccountTransport::class)->fake($account);
    expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fake-real-account-token']))->toBe(0, Artisan::output());
    $config = $check->refresh()->executionConfig();
    expect($config['max_depth'])->toBe(5)->and($config['max_pages'])->toBe(2000)
        ->and($config['respect_robots'])->toBeTrue()->and($config['types'])->toContain('og:image')
        ->and($site->checks()->where('type', 'mixed_content')->sole()->enabled)->toBeTrue();
})->with(['null' => false, 'absent' => true]);

test('imports blank source crawl patterns as empty lists and preserves disabled crawl checks', function (): void {
    $account = realOhDearAccount();
    $account['monitors'] = [$account['monitors'][3]];
    app(OhDearAccountTransport::class)->fake($account);
    expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fake-real-account-token']))->toBe(0, Artisan::output());
    foreach ($account['monitors'][0]['checks'] as &$check) {
        if (in_array($check['type'], ['broken_links', 'mixed_content'], true)) {
            $check['enabled'] = false;
        }
    }
    unset($check);
    app(OhDearAccountTransport::class)->fake($account);
    $exit = Artisan::call('heptapod:import-ohdear', ['--token' => 'fake-real-account-token']);
    $output = Artisan::output();
    expect($exit)->toBe(0, $output);
    $site = Site::query()->where('oh_dear_id', 504)->sole();
    $broken = $site->checks()->where('type', 'broken_links')->sole();
    expect($broken->enabled)->toBeFalse()->and($broken->executionConfig()['respect_robots'])->toBeFalse()
        ->and($broken->executionConfig()['do_not_crawl_urls'])->toBe([])
        ->and($broken->executionConfig()['force_crawl_urls'])->toBe([])
        ->and($broken->executionConfig()['types'])->toContain('og:image')
        ->and($site->checks()->where('type', 'mixed_content')->sole()->enabled)->toBeFalse();
    expect($output)->toContain('broken_links_check_settings.crawler_speed');
});

test('reports non-default crawl speed as an unsupported setting without silently mapping it', function (): void {
    $account = realOhDearAccount();
    $account['monitors'] = [$account['monitors'][0]];
    foreach ($account['monitors'][0]['checks'] as &$sourceCheck) {
        if ($sourceCheck['type'] === 'broken_links') {
            $sourceCheck['settings']['crawler_speed'] = 'slow';
        }
    }
    unset($sourceCheck);
    app(OhDearAccountTransport::class)->fake($account);
    expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fake-real-account-token']))->toBe(0)
        ->and(Artisan::output())->toContain('broken_links_check_settings.crawler_speed');
    expect(Site::query()->where('oh_dear_id', 501)->sole()->checks()->where('type', 'broken_links')->sole()->executionConfig())->not->toHaveKey('crawler_speed');
});

test('root crawl settings and site headers take precedence over nested source check settings', function (): void {
    $account = realOhDearAccount();
    $account['monitors'] = [$account['monitors'][3]];
    $account['monitors'][0]['broken_links_check_settings']['respect_robots'] = true;
    $account['monitors'][0]['crawler_headers'] = [['name' => 'X-Canonical', 'value' => 'canonical-crawler-secret']];
    foreach ($account['monitors'][0]['checks'] as &$check) {
        if ($check['type'] === 'broken_links') {
            $check['settings']['crawler_headers'] = [['name' => 'X-Nested', 'value' => 'ignored-nested-secret']];
        }
    }
    unset($check);
    app(OhDearAccountTransport::class)->fake($account);
    $exit = Artisan::call('heptapod:import-ohdear', ['--token' => 'fake-real-account-token']);
    $output = Artisan::output();
    expect($exit)->toBe(0, $output);
    $site = Site::query()->where('oh_dear_id', 504)->sole();
    expect($site->checks()->where('type', 'broken_links')->sole()->executionConfig()['respect_robots'])->toBeTrue()
        ->and($site->crawler_headers)->toBe([['name' => 'X-Canonical', 'value' => 'canonical-crawler-secret']])
        ->and($output)->toContain('broken_links_check_settings.crawler_speed');
    expect($output)->not->toContain('canonical-crawler-secret');
    expect($output)->not->toContain('ignored-nested-secret');
});

test('rejects undeclared source settings outside production without exposing their values', function (string $type, string $field): void {
    $account = realOhDearAccount();
    $account['monitors'] = [$account['monitors'][0]];
    $account['monitors'][0][$type.'_check_settings'][$field] = 'fake-unknown-option-secret';
    app(OhDearAccountTransport::class)->fake($account);

    try {
        Artisan::call('heptapod:import-ohdear', ['--token' => 'fake-real-account-token']);
        $this->fail('Expected an undeclared import notice.');
    } catch (UndeclaredImportNotice $exception) {
        expect($exception->getMessage())->toBe('Undeclared Oh Dear import notice: setting:'.$type.'.'.$field);
    }
})->with([
    'sitemap' => ['sitemap', 'unsupported_option'],
    'crawl' => ['broken_links', 'unsupported_option'],
    'lighthouse' => ['lighthouse', 'unsupported_probe_setting'],
]);

test('reports undeclared source settings with a generic notice and completes imports in production', function (): void {
    $account = realOhDearAccount();
    $account['monitors'] = [$account['monitors'][0]];
    $account['monitors'][0]['sitemap_check_settings']['unsupported_option'] = 'fake-unknown-option-secret';
    app()->instance('env', 'production');
    Exceptions::fake();
    app(OhDearAccountTransport::class)->fake($account);

    $plan = app(ImportOhDearAccount::class)('fake-real-account-token', false);

    expect($plan->failed())->toBeFalse()
        ->and($plan->rows[0]->notices)->toContain('import.notice.other')
        ->and(__('import.notice.other'))->toBe('Another Oh Dear setting: not imported');
    Exceptions::assertReported(fn (UndeclaredImportNotice $exception): bool => $exception->getMessage() === 'Undeclared Oh Dear import notice: setting:sitemap.unsupported_option');
    $site = Site::query()->where('oh_dear_id', 501)->sole();
    expect($site->checks()->where('type', 'sitemap')->sole()->executionConfig())->not->toHaveKey('unsupported_option');
    app(OhDearAccountTransport::class)->fake($account);
    expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fake-real-account-token']))->toBe(0);
    expect(Artisan::output())->toContain('sitemap_check_settings.unsupported_option')->not->toContain('fake-unknown-option-secret');
});

test('imports blank uptime alert timing as inherited timing', function (string $blank): void {
    $account = realOhDearAccount();
    $account['monitors'] = [$account['monitors'][0]];
    $account['monitors'][0]['uptime_check_settings']['failed_notification_threshold'] = $blank;
    app(OhDearAccountTransport::class)->fake($account);

    $exit = Artisan::call('heptapod:import-ohdear', ['--token' => 'fake-real-account-token']);
    $output = Artisan::output();
    expect($exit)->toBe(0, $output)
        ->and($output)->toContain(__('alert_sensitivity.import_summary', ['inherited' => 1, 'overridden' => 0]));

    $site = Site::query()->where('oh_dear_id', 501)->sole();
    expect($site->alert_after_minutes)->toBeNull()
        ->and($site->alert_recovery_checks)->toBeNull();
})->with(['empty' => '', 'whitespace' => ' ']);
