<?php

use Carbon\CarbonImmutable;
use Heptapod\Actions\DeleteMaintenancePeriod;
use Heptapod\Actions\DeleteRecurringMaintenancePeriod;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\RecurringMaintenancePeriod;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Settings\InstanceSettings;
use Heptapod\Testing\OhDearAccountTransport;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Saloon\Http\Faking\MockClient;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05T12:00:00Z'));
    $this->account = json_decode(file_get_contents(base_path('tests/Fixtures/OhDear/import-account.json')), true, flags: JSON_THROW_ON_ERROR);
    Site::factory()->create(['url' => 'https://duplicate.example.com']);
});

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('dry-run plans every page and reports unsupported fields and duplicates without writes or secrets', function (): void {
    app(OhDearAccountTransport::class)->fake($this->account);
    $before = DB::table('activity_log')->count();

    $exit = Artisan::call('heptapod:import-ohdear', ['--token' => 'fixture-token-secret', '--dry-run' => true]);
    $output = Artisan::output();
    expect($exit)->toBe(0, $output);
    expect($output)->toContain('plan + config validation', '101', '102', '103', 'Create', 'Duplicate URL', 'uptime_check_settings.location', 'Cron definitions: 2')
        ->not->toContain('fixture-token-secret', 'fixture-uptime-secret', 'fixture-health-secret', 'fixture-health-header-secret', 'fixture-crawler-secret', 'fixture-ping-secret');
    expect(Site::query()->count())->toBe(1)
        ->and(DB::table('checks')->count())->toBe(0)
        ->and(DB::table('cron_checks')->count())->toBe(0)
        ->and(DB::table('maintenance_periods')->count())->toBe(0)
        ->and(DB::table('recurring_maintenance_periods')->count())->toBe(0)
        ->and(DB::table('activity_log')->count())->toBe($before);
});

test('refreshes client-scope reader statistics after imports but does not analyze on dry runs', function (bool $dryRun): void {
    $site = Site::factory()->create();
    $site->attachTag('Existing tag', 'sites');
    User::factory()->create()->clients()->attach($site->client_id);
    $analyzeStatements = 0;
    DB::listen(static function (QueryExecuted $query) use (&$analyzeStatements): void {
        if (str_starts_with($query->sql, 'ANALYZE ')) {
            $analyzeStatements++;
        }
    });
    app(OhDearAccountTransport::class)->fake($this->account);

    expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fixture-token-secret', '--dry-run' => $dryRun]))->toBe(0);
    expect($analyzeStatements)->toBe($dryRun ? 0 : 1);

    if ($dryRun) {
        return;
    }
    foreach (['sites', 'tags', 'taggables', 'client_user'] as $table) {
        expect((int) DB::table('pg_class')->where('relname', $table)->value('reltuples'))->toBe(DB::table($table)->count(), $table);
    }
})->with(['dry-run' => true, 'real import' => false]);

test('imports settings and definitions through actions and reruns update stable local identities', function (): void {
    app(OhDearAccountTransport::class)->fake($this->account);
    $exit = Artisan::call('heptapod:import-ohdear', ['--token' => 'fixture-token-secret']);
    $output = Artisan::output();
    expect($exit)->toBe(0, $output);
    expect($output)->toContain('101', 'Create')->not->toContain('fixture-token-secret', 'fixture-uptime-secret', 'fixture-health-secret', 'fixture-health-header-secret', 'fixture-crawler-secret', 'fixture-ping-secret');
    $site = Site::query()->where('oh_dear_id', 101)->firstOrFail();
    $uptime = $site->checks()->getQuery()->where('type', 'uptime')->firstOrFail();
    $health = $site->checks()->getQuery()->where('type', 'application_health')->firstOrFail();
    expect($health->enabled)->toBeTrue()
        ->and($health->executionConfig()['result_url'])->toBe('https://import.example.com/health')
        ->and($health->executionConfig()['secret'])->toBe('fixture-health-secret')
        ->and($health->executionConfig()['headers'][0]['value'])->toBe('fixture-health-header-secret');
    expect(DB::table('checks')->where('id', $health->id)->value('config_secrets'))->not->toContain('fixture-health-secret', 'fixture-health-header-secret');
    expect($site->getAttribute('friendly_name'))->toBe('Storefront')
        ->and($site->tags->pluck('name')->sort()->values()->all())->toBe(['production', 'shop'])
        ->and($site->crawler_headers[0]['value'])->toBe('fixture-crawler-secret')
        ->and($uptime->interval_seconds)->toBe(300)
        ->and($uptime->failure_threshold)->toBe(2)
        ->and($site->alert_after_minutes)->toBe(3)
        ->and($site->alert_recovery_checks)->toBe(3)
        ->and($uptime->executionConfig()['http_client_headers'][0]['value'])->toBe('fixture-uptime-secret')
        ->and($uptime->executionConfig()['http_client_headers'][2]['value'])->toBe('second')
        ->and($uptime->executionConfig())->not->toHaveKey('location')
        ->and($site->checks()->getQuery()->where('type', 'certificate_health')->firstOrFail()->enabled)->toBeFalse()
        ->and($site->checks()->getQuery()->where('type', 'dns')->firstOrFail()->executionConfig()['extra_cnames'])->toBe(['www.import.example.com']);
    expect(DB::table('sites')->where('id', $site->id)->value('crawler_headers'))->not->toContain('fixture-crawler-secret');
    expect(DB::table('checks')->where('id', $uptime->id)->value('config_secrets'))->not->toContain('fixture-uptime-secret');
    $task = DB::table('cron_checks')->where('oh_dear_id', 201)->first();
    expect($task->server_timezone)->toBe('Europe/Berlin')->and($task->uuid)->not->toBe($this->account['cron'][0]['uuid']);
    $period = DB::table('maintenance_periods')->where('oh_dear_id', 301)->first();
    expect($period->starts_at)->toStartWith('2030-01-10 11:00:00')
        ->and(DB::table('recurring_maintenance_periods')->where('oh_dear_id', 401)->value('timezone'))->toBe('Europe/Berlin');

    $generatedIds = DB::table('maintenance_periods')->orderBy('id')->pluck('id')->all();
    app(OhDearAccountTransport::class)->fake($this->account);
    expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fixture-token-secret']))->toBe(0);
    expect(DB::table('cron_checks')->where('oh_dear_id', 201)->value('next_due_at'))->toBe($task->next_due_at)
        ->and(DB::table('maintenance_periods')->orderBy('id')->pluck('id')->all())->toBe($generatedIds);

    $this->account['monitors'][0]['url'] = 'https://renamed.example.com';
    $this->account['monitors'][0]['tags'] = ['updated'];
    $this->account['monitors'][0]['performance_check_settings']['threshold_in_ms'] = 900;
    $this->account['cron'][0]['server_timezone'] = 'America/New_York';
    $this->account['cron'][0]['name'] = 'renamed-backup';
    $this->account['maintenance'][0]['ends_at'] = '2030-01-10 14:00:00';
    $this->account['recurring'][0]['days_of_week'] = [4];
    app(OhDearAccountTransport::class)->fake($this->account);
    expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fixture-token-secret']))->toBe(0);
    expect(Artisan::output())->toContain('Update');
    expect($site->refresh()->url)->toBe('https://renamed.example.com')
        ->and($site->tags->pluck('name')->all())->toBe(['updated'])
        ->and($site->checks()->getQuery()->where('type', 'performance')->firstOrFail()->executionConfig()['threshold_in_ms'])->toBe(900)
        ->and($site->checks()->getQuery()->where('type', 'application_health')->firstOrFail()->id)->toBe($health->id)
        ->and(Site::query()->count())->toBe(2)
        ->and(DB::table('cron_checks')->count())->toBe(2)
        ->and(DB::table('cron_checks')->where('oh_dear_id', 201)->value('uuid'))->toBe($task->uuid)
        ->and(DB::table('cron_checks')->where('oh_dear_id', 201)->value('server_timezone'))->toBe('America/New_York')
        ->and(DB::table('maintenance_periods')->whereNull('recurring_maintenance_period_id')->count())->toBe(1)
        ->and(DB::table('maintenance_periods')->where('oh_dear_id', 301)->value('id'))->toBe($period->id)
        ->and(DB::table('recurring_maintenance_periods')->count())->toBe(1);
});

test('application health missing required settings reports setup without creating a check', function (string $field, string $locale, string $message): void {
    unset($this->account['monitors'][0]['application_health_check_settings'][$field]);
    app()->setLocale($locale);
    foreach ([true, false] as $dryRun) {
        app(OhDearAccountTransport::class)->fake($this->account);
        expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fixture-token-secret', '--dry-run' => $dryRun]))->toBe(0);
        expect(Artisan::output())->toContain($message)->not->toContain('fixture-health-secret', 'fixture-health-header-secret');
        expect(DB::table('checks')->where('type', 'application_health')->count())->toBe(0);
    }
    expect(Site::query()->where('oh_dear_id', 101)->count())->toBe(1)
        ->and(DB::table('checks')->where('type', 'uptime')->count())->toBe(1);
})->with([
    'missing result URL' => ['result_url', 'en', 'Not imported: setup required: application_health'],
    'missing secret in German' => ['secret', 'de', 'Nicht importiert: Einrichtung erforderlich: application_health'],
]);

test('malformed application health settings fail planning and import without exposing secrets', function (string $field, mixed $value, string $invalidPath): void {
    $this->account['monitors'][0]['application_health_check_settings'][$field] = $value;
    foreach ([true, false] as $dryRun) {
        app(OhDearAccountTransport::class)->fake($this->account);
        expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fixture-token-secret', '--dry-run' => $dryRun]))->toBe(1);
        expect(Artisan::output())->toContain('Partial', $invalidPath)
            ->not->toContain('setup required', 'fixture-token-secret', 'fixture-health-secret', 'fixture-health-header-secret');
        expect(Site::query()->where('oh_dear_id', 101)->count())->toBe($dryRun ? 0 : 1)
            ->and(DB::table('checks')->where('type', 'application_health')->count())->toBe(0);
    }
})->with([
    'bad URL' => ['result_url', 'invalid-url', 'application_health_check_settings.result_url'],
    'secret header injection' => ['secret', "fixture-health-secret\r\n", 'application_health_check_settings.secret'],
    'secret wrong type' => ['secret', [], 'application_health_check_settings.secret'],
    'malformed header object' => ['headers', [['value' => 'fixture-health-header-secret']], 'application_health_check_settings.headers.0.name'],
]);

test('invalid supported config fails planning per check without exposing values', function (string $field, mixed $value): void {
    $this->account['monitors'][0]['uptime_check_settings'][$field] = $value;
    app(OhDearAccountTransport::class)->fake($this->account);
    expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fixture-token-secret', '--dry-run' => true]))->toBe(1);
    expect(Artisan::output())->toContain('Partial', 'Invalid fields')->not->toContain('fixture-token-secret', 'fixture-uptime-secret');
    expect(Site::query()->count())->toBe(1)->and(DB::table('cron_checks')->count())->toBe(0);
})->with([['valid_status_codes', ['invalid']], ['frequency_unit', 'week']]);

test('invalid report email is rejected before creating a monitor', function (): void {
    $this->account['monitors'][0]['send_report_to_emails'] = ['invalid'];
    app(OhDearAccountTransport::class)->fake($this->account);
    expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fixture-token-secret']))->toBe(1);
    expect(Artisan::output())->toContain('send_report_to_emails.0');
    expect(Site::query()->count())->toBe(1)->and(DB::table('cron_checks')->count())->toBe(0)->and(DB::table('maintenance_periods')->count())->toBe(0);
});

test('matching future recurring windows are covered while manual and past periods remain one-offs', function (): void {
    $this->travelTo(CarbonImmutable::parse('2030-01-01 00:00:00', 'UTC'));
    $this->account['maintenance'][] = ['id' => 302, 'monitor_id' => 101, 'name' => 'Generated backup', 'starts_at' => '2030-01-07 02:00:00', 'ends_at' => '2030-01-07 03:00:00'];
    $this->account['maintenance'][] = ['id' => 303, 'monitor_id' => 101, 'name' => 'History', 'starts_at' => '2029-12-31 02:00:00', 'ends_at' => '2029-12-31 03:00:00'];
    app(OhDearAccountTransport::class)->fake($this->account);
    expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fixture-token-secret']))->toBe(0);
    expect(Artisan::output())->toContain('Covered by recurring: 1');
    expect(DB::table('maintenance_periods')->where('oh_dear_id', 302)->count())->toBe(0)
        ->and(DB::table('maintenance_periods')->whereNotNull('recurring_maintenance_period_id')->where('starts_at', '2030-01-07 01:00:00+00')->count())->toBe(1)
        ->and(DB::table('maintenance_periods')->whereNull('recurring_maintenance_period_id')->count())->toBe(2);
});

test('missing team timezone uses the instance timezone for recurring import', function (): void {
    unset($this->account['me']['teams'][0]['timezone']);
    $instance = app(InstanceSettings::class);
    $instance->timezone = 'Asia/Kolkata';
    $instance->save();
    app(OhDearAccountTransport::class)->fake($this->account);
    expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fixture-token-secret']))->toBe(0);
    expect(DB::table('recurring_maintenance_periods')->where('oh_dear_id', 401)->value('timezone'))->toBe('Asia/Kolkata');
});

test('disabled cron definitions import into a disabled optional check and retain identity', function (): void {
    $this->account['monitors'][0]['checks'][4]['enabled'] = false;
    app(OhDearAccountTransport::class)->fake($this->account);
    expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fixture-token-secret']))->toBe(0);
    $site = Site::query()->where('oh_dear_id', 101)->firstOrFail();
    $check = $site->checks()->getQuery()->where('type', 'cron')->firstOrFail();
    expect($check->enabled)->toBeFalse();
    expect(DB::table('cron_checks')->where('check_id', $check->id)->count())->toBe(2);
    $identities = DB::table('cron_checks')->where('check_id', $check->id)->orderBy('oh_dear_id')->pluck('uuid', 'oh_dear_id')->all();
    app(OhDearAccountTransport::class)->fake($this->account);
    expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fixture-token-secret']))->toBe(0);
    expect(DB::table('cron_checks')->where('check_id', $check->id)->orderBy('oh_dear_id')->pluck('uuid', 'oh_dear_id')->all())->toBe($identities);
    expect($check->refresh()->enabled)->toBeFalse();
});

test('cron without source definitions reports setup without creating an empty check', function (): void {
    $this->account['cron'] = [];
    foreach ([false, true] as $dryRun) {
        app(OhDearAccountTransport::class)->fake($this->account);
        expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fixture-token-secret', '--dry-run' => $dryRun]))->toBe(0);
        expect(DB::table('checks')->where('type', 'cron')->count())->toBe(0);
        expect(Artisan::output())->toContain('Not imported: setup required: cron', 'Cron definitions: 0');
    }
    expect(Site::query()->where('oh_dear_id', 101)->count())->toBe(1);
    expect(DB::table('cron_checks')->count())->toBe(0);
});

test('optional checks absent from the source do not create placeholders or require a cron row', function (): void {
    $this->account['monitors'][0]['checks'] = array_values(array_filter($this->account['monitors'][0]['checks'], fn (array $check): bool => ! in_array($check['type'], ['cron', 'application_health'], true)));
    $this->account['monitors'][0]['application_health_check_settings'] = [];
    $this->account['cron'] = [];
    app(OhDearAccountTransport::class)->fake($this->account);
    expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fixture-token-secret']))->toBe(0);
    expect(Site::query()->where('oh_dear_id', 101)->count())->toBe(1);
    expect(DB::table('checks')->whereIn('type', ['cron', 'application_health'])->count())->toBe(0);
    expect(Artisan::output())->not->toContain('setup required');
});

test('invalid cron definitions fail planning through the shared creation validator', function (): void {
    $this->account['cron'][0]['server_timezone'] = 'Not/AZone';
    app(OhDearAccountTransport::class)->fake($this->account);
    expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fixture-token-secret', '--dry-run' => true]))->toBe(1);
    expect(Artisan::output())->toContain('server_timezone');
    expect(Site::query()->count())->toBe(1)->and(DB::table('cron_checks')->count())->toBe(0);
});

test('remote failures are nonzero and never print response secrets', function (int $status): void {
    app(OhDearAccountTransport::class)->fake($this->account, $status);
    expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fixture-token-secret']))->toBe(1);
    expect(Artisan::output())->toContain($status === 0 ? 'connection failed' : 'HTTP '.$status)->not->toContain('fixture-token-secret', 'fixture-remote-secret');
    expect(Site::query()->count())->toBe(1);
})->with([401, 422, 0]);

test('reimport narrows a mapped one-off into a recurring slot without retaining its old end', function (): void {
    $this->travelTo(CarbonImmutable::parse('2030-01-01 00:00:00', 'UTC'));
    $this->account['maintenance'] = [['id' => 301, 'monitor_id' => 101, 'name' => 'Backup', 'starts_at' => '2030-01-07 02:00:00', 'ends_at' => '2030-01-07 04:00:00']];
    app(OhDearAccountTransport::class)->fake($this->account);
    expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fixture-token-secret']))->toBe(0);
    expect(DB::table('maintenance_periods')->where('oh_dear_id', 301)->value('ends_at'))->toStartWith('2030-01-07 03:00:00');

    $this->account['maintenance'][0]['ends_at'] = '2030-01-07 03:00:00';
    app(OhDearAccountTransport::class)->fake($this->account);
    expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fixture-token-secret']))->toBe(0);
    expect(DB::table('maintenance_periods')->where('starts_at', '2030-01-07 01:00:00+00')->where('ends_at', '2030-01-07 03:00:00+00')->count())->toBe(0);
    expect(DB::table('maintenance_periods')->where('starts_at', '2030-01-07 01:00:00+00')->count())->toBe(1);
});

test('covered source identity survives becoming past and re-evaluates edited source windows', function (): void {
    $this->travelTo(CarbonImmutable::parse('2030-01-01 00:00:00', 'UTC'));
    $this->account['maintenance'] = [['id' => 301, 'monitor_id' => 101, 'name' => 'Backup', 'starts_at' => '2030-01-07 02:00:00', 'ends_at' => '2030-01-07 03:00:00']];
    app(OhDearAccountTransport::class)->fake($this->account);
    expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fixture-token-secret']))->toBe(0);
    $this->travelTo(CarbonImmutable::parse('2030-01-07 04:00:00', 'UTC'));
    app(OhDearAccountTransport::class)->fake($this->account);
    expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fixture-token-secret']))->toBe(0);
    expect(DB::table('maintenance_periods')->where('starts_at', '2030-01-07 01:00:00+00')->count())->toBe(1);

    $this->account['maintenance'][0]['ends_at'] = '2030-01-07 04:00:00';
    app(OhDearAccountTransport::class)->fake($this->account);
    expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fixture-token-secret']))->toBe(0);
    expect(DB::table('maintenance_periods')->where('oh_dear_id', 301)->value('ends_at'))->toStartWith('2030-01-07 03:00:00');
    app(OhDearAccountTransport::class)->fake($this->account);
    expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fixture-token-secret']))->toBe(0);
    expect(DB::table('maintenance_periods')->where('starts_at', '2030-01-07 01:00:00+00')->count())->toBe(2);
});

test('covered source periods missing a local window are restored only while not ended', function (string $rerunAt, bool $restore, bool $active): void {
    $this->travelTo(CarbonImmutable::parse('2030-01-01 00:00:00', 'UTC'));
    $this->account['maintenance'] = [['id' => 301, 'monitor_id' => 101, 'name' => 'Backup', 'starts_at' => '2030-01-07 02:00:00', 'ends_at' => '2030-01-07 03:00:00']];
    app(OhDearAccountTransport::class)->fake($this->account);
    expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fixture-token-secret']))->toBe(0);
    $site = Site::query()->where('oh_dear_id', 101)->firstOrFail();
    expect(DB::table('ohdear_import_coverages')->where('oh_dear_id', 301)->count())->toBe(1);
    app(DeleteRecurringMaintenancePeriod::class)(RecurringMaintenancePeriod::query()->where('oh_dear_id', 401)->firstOrFail());
    expect(DB::table('maintenance_periods')->where('site_id', $site->id)->count())->toBe(0);
    $this->account['recurring'] = [];

    $this->travelTo(CarbonImmutable::parse($rerunAt, 'UTC'));
    app(OhDearAccountTransport::class)->fake($this->account);
    expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fixture-token-secret']))->toBe(0);
    expect(DB::table('maintenance_periods')->where('oh_dear_id', 301)->count())->toBe($restore ? 1 : 0);
    expect(MaintenancePeriod::activeForSite($site->id, CarbonImmutable::now())->getQuery()->exists())->toBe($active);

    if ($restore) {
        $period = MaintenancePeriod::query()->where('oh_dear_id', 301)->firstOrFail();
        app(OhDearAccountTransport::class)->fake($this->account);
        expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fixture-token-secret']))->toBe(0);
        expect(MaintenancePeriod::query()->where('oh_dear_id', 301)->firstOrFail()->id)->toBe($period->id);
        app(DeleteMaintenancePeriod::class)($period);
        $this->travelTo(CarbonImmutable::parse('2030-01-07 02:30:00', 'UTC'));
        app(OhDearAccountTransport::class)->fake($this->account);
        expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fixture-token-secret']))->toBe(0);
        expect(DB::table('maintenance_periods')->where('oh_dear_id', 301)->count())->toBe(0);
    }
})->with([
    'running' => ['2030-01-07 01:30:00', true, true],
    'future' => ['2030-01-07 00:30:00', true, false],
    'ended' => ['2030-01-07 02:30:00', false, false],
    'exact end reconciles but is inactive' => ['2030-01-07 02:00:00', true, false],
]);

test('Berlin fold source periods deduplicate only against the same UTC occurrence', function (string $offset, string $sourceStart, string $sourceEnd, bool $oneOff): void {
    $this->travelTo(CarbonImmutable::parse('2030-10-01 00:00:00', 'UTC'));
    $this->account['maintenance'] = [['id' => 301, 'monitor_id' => 101, 'name' => 'Fold backup', 'starts_at' => '2030-10-27T02:10:00'.$offset, 'ends_at' => '2030-10-27T02:40:00'.$offset]];
    $this->account['recurring'][0]['days_of_week'] = [0];
    $this->account['recurring'][0]['start_time'] = '02:10';
    $this->account['recurring'][0]['end_time'] = '02:40';
    app(OhDearAccountTransport::class)->fake($this->account);
    expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fixture-token-secret']))->toBe(0);
    $site = Site::query()->where('oh_dear_id', 101)->firstOrFail();
    expect(DB::table('maintenance_periods')->where('site_id', $site->id)->whereNotNull('recurring_maintenance_period_id')
        ->where('starts_at', '2030-10-27 00:10:00+00')->where('ends_at', '2030-10-27 00:40:00+00')->count())->toBe(1);
    expect(DB::table('maintenance_periods')->where('oh_dear_id', 301)->count())->toBe($oneOff ? 1 : 0);
    expect(DB::table('ohdear_import_coverages')->where('oh_dear_id', 301)->count())->toBe($oneOff ? 0 : 1);
    $window = DB::table('maintenance_periods')->where('site_id', $site->id)->where('starts_at', $sourceStart)->where('ends_at', $sourceEnd)->first();
    expect($window)->not->toBeNull();

    $this->travelTo(CarbonImmutable::parse('2030-10-27 01:20:00', 'UTC'));
    app(OhDearAccountTransport::class)->fake($this->account);
    expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fixture-token-secret']))->toBe(0);
    expect(MaintenancePeriod::activeForSite($site->id, CarbonImmutable::now())->getQuery()->exists())->toBe($oneOff);
    expect(DB::table('maintenance_periods')->where('site_id', $site->id)->where('starts_at', $sourceStart)->where('ends_at', $sourceEnd)->pluck('id')->all())->toBe([$window->id]);
})->with([
    'first occurrence matches recurrence' => ['+02:00', '2030-10-27 00:10:00+00', '2030-10-27 00:40:00+00', false],
    'second occurrence stays a one-off' => ['+01:00', '2030-10-27 01:10:00+00', '2030-10-27 01:40:00+00', true],
]);

test('metadata-only cron reimport inside grace preserves the unpinged deadline', function (): void {
    $this->travelTo(CarbonImmutable::parse('2030-01-01 00:00:00', 'UTC'));
    app(OhDearAccountTransport::class)->fake($this->account);
    expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fixture-token-secret']))->toBe(0);
    $deadline = DB::table('cron_checks')->where('oh_dear_id', 201)->value('next_due_at');
    expect($deadline)->toStartWith('2030-01-01 01:00:00');

    $this->travelTo(CarbonImmutable::parse('2030-01-01 01:01:00', 'UTC'));
    $this->account['cron'][0]['name'] = 'renamed-backup';
    $this->account['cron'][0]['description'] = 'New description';
    app(OhDearAccountTransport::class)->fake($this->account);
    expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fixture-token-secret']))->toBe(0);
    expect(DB::table('cron_checks')->where('oh_dear_id', 201)->value('next_due_at'))->toBe($deadline);
    expect(DB::table('cron_checks')->where('oh_dear_id', 201)->value('name'))->toBe('renamed-backup');
});

test('next-page failure reports committed operations and earlier skips before incomplete import', function (): void {
    $this->account['monitors'][] = ['id' => 104, 'team_id' => 48, 'type' => 'tcp', 'url' => 'next.example.com', 'checks' => []];
    app(OhDearAccountTransport::class)->fake($this->account, 429, 4);
    expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fixture-token-secret']))->toBe(1);
    $output = Artisan::output();
    expect($output)->toContain('101', 'Create', '102', 'tcp', '103', 'Duplicate URL', 'Import incomplete', 'HTTP 429')
        ->not->toContain('fixture-token-secret', 'fixture-remote-secret', 'fixture-uptime-secret', 'fixture-crawler-secret');
    expect(strpos($output, 'Duplicate URL'))->toBeLessThan(strpos($output, 'Import incomplete'));
    expect(Site::query()->where('oh_dear_id', 101)->count())->toBe(1);
});

test('late cron identity conflict rolls back this monitor while a previous monitor remains committed', function (): void {
    $second = $this->account['monitors'][0];
    $second['id'] = 104;
    $second['url'] = 'https://second.example.com';
    $firstTask = $this->account['cron'][0];
    $firstTask['id'] = 203;
    $this->account['monitors'] = [$this->account['monitors'][0], $second];
    $this->account['monitor_details'][104] = ['cron' => [$firstTask, $this->account['cron'][0]], 'maintenance' => [], 'recurring' => []];
    app(OhDearAccountTransport::class)->fake($this->account);

    expect(Artisan::call('heptapod:import-ohdear', ['--token' => 'fixture-token-secret']))->toBe(1);
    expect(Artisan::output())->toContain('104', 'Failed', 'LogicException', 'Monitor import failed; source details withheld.')
        ->not->toContain('fixture-token-secret');
    expect(Site::query()->where('oh_dear_id', 104)->count())->toBe(0);
    expect(DB::table('cron_checks')->where('oh_dear_id', 203)->count())->toBe(0);
    expect(Site::query()->where('oh_dear_id', 101)->count())->toBe(1);
    expect(DB::table('cron_checks')->whereIn('oh_dear_id', [201, 202])->count())->toBe(2);
    expect(DB::table('maintenance_periods')->where('oh_dear_id', 301)->count())->toBe(1);
});
