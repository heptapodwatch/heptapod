<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Actions\EmitMaintenanceCatchUps;
use Heptapod\Addons\Channels\RenderedAlert;
use Heptapod\Addons\Uptime\UptimeMetrics;
use Heptapod\Checks\Recording\RecordableRun;
use Heptapod\Checks\Recording\ResultRecorder;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckOutcome;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\Notifications\ChannelRegistry;
use Heptapod\Contracts\RunResult;
use Heptapod\Enums\AlertReminders;
use Heptapod\Events\Notifications\NotificationMessage;
use Heptapod\Jobs\DispatchNotifications;
use Heptapod\Models\Check;
use Heptapod\Models\CheckSnooze;
use Heptapod\Models\Client;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\Site;
use Heptapod\Settings\InstanceSettings;
use Heptapod\Testing\ClaimedRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../Support/NotificationChannels.php';

beforeEach(function (): void {
    $alertSettings = app(InstanceSettings::class);
    // Maintenance catch-up, not alert sensitivity: the former team behaviour.
    $alertSettings->alert_after_minutes = 10;
    $alertSettings->alert_confirms_other_checks = false;
    $alertSettings->alert_reminders = AlertReminders::Standard;
    $alertSettings->save();
});

function maintenanceDestination(): NotificationDestination
{
    return NotificationDestination::factory()->create(['channel' => 'webhook',
        'config' => app(ChannelRegistry::class)->get('webhook')->configSchema()->validateConfig([
            'url' => 'https://receiver.test/maintenance', 'secret' => 'secret-for-maintenance',
        ])]);
}

function maintenanceRule(NotificationDestination $destination, string $type): void
{
    notificationRule($destination, $type);
}

it('attributes a failed run to the catch-up-enabled overlapping window', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04T00:10:00Z'));
    config(['notifications.renotify_intervals_seconds' => null]);
    Http::preventStrayRequests();
    Http::fake(['https://receiver.test/maintenance' => Http::response([], 200)]);
    $check = Check::factory()->create(['failure_threshold' => 1]);
    MaintenancePeriod::factory()->create(['site_id' => $check->site_id, 'starts_at' => now()->subMinutes(5),
        'ends_at' => now()->addMinutes(10), 'catch_up_at_end' => false]);
    $catchUp = MaintenancePeriod::factory()->create(['site_id' => $check->site_id, 'starts_at' => now()->subMinutes(5),
        'ends_at' => now()->addMinutes(15), 'catch_up_at_end' => true]);
    $at = CarbonImmutable::now('UTC');
    $claim = ClaimedRun::start($check, $at->subSecond());
    $timings = array_fill_keys(['namelookup_time', 'connect_time', 'appconnect_time', 'starttransfer_time',
        'pretransfer_time', 'redirect_time', 'total_time', 'header_size', 'size_download', 'speed_download'], null);
    app(ResultRecorder::class)->record(new RecordableRun($check->id, $claim->claimToken, $claim->startedAt, $at,
        new CheckOutcome(RunResult::Failed, 'uptime.failed', [],
            new UptimeMetrics(null, null, null, null, null, 0.2, $timings, $at->toIso8601String()))));
    expect(Incident::query()->sole()->opened_during_maintenance_window_id)->toBe($catchUp->id);
    maintenanceRule(maintenanceDestination(), 'site.down');
    $this->travel(20)->minutes();
    app()->call(new DispatchNotifications()->handle(...));
    app()->call(new DispatchNotifications()->handle(...));
    expect(Event::query()->where('type', 'maintenance.catch_up')->count())->toBe(1);
    Http::assertSentCount(1);
});

it('groups catch-up by site and destination using each incident own rules, then dedupes a second dispatch', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04T00:30:00Z'));
    config(['notifications.renotify_intervals_seconds' => null]);
    Http::preventStrayRequests();
    Http::fake(['https://receiver.test/maintenance' => Http::response([], 200)]);
    $site = Site::factory()->create();
    $window = MaintenancePeriod::factory()->create(['site_id' => $site->id, 'starts_at' => now()->subHour(),
        'ends_at' => now()->subMinute(), 'catch_up_at_end' => true]);
    $uptime = Check::factory()->for($site)->create(['type' => CheckKey::Uptime]);
    $certificate = Check::factory()->for($site)->create(['type' => CheckKey::CertificateHealth]);
    foreach ([$uptime, $certificate] as $check) {
        $incident = Incident::factory()->create(['site_id' => $site->id, 'check_id' => $check->id,
            'opened_at' => now()->subMinutes(30), 'opened_during_maintenance_window_id' => $window->id]);
        Event::factory()->create(['site_id' => $site->id, 'check_id' => $check->id, 'incident_id' => $incident->id,
            'type' => 'incident.opened', 'cause' => 'failed', 'to_result' => CheckResult::Failed,
            'occurred_at' => $incident->opened_at, 'suppressed' => true, 'suppression_reason' => 'maintenance',
            'notification_routed_at' => now()->subMinutes(29)]);
    }
    $onlyUptime = maintenanceDestination();
    $onlyCertificate = maintenanceDestination();
    $both = maintenanceDestination();
    $both->update(['locale' => 'de']);
    maintenanceRule($onlyUptime, 'site.down');
    maintenanceRule($onlyCertificate, 'check.failing');
    maintenanceRule($both, 'site.down');
    maintenanceRule($both, 'check.failing');
    app()->call(new DispatchNotifications()->handle(...));
    app()->call(new DispatchNotifications()->handle(...));
    expect(Event::query()->where('type', 'maintenance.catch_up')->count())->toBe(3)
        ->and(Delivery::query()->whereHas('event', fn ($query) => $query->where('type', 'maintenance.catch_up'))->count())->toBe(3)
        ->and(DB::table('maintenance_catch_up_incidents')->count())->toBe(4)
        ->and($window->fresh()->catch_up_processed_at)->not->toBeNull();
    $counts = Delivery::query()->whereHas('event', fn ($query) => $query->where('type', 'maintenance.catch_up'))
        ->get()->mapWithKeys(fn (Delivery $delivery): array => [$delivery->destination_id => $delivery->event->payload['count']])->sortKeys();
    expect($counts->all())->toBe([$onlyUptime->id => 1, $onlyCertificate->id => 1, $both->id => 2]);
    Http::assertSentCount(3);
    $bothEvent = Delivery::query()->where('destination_id', $both->id)->sole()->event;
    $rendered = new RenderedAlert(app(NotificationMessage::class)->build($bothEvent), 'de', 'Europe/Berlin',
        'Example', 'https://example.test');
    expect($rendered->details())->toContain('Erreichbarkeit', 'Zertifikat');
    $recovered = Incident::query()->where('check_id', $certificate->id)->sole();
    $recovered->update(['resolved_at' => now()]);
    Event::factory()->create(['site_id' => $site->id, 'check_id' => $certificate->id,
        'incident_id' => $recovered->id, 'type' => 'incident.resolved', 'cause' => 'failed',
        'from_result' => CheckResult::Failed, 'to_result' => CheckResult::Succeeded]);
    maintenanceRule($onlyCertificate, 'check.recovered');
    app()->call(new DispatchNotifications()->handle(...));
    expect(Delivery::query()->where('destination_id', $onlyCertificate->id)
        ->whereHas('event', fn ($query) => $query->where('type', 'incident.resolved'))
        ->where('state', 'sent')->count())->toBe(1);
});

it('excludes actively snoozed checks before grouping catch-up for a shared recipient', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04T00:30:00Z'));
    config(['notifications.renotify_intervals_seconds' => null]);
    Http::preventStrayRequests();
    Http::fake(['https://receiver.test/maintenance' => Http::response([], 200)]);
    $site = Site::factory()->create();
    $window = MaintenancePeriod::factory()->create(['site_id' => $site->id, 'starts_at' => now()->subHour(),
        'ends_at' => now()->subMinute(), 'catch_up_at_end' => true]);
    $incidents = [];
    foreach ([false, true] as $snoozed) {
        $check = Check::factory()->create(['site_id' => $site->id,
            'type' => $snoozed ? CheckKey::CertificateHealth : CheckKey::Uptime]);
        if ($snoozed) {
            CheckSnooze::factory()->create(['check_id' => $check->id,
                'starts_at' => now()->subMinute(), 'ends_at' => now()->addHour()]);
        }
        $incident = Incident::factory()->create(['site_id' => $site->id, 'check_id' => $check->id,
            'opened_at' => now()->subMinutes(30), 'opened_during_maintenance_window_id' => $window->id]);
        Event::factory()->create(['site_id' => $site->id, 'check_id' => $check->id, 'incident_id' => $incident->id,
            'type' => 'incident.opened', 'cause' => 'failed', 'to_result' => CheckResult::Failed,
            'suppressed' => true, 'suppression_reason' => 'maintenance']);
        $incidents[$snoozed ? 'snoozed' : 'eligible'] = $incident;
    }
    $destination = maintenanceDestination();
    maintenanceRule($destination, 'site.down');
    maintenanceRule($destination, 'check.failing');
    app()->call(new DispatchNotifications()->handle(...));
    $event = Event::query()->where('type', 'maintenance.catch_up')->sole();
    expect($event->payload['count'])->toBe(1)
        ->and(DB::table('maintenance_catch_up_incidents')->where('event_id', $event->id)->pluck('incident_id')->all())
        ->toBe([$incidents['eligible']->id])
        ->and(DB::table('maintenance_catch_up_incidents')->where('incident_id', $incidents['snoozed']->id)->exists())
        ->toBeFalse();
    Http::assertSentCount(1);
});

it('holds only overlapping site recipients and does not repeat catch-up for other sites', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04T00:30:00Z'));
    config(['notifications.renotify_intervals_seconds' => null]);
    Http::preventStrayRequests();
    Http::fake(['https://receiver.test/maintenance' => Http::response([], 200)]);
    $client = Client::factory()->create();
    $sites = Site::factory()->for($client)->count(2)->create();
    $window = MaintenancePeriod::query()->create(['target_type' => 'client', 'target_id' => $client->id,
        'starts_at' => now()->subHour(), 'ends_at' => now()->subMinute(), 'catch_up_at_end' => true]);
    MaintenancePeriod::factory()->create(['site_id' => $sites[1]->id, 'starts_at' => now()->subMinute(),
        'ends_at' => now()->addMinutes(5), 'catch_up_at_end' => false]);
    foreach ($sites as $site) {
        $check = Check::factory()->create(['site_id' => $site->id]);
        $incident = Incident::factory()->create(['check_id' => $check->id, 'site_id' => $site->id,
            'opened_at' => now()->subMinutes(30), 'opened_during_maintenance_window_id' => $window->id]);
        Event::factory()->create(['site_id' => $site->id, 'check_id' => $check->id, 'incident_id' => $incident->id,
            'type' => 'incident.opened', 'cause' => 'failed', 'to_result' => CheckResult::Failed,
            'suppressed' => true, 'suppression_reason' => 'maintenance']);
    }
    maintenanceRule(maintenanceDestination(), 'site.down');
    app()->call(new DispatchNotifications()->handle(...));
    expect(Event::query()->where('type', 'maintenance.catch_up')->count())->toBe(1)
        ->and($window->fresh()->catch_up_processed_at)->toBeNull();
    $this->travel(5)->minutes();
    app()->call(new DispatchNotifications()->handle(...));
    app()->call(new DispatchNotifications()->handle(...));
    expect(Event::query()->where('type', 'maintenance.catch_up')->count())->toBe(2)
        ->and($window->fresh()->catch_up_processed_at)->not->toBeNull();
    Http::assertSentCount(2);
});

it('does not let twenty blocked windows starve a later eligible catch-up', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04T00:30:00Z'));
    config(['notifications.renotify_intervals_seconds' => null]);
    Http::preventStrayRequests();
    Http::fake(['https://receiver.test/maintenance' => Http::response([], 200)]);
    $client = Client::factory()->create();
    MaintenancePeriod::query()->create(['target_type' => 'client', 'target_id' => $client->id,
        'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(), 'catch_up_at_end' => false]);
    $blocked = [];
    for ($i = 0; $i < 20; $i++) {
        $site = Site::factory()->for($client)->create();
        $window = MaintenancePeriod::factory()->create(['site_id' => $site->id, 'starts_at' => now()->subHour(),
            'ends_at' => now()->subMinutes(30), 'catch_up_at_end' => true]);
        $check = Check::factory()->create(['site_id' => $site->id]);
        $incident = Incident::factory()->create(['site_id' => $site->id, 'check_id' => $check->id,
            'opened_at' => now()->subMinutes(45), 'opened_during_maintenance_window_id' => $window->id]);
        Event::factory()->create(['site_id' => $site->id, 'check_id' => $check->id, 'incident_id' => $incident->id,
            'type' => 'incident.opened', 'cause' => 'failed', 'to_result' => CheckResult::Failed,
            'suppressed' => true, 'suppression_reason' => 'maintenance']);
        $blocked[] = $window->id;
    }
    $eligible = Site::factory()->create();
    $ready = MaintenancePeriod::factory()->create(['site_id' => $eligible->id,
        'starts_at' => now()->subHour(), 'ends_at' => now()->subMinutes(20), 'catch_up_at_end' => true]);
    $check = Check::factory()->create(['site_id' => $eligible->id]);
    $incident = Incident::factory()->create(['site_id' => $eligible->id, 'check_id' => $check->id,
        'opened_at' => now()->subMinutes(40), 'opened_during_maintenance_window_id' => $ready->id]);
    Event::factory()->create(['site_id' => $eligible->id, 'check_id' => $check->id, 'incident_id' => $incident->id,
        'type' => 'incident.opened', 'cause' => 'failed', 'to_result' => CheckResult::Failed,
        'suppressed' => true, 'suppression_reason' => 'maintenance']);
    maintenanceRule(maintenanceDestination(), 'site.down');
    app()->call(new DispatchNotifications()->handle(...));
    expect($ready->fresh()->catch_up_processed_at)->not->toBeNull()
        ->and(MaintenancePeriod::query()->whereIn('id', $blocked)->whereNull('catch_up_processed_at')->count())->toBe(20)
        ->and(Event::query()->where('type', 'maintenance.catch_up')->where('site_id', $eligible->id)->count())->toBe(1);
    Http::assertSentCount(1);
});

it('serializes catch-up against two independent schedulers', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04T00:30:00Z'));
    $site = Site::factory()->create();
    $window = MaintenancePeriod::factory()->create(['site_id' => $site->id, 'starts_at' => now()->subHour(),
        'ends_at' => now()->subMinute(), 'catch_up_at_end' => true]);
    $check = Check::factory()->create(['site_id' => $site->id]);
    $incident = Incident::factory()->create(['site_id' => $site->id, 'check_id' => $check->id,
        'opened_at' => now()->subMinutes(30), 'opened_during_maintenance_window_id' => $window->id]);
    Event::factory()->create(['site_id' => $site->id, 'check_id' => $check->id, 'incident_id' => $incident->id,
        'type' => 'incident.opened', 'cause' => 'failed', 'to_result' => CheckResult::Failed,
        'suppressed' => true, 'suppression_reason' => 'maintenance']);
    maintenanceRule(maintenanceDestination(), 'site.down');
    DB::commit();
    $code = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        Carbon\CarbonImmutable::setTestNow(Carbon\CarbonImmutable::parse('2026-10-04T00:30:00Z'));
        app(Heptapod\Actions\EmitMaintenanceCatchUps::class)();
        PHP;
    $processes = [];
    try {
        for ($i = 0; $i < 2; $i++) {
            $process = new Process([PHP_BINARY, '-r', $code], base_path(),
                ['APP_ENV' => 'testing', 'DB_DATABASE' => config('database.connections.pgsql.database')], timeout: 20);
            $process->start();
            $processes[] = $process;
        }
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
        }
        expect(Event::query()->where('type', 'maintenance.catch_up')->count())->toBe(1)
            ->and(Delivery::query()->count())->toBe(1);
    } finally {
        foreach ($processes as $process) {
            $process->stop();
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        DB::beginTransaction();
    }
});

it('alerts on the first post-window reminder without catch-up and permits later recovery', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04T00:30:00Z'));
    config(['notifications.renotify_intervals_seconds' => [3600]]);
    Http::preventStrayRequests();
    Http::fake(['https://receiver.test/maintenance' => Http::response([], 200)]);
    $check = Check::factory()->create(['latest_run_result' => CheckResult::Failed]);
    $window = MaintenancePeriod::factory()->create(['site_id' => $check->site_id,
        'starts_at' => now()->subHours(2), 'ends_at' => now()->subMinute(), 'catch_up_at_end' => false]);
    $incident = Incident::factory()->create(['check_id' => $check->id, 'site_id' => $check->site_id,
        'opened_at' => now()->subMinutes(90), 'opened_during_maintenance_window_id' => $window->id]);
    Event::factory()->create(['site_id' => $check->site_id, 'check_id' => $check->id, 'incident_id' => $incident->id,
        'type' => 'incident.opened', 'cause' => 'failed', 'to_result' => CheckResult::Failed,
        'occurred_at' => $incident->opened_at, 'suppressed' => true, 'suppression_reason' => 'maintenance']);
    maintenanceRule(maintenanceDestination(), 'site.down');
    app()->call(new DispatchNotifications()->handle(...));
    expect(Delivery::query()->whereHas('event', fn ($query) => $query->where('type', 'incident.still_down'))
        ->where('state', 'sent')->count())->toBe(1);
    $incident->update(['resolved_at' => now()]);
    Event::factory()->create(['site_id' => $check->site_id, 'check_id' => $check->id, 'incident_id' => $incident->id,
        'type' => 'incident.resolved', 'cause' => 'failed', 'from_result' => CheckResult::Failed,
        'to_result' => CheckResult::Succeeded]);
    maintenanceRule(NotificationDestination::query()->sole(), 'site.recovered');
    app()->call(new DispatchNotifications()->handle(...));
    expect(Delivery::query()->whereHas('event', fn ($query) => $query->where('type', 'incident.resolved'))
        ->where('state', 'sent')->count())->toBe(1);
});

it('skips recovered incidents and does not catch up legacy windows', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04T00:30:00Z'));
    $window = MaintenancePeriod::factory()->create(['starts_at' => now()->subHour(), 'ends_at' => now()->subMinute(),
        'catch_up_at_end' => true]);
    $check = Check::factory()->create(['site_id' => $window->site_id]);
    Incident::factory()->create(['check_id' => $check->id, 'site_id' => $window->site_id,
        'opened_during_maintenance_window_id' => $window->id, 'resolved_at' => now()->subMinutes(10)]);
    MaintenancePeriod::factory()->create(['site_id' => $window->site_id, 'starts_at' => now()->subHour(),
        'ends_at' => now()->subMinute(), 'catch_up_at_end' => false]);
    app(EmitMaintenanceCatchUps::class)();
    expect(Event::query()->where('type', 'maintenance.catch_up')->count())->toBe(0)
        ->and($window->fresh()->catch_up_processed_at)->not->toBeNull();
});
