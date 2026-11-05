<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Actions\CreateApiToken;
use Heptapod\Actions\CreateMaintenanceWindow;
use Heptapod\Actions\EmitMaintenanceCatchUps;
use Heptapod\Addons\Uptime\UptimeMetrics;
use Heptapod\Checks\Recording\RecordableRun;
use Heptapod\Checks\Recording\ResultRecorder;
use Heptapod\Contracts\CheckOutcome;
use Heptapod\Contracts\Notifications\ChannelRegistry;
use Heptapod\Contracts\RunResult;
use Heptapod\Enums\ClientScope;
use Heptapod\Enums\SiteStatus;
use Heptapod\Incidents\SiteState;
use Heptapod\Models\Check;
use Heptapod\Models\Client;
use Heptapod\Models\Event;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\MaintenanceSiteExclusion;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Testing\ApiSpec;
use Heptapod\Testing\ClaimedRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    app()->singleton(ApiSpec::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-04T00:30:00Z'));
});

it('serves Oh Dear-shaped maintenance CRUD and monitor start stop through real bearer routes', function (): void {
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $site = Site::factory()->create();
    $this->withToken(app(CreateApiToken::class)($owner, 'maintenance', ['maintenance:read', 'maintenance:write']));
    $period = $this->postJson('/api/maintenance-periods', [
        'monitor_id' => $site->id, 'starts_at' => '2026-10-05 10:00', 'ends_at' => '2026-10-05 11:00',
        'name' => 'Migration',
    ])->assertCreated()->assertJsonPath('monitor_id', $site->id)->json('id');
    $this->getJson('/api/monitors/'.$site->id.'/maintenance-periods?filter[started_at]=20261005000000')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $period);
    $this->putJson('/api/maintenance-periods/'.$period, [
        'starts_at' => '2026-10-05 12:00', 'ends_at' => '2026-10-05 13:00', 'name' => 'Updated',
    ])->assertOk()->assertJsonPath('ends_at', '2026-10-05 13:00:00');
    $this->deleteJson('/api/maintenance-periods/'.$period)->assertNoContent();
    $this->postJson('/api/monitors/'.$site->id.'/start-maintenance', [
        'stop_maintenance_after_seconds' => 300, 'name' => 'Immediate',
    ])->assertOk()->assertJsonPath('name', 'Immediate')->assertJsonPath('monitor_id', $site->id);
    expect(app(SiteState::class)->derive($site))->toBe(SiteStatus::Maintenance);
    $this->postJson('/api/monitors/'.$site->id.'/stop-maintenance')->assertNoContent();
    expect(MaintenancePeriod::activeForSite($site->id, CarbonImmutable::now())->count())->toBe(0);
});

it('hydrates the official SDK maintenance DTOs and collection', function (): void {
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $site = Site::factory()->create();
    $token = app(CreateApiToken::class)($owner, 'sdk', ['maintenance:read', 'maintenance:write']);
    $sdk = $this->sdk($token);
    $period = $sdk->createMaintenancePeriod(['monitor_id' => $site->id,
        'starts_at' => '2026-10-05 10:00', 'ends_at' => '2026-10-05 11:00', 'name' => 'SDK']);
    expect($period->monitorId)->toBe($site->id)
        ->and(iterator_to_array($sdk->maintenancePeriods($site->id)))->toHaveCount(1);
    expect($sdk->updateMaintenancePeriod($period->id, ['starts_at' => '2026-10-05 12:00',
        'ends_at' => '2026-10-05 13:00'])->id)->toBe($period->id);
    $sdk->deleteMaintenancePeriod($period->id);
    expect(iterator_to_array($sdk->maintenancePeriods($site->id)))->toBeEmpty();
    expect($sdk->startMaintenancePeriod($site->id, 300, 'SDK immediate')->name)->toBe('SDK immediate');
    $sdk->stopMaintenancePeriod($site->id);
    expect(MaintenancePeriod::activeForSite($site->id, CarbonImmutable::now())->count())->toBe(0);
});

it('applies independent abilities and current user reach to every maintenance API entry point', function (): void {
    $client = Client::factory()->create();
    $visible = Site::factory()->for($client)->create();
    $hidden = Site::factory()->create();
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $readToken = app(CreateApiToken::class)($owner, 'read', ['maintenance:read']);
    $this->withToken($readToken)->getJson('/api/monitors/'.$visible->id.'/maintenance-periods')->assertOk();
    $this->postJson('/api/monitors/'.$visible->id.'/start-maintenance')->assertForbidden();
    $this->postJson('/api/maintenance-periods', ['monitor_id' => $visible->id,
        'starts_at' => '2026-10-05 10:00', 'ends_at' => '2026-10-05 11:00'])->assertForbidden();
    $writeToken = app(CreateApiToken::class)($owner, 'write', ['maintenance:write']);
    $this->withToken($writeToken)->getJson('/api/monitors/'.$visible->id.'/maintenance-periods')->assertForbidden();
    $this->postJson('/api/monitors/'.$visible->id.'/start-maintenance')->assertOk();
    $this->getJson('/api/monitors/'.$visible->id.'/maintenance-periods')->assertForbidden();
    $this->withToken($readToken);
    $member = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $member->assignRole('Member');
    $member->clients()->attach($client);
    expect(fn () => app(CreateApiToken::class)($member, 'unsafe', ['maintenance:write']))->toThrow(ValidationException::class);
    $this->withToken(app(CreateApiToken::class)($member, 'scoped', ['maintenance:read']));
    $this->getJson('/api/monitors/'.$visible->id.'/maintenance-periods')->assertOk();
    $this->getJson('/api/monitors/'.$hidden->id.'/maintenance-periods')->assertNotFound();
    $this->withToken($member->createToken('legacy', ['*'])->plainTextToken);
    $this->postJson('/api/monitors/'.$visible->id.'/stop-maintenance')->assertForbidden();
    $this->postJson('/api/monitors/'.$hidden->id.'/stop-maintenance')->assertNotFound();
});

it('catches up a still-failing monitor immediately when it leaves a shared window', function (): void {
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $client = Client::factory()->create();
    $sites = Site::factory()->for($client)->count(2)->create();
    $check = Check::factory()->create(['site_id' => $sites[0]->id, 'failure_threshold' => 1]);
    $window = app(CreateMaintenanceWindow::class)($owner, [
        'target_type' => 'client', 'target_id' => $client->id,
        'starts_local' => '2026-10-04 00:00', 'ends_local' => '2026-10-04 01:00',
        'timezone' => 'UTC', 'recurrence_type' => 'once',
    ]);
    $destination = NotificationDestination::factory()->create(['channel' => 'webhook',
        'config' => app(ChannelRegistry::class)->get('webhook')->configSchema()->validateConfig([
            'url' => 'https://receiver.test/maintenance', 'secret' => 'maintenance-route-secret',
        ])]);
    DB::table('notification_preferences')->where('destination_id', $destination->id)->where('category', 'outage')->update(['mode' => 'immediate']);
    $at = CarbonImmutable::now('UTC');
    $claim = ClaimedRun::start($check, $at->subSecond());
    $timings = array_fill_keys(['namelookup_time', 'connect_time', 'appconnect_time', 'starttransfer_time',
        'pretransfer_time', 'redirect_time', 'total_time', 'header_size', 'size_download', 'speed_download'], null);
    app(ResultRecorder::class)->record(new RecordableRun($check->id, $claim->claimToken, $claim->startedAt, $at,
        new CheckOutcome(RunResult::Failed, 'uptime.failed', [],
            new UptimeMetrics(null, null, null, null, null, 0.2, $timings, $at->toIso8601String()))));
    $this->withToken(app(CreateApiToken::class)($owner, 'maintenance', ['maintenance:write']));
    $this->postJson('/api/monitors/'.$sites[0]->id.'/stop-maintenance')->assertNoContent();
    $this->postJson('/api/monitors/'.$sites[0]->id.'/stop-maintenance')->assertNoContent();
    expect(Event::query()->where('type', 'maintenance.catch_up')->count())->toBe(1)
        ->and($window->fresh()->ends_at->greaterThan($at))->toBeTrue()
        ->and(app(SiteState::class)->derive($sites[1]))->toBe(SiteStatus::Maintenance);
});

it('honors delayed site departure and processes it at the scheduled instant', function (): void {
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $client = Client::factory()->create();
    $sites = Site::factory()->for($client)->count(2)->create();
    $window = app(CreateMaintenanceWindow::class)($owner, [
        'target_type' => 'client', 'target_id' => $client->id,
        'starts_local' => '2026-10-04 00:00', 'ends_local' => '2026-10-04 01:00',
        'timezone' => 'UTC', 'recurrence_type' => 'once',
    ]);
    $this->withToken(app(CreateApiToken::class)($owner, 'maintenance', ['maintenance:write']));
    $this->postJson('/api/monitors/'.$sites[0]->id.'/stop-maintenance', ['delay_seconds' => 60])->assertNoContent();
    expect(app(SiteState::class)->derive($sites[0]))->toBe(SiteStatus::Maintenance);
    $this->travel(60)->seconds();
    app(EmitMaintenanceCatchUps::class)();
    expect(app(SiteState::class)->derive($sites[0]))->not->toBe(SiteStatus::Maintenance)
        ->and(app(SiteState::class)->derive($sites[1]))->toBe(SiteStatus::Maintenance)
        ->and(MaintenanceSiteExclusion::query()->where('maintenance_period_id', $window->id)
            ->firstOrFail()->catch_up_processed_at)->not->toBeNull();
});

it('projects shared client windows without granting shared writes and excludes only the stopped site', function (): void {
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $client = Client::factory()->create();
    $sites = Site::factory()->for($client)->count(2)->create();
    config(['heptapod.maintenance.horizon_days' => 1]);
    app(CreateMaintenanceWindow::class)($owner, [
        'target_type' => 'client', 'target_id' => $client->id,
        'starts_local' => '2026-10-04 00:00', 'ends_local' => '2026-10-04 01:00',
        'timezone' => 'UTC', 'recurrence_type' => 'daily',
    ]);
    $first = MaintenancePeriod::activeForSite($sites[0]->id, CarbonImmutable::now())->sole();
    $this->withToken(app(CreateApiToken::class)($owner, 'maintenance', ['maintenance:read', 'maintenance:write']));
    $this->getJson('/api/monitors/'.$sites[0]->id.'/maintenance-periods')
        ->assertOk()->assertJsonPath('data.0.monitor_id', $sites[0]->id)->assertJsonPath('data.0.shared', true)
        ->assertJsonPath('data.0.scope', 'client');
    $this->putJson('/api/maintenance-periods/'.$first->id, [
        'starts_at' => '2026-10-04 00:00', 'ends_at' => '2026-10-04 01:00',
    ])->assertUnprocessable()->assertJsonValidationErrors('maintenance_period_id');
    $this->deleteJson('/api/maintenance-periods/'.$first->id)->assertUnprocessable()->assertJsonValidationErrors('maintenance_period_id');
    $this->postJson('/api/monitors/'.$sites[0]->id.'/stop-maintenance')->assertNoContent();
    $this->postJson('/api/monitors/'.$sites[0]->id.'/stop-maintenance')->assertNoContent();
    expect(app(SiteState::class)->derive($sites[0]))->not->toBe(SiteStatus::Maintenance)
        ->and(app(SiteState::class)->derive($sites[1]))->toBe(SiteStatus::Maintenance)
        ->and($first->fresh()->ends_at->greaterThan(CarbonImmutable::now()))->toBeTrue();
    $history = $this->getJson('/api/monitors/'.$sites[0]->id.'/maintenance-periods')->assertOk()->assertJsonCount(2, 'data')->json('data');
    $ended = collect($history)->firstWhere('id', $first->id);
    expect($ended['ends_at'])->toBe('2026-10-04 00:30:00');
    $this->travel(1)->day();
    expect(app(SiteState::class)->derive($sites[0]))->toBe(SiteStatus::Maintenance);
});
