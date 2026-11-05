<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Actions\EmitIncidentReminders;
use Heptapod\Contracts\NotificationSuppression;
use Heptapod\Contracts\SuppressionSubject;
use Heptapod\Enums\ClientScope;
use Heptapod\Enums\SiteStatus;
use Heptapod\Incidents\SiteState;
use Heptapod\Models\Check;
use Heptapod\Models\Client;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\RecurringMaintenancePeriod;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Queries\UptimeReader;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\QueryPlanWork;

function maintenanceOwner(): User
{
    $owner = User::factory()->create();
    $owner->assignRole('Owner');

    return $owner;
}

it('filters maintenance target intersections without changing target facts or exposing restricted tags', function (string $role): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04T00:30:00Z'));
    $user = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $user->assignRole($role);
    $first = Client::factory()->create(['name' => 'First client']);
    $second = Client::factory()->create(['name' => 'Second client']);
    $foreign = Client::factory()->create(['name' => 'Foreign client']);
    $user->clients()->attach([$first->id, $second->id]);
    $firstSite = Site::factory()->for($first)->create();
    $secondSite = Site::factory()->for($second)->create();
    $foreignSite = Site::factory()->for($foreign)->create();
    $firstSite->attachTag('Cross-client target', 'sites');
    $secondSite->attachTag('Cross-client target', 'sites');
    $tagId = $firstSite->tags()->firstOrFail()->id;
    MaintenancePeriod::factory()->create(['site_id' => $firstSite->id]);
    $secondWindow = MaintenancePeriod::factory()->create(['site_id' => $secondSite->id]);
    MaintenancePeriod::factory()->create(['site_id' => $foreignSite->id]);
    $clientWindow = MaintenancePeriod::factory()->create(['site_id' => null, 'target_type' => 'client', 'target_id' => $second->id]);
    $tagWindow = MaintenancePeriod::factory()->create(['site_id' => null, 'target_type' => 'tag', 'target_id' => $tagId]);
    $this->actingAs($user)->withSession(['selected_client_id' => $first->id]);

    $response = $this->get('/maintenance')->assertOk()->assertSessionMissing('selected_client_id');
    expect($response->inertiaProps()['windows'])->toHaveCount($role === 'Owner' ? 5 : 3);
    $response = $this->withSession(['selected_client_id' => $first->id])->get('/maintenance?client_id='.$second->id)->assertOk();
    $rows = collect($response->inertiaProps()['windows'])->keyBy('id');
    expect($rows->keys()->all())->toEqualCanonicalizing($role === 'Owner' ? [$secondWindow->id, $clientWindow->id, $tagWindow->id] : [$secondWindow->id, $clientWindow->id]);
    expect($rows[$clientWindow->id]['targetType'])->toBe('client')->and($rows[$clientWindow->id]['targetId'])->toBe($second->id)
        ->and($rows[$clientWindow->id]['targetLabel'])->toBe('Second client');
    if ($role === 'Owner') {
        expect($rows[$tagWindow->id]['siteCount'])->toBe(2)->and($rows[$tagWindow->id]['targetLabel'])->toBe('Cross-client target');
    } else {
        expect(json_encode($response->inertiaProps(), JSON_THROW_ON_ERROR))->not->toContain('Cross-client target', 'Foreign client');
        expect(array_column($response->inertiaProps()['filter_clients'], 'id'))->not->toContain($foreign->id);
    }
    $response = $this->get('/maintenance?client_id='.$foreign->id)->assertOk();
    expect($response->inertiaProps()['windows'])->toHaveCount($role === 'Owner' ? 1 : 0);
    $response = $this->get('/maintenance?client_id='.$second->id.'&site_id='.$firstSite->id)->assertOk();
    expect(array_column($response->inertiaProps()['windows'], 'id'))->toBe($role === 'Owner' ? [$tagWindow->id] : []);
    $this->get('/maintenance?client_id=0')->assertSessionHasErrors('client_id');
})->with(['Owner', 'Member']);

it('keeps a directly targeted empty client and historical left-site windows filterable', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04T00:30:00Z'));
    $owner = maintenanceOwner();
    $client = Client::factory()->create();
    $empty = MaintenancePeriod::factory()->create(['site_id' => null, 'target_type' => 'client', 'target_id' => $client->id]);
    $this->actingAs($owner)->get('/maintenance?client_id='.$client->id)->assertInertia(fn (Assert $page): Assert => $page
        ->has('windows', 1)->where('windows.0.id', $empty->id)->where('windows.0.siteCount', 0));
    $site = Site::factory()->for($client)->create();
    $siteWindow = MaintenancePeriod::factory()->create(['site_id' => $site->id]);
    DB::table('maintenance_site_exclusions')->insert(['maintenance_period_id' => $siteWindow->id, 'site_id' => $site->id,
        'left_at' => now()->subMinute(), 'created_at' => now(), 'updated_at' => now()]);

    $this->get('/maintenance?client_id='.$client->id.'&site_id='.$site->id)->assertInertia(fn (Assert $page): Assert => $page
        ->has('windows', 2)->where('client_id', $client->id)->where('site_id', $site->id));
});

it('exposes dynamic client and tag membership, muted and informational modes through HTTP', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04T00:30:00Z'));
    $owner = maintenanceOwner();
    $client = Client::factory()->create();
    $first = Site::factory()->for($client)->create();
    $check = Check::factory()->for($first)->create();
    $this->actingAs($owner)->post('/maintenance', [
        'target_type' => 'client', 'target_id' => $client->id, 'starts_local' => '2026-10-04 00:00',
        'ends_local' => '2026-10-04 01:00', 'timezone' => 'UTC', 'recurrence_type' => 'once',
        'reason' => 'Deploy',
    ])->assertRedirect(route('maintenance.index'));
    $other = Site::factory()->for($client)->create();
    $this->get('/maintenance')->assertInertia(fn (Assert $page): Assert => $page->component('maintenance/index')
        ->where('windows.0.siteCount', 2)->where('windows.0.state', 'active')
        ->where('windows.0.targetLabel', $client->name)->where('windows.0.catchUpAtEnd', true));
    expect(app(SiteState::class)->derive($other))->toBe(SiteStatus::Maintenance)
        ->and(app(NotificationSuppression::class)->isSuppressed(new SuppressionSubject($check->id), CarbonImmutable::now())->suppressed)->toBeTrue();

    $tagged = Site::factory()->create();
    $tagged->attachTag('maintenance-dynamic');
    $tagId = $tagged->tags()->firstOrFail()->id;
    $this->post('/maintenance', [
        'target_type' => 'tag', 'target_id' => $tagId, 'starts_local' => '2026-10-04 00:00',
        'ends_local' => '2026-10-04 01:00', 'timezone' => 'UTC', 'recurrence_type' => 'once',
        'mute_alerts' => false,
    ])->assertRedirect(route('maintenance.index'));
    $later = Site::factory()->create();
    $later->attachTag('maintenance-dynamic');
    $laterCheck = Check::factory()->for($later)->create();
    $this->get('/maintenance')->assertInertia(fn (Assert $page): Assert => $page
        ->where('windows.0.siteCount', 2)->where('windows.0.muteAlerts', false));
    expect(app(NotificationSuppression::class)->isSuppressed(new SuppressionSubject($laterCheck->id), CarbonImmutable::now())->suppressed)->toBeFalse();
});

it('renders active, planned and done windows with only their available actions', function (): void {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-10-05T00:30:00Z'));
    $owner = maintenanceOwner();
    $site = Site::factory()->create();
    MaintenancePeriod::factory()->create(['site_id' => $site->id, 'starts_at' => now()->subDay(), 'ends_at' => now()->subHour()]);
    MaintenancePeriod::factory()->create(['site_id' => $site->id, 'starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);
    MaintenancePeriod::factory()->create(['site_id' => $site->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour()]);

    $this->actingAs($owner)->get('/maintenance')->assertInertia(fn (Assert $page): Assert => $page
        ->component('maintenance/index')->where('can_manage', true)->has('sites', 1)
        ->where('windows.0.state', 'planned')->where('windows.1.state', 'active')->where('windows.2.state', 'done')
        ->where('windows.1.actions.0', 'end_now')->has('windows.2.actions', 0));
});

it('hides a window outside member reach and disallows member writes', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04T00:30:00Z'));
    $owner = maintenanceOwner();
    $member = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $member->assignRole('Member');
    $allowed = Client::factory()->create();
    $denied = Client::factory()->create();
    $member->clients()->attach($allowed);
    $site = Site::factory()->for($denied)->create();
    $this->actingAs($owner)->post('/maintenance', [
        'target_type' => 'site', 'target_id' => $site->id, 'starts_local' => '2026-10-04 00:00',
        'ends_local' => '2026-10-04 01:00', 'timezone' => 'UTC', 'recurrence_type' => 'once',
    ])->assertRedirect(route('maintenance.index'));
    $this->actingAs($member)->get('/maintenance')->assertInertia(fn (Assert $page): Assert => $page->has('windows', 0)
        ->where('can_manage', false)->has('sites', 0)->has('clients', 0)->has('tags', 0));
    $this->post('/maintenance', [
        'target_type' => 'client', 'target_id' => $denied->id, 'starts_local' => '2026-10-04 00:00',
        'ends_local' => '2026-10-04 01:00', 'timezone' => 'UTC', 'recurrence_type' => 'once',
    ])->assertForbidden();
    $this->getJson('/sites/'.$site->id.'/maintenance')->assertNotFound();
    $window = MaintenancePeriod::query()->sole();
    $this->put('/maintenance/'.$window->id, [])->assertForbidden();
    $this->delete('/maintenance/'.$window->id)->assertForbidden();
    $series = RecurringMaintenancePeriod::factory()->create();
    $this->put('/maintenance/series/'.$series->id, [])->assertForbidden();
    $this->delete('/maintenance/series/'.$series->id)->assertForbidden();
});

it('redacts a restricted member site header for a shared tag even after another client joins', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04T00:30:00Z'));
    $owner = maintenanceOwner();
    $client = Client::factory()->create();
    $visible = Site::factory()->for($client)->create();
    $visible->attachTag('cross-client-maintenance');
    $tagId = $visible->tags()->firstOrFail()->id;
    $member = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $member->assignRole('Member');
    $member->clients()->attach($client);
    $this->actingAs($owner)->post('/maintenance', [
        'target_type' => 'tag', 'target_id' => $tagId, 'starts_local' => '2026-10-04 00:00',
        'ends_local' => '2026-10-04 01:00', 'timezone' => 'UTC', 'recurrence_type' => 'once',
        'reason' => 'Private client migration',
    ])->assertRedirect(route('maintenance.index'));
    $id = MaintenancePeriod::query()->where('target_type', 'tag')->sole()->id;
    $planned = MaintenancePeriod::factory()->create(['site_id' => $visible->id,
        'starts_at' => now()->addHour(), 'ends_at' => now()->addHours(2)]);
    $accessible = Site::factory()->for($client)->create();
    $own = MaintenancePeriod::factory()->create(['site_id' => $accessible->id,
        'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(), 'reason' => 'Visible maintenance']);
    $this->getJson('/sites/'.$visible->id.'/maintenance')->assertOk()
        ->assertJsonPath('window.id', $id)->assertJsonPath('window.reason', 'Private client migration');
    $this->actingAs($member)->getJson('/sites/'.$visible->id.'/maintenance')->assertOk()
        ->assertJsonPath('window.state', 'active')->assertJsonPath('window.muteAlerts', true)
        ->assertJsonPath('window.id', null)->assertJsonPath('window.reason', null)
        ->assertJsonPath('window.startsAt', null)->assertJsonPath('window.endsAt', null);
    $this->getJson('/sites/'.$accessible->id.'/maintenance')->assertOk()
        ->assertJsonPath('window.id', $own->id)->assertJsonPath('window.reason', 'Visible maintenance');
    $this->get('/maintenance')->assertInertia(fn (Assert $page): Assert => $page->has('windows', 2))
        ->assertDontSee('Private client migration');
    $foreign = Site::factory()->create();
    $foreign->attachTag('cross-client-maintenance');
    $this->getJson('/sites/'.$visible->id.'/maintenance')->assertOk()
        ->assertJsonPath('window.state', 'active')->assertJsonPath('window.id', null)
        ->assertJsonPath('window.reason', null)->assertJsonPath('window.timezone', null);
    $this->get('/maintenance?site_id='.$visible->id)->assertInertia(fn (Assert $page): Assert => $page
        ->has('windows', 1)->where('windows.0.id', $planned->id));
    $this->actingAs($owner)->getJson('/sites/'.$visible->id.'/maintenance')->assertOk()
        ->assertJsonPath('window.id', $id)->assertJsonPath('window.reason', 'Private client migration');
});

it('ends only the selected occurrence and keeps another site window active', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04T00:30:00Z'));
    $owner = maintenanceOwner();
    $site = Site::factory()->create();
    $first = MaintenancePeriod::factory()->create(['site_id' => $site->id, 'starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);
    $second = MaintenancePeriod::factory()->create(['site_id' => $site->id, 'starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);
    expect($first->fresh()->catch_up_at_end)->toBeTrue();
    $this->actingAs($owner)->get('/maintenance')->assertInertia(fn (Assert $page): Assert => $page
        ->where('windows.0.targetLabel', $site->displayName()));
    $this->actingAs($owner)->post('/maintenance/'.$first->id.'/end')->assertRedirect(route('maintenance.index'));
    expect($first->fresh()->ends_at->equalTo(CarbonImmutable::now()))->toBeTrue()
        ->and(MaintenancePeriod::activeForSite($site->id, CarbonImmutable::now())->count())->toBe(1)
        ->and($second->fresh()->ends_at->greaterThan(CarbonImmutable::now()))->toBeTrue();
});

it('generates nth weekdays and every second week through HTTP across DST', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-01T00:30:00Z'));
    config(['heptapod.maintenance.horizon_days' => 65]);
    $owner = maintenanceOwner();
    $site = Site::factory()->create();
    $this->actingAs($owner)->post('/maintenance', [
        'target_type' => 'site', 'target_id' => $site->id, 'starts_local' => '2026-10-01 02:30',
        'ends_local' => '2026-10-01 03:30', 'timezone' => 'Europe/Berlin', 'recurrence_type' => 'monthly',
        'nth_weekday' => 2, 'day_of_week' => 0,
    ])->assertRedirect(route('maintenance.index'));
    $windows = MaintenancePeriod::query()->orderBy('starts_at')->limit(2)->get();
    expect($windows)->toHaveCount(2)->and($windows[0]->starts_at->format('Y-m-d H:i'))
        ->toBe('2026-10-11 00:30')->and($windows[1]->starts_at->format('Y-m-d H:i'))->toBe('2026-11-08 01:30');
    config(['heptapod.maintenance.horizon_days' => 30]);
    $this->post('/maintenance', [
        'target_type' => 'site', 'target_id' => $site->id, 'starts_local' => '2026-10-01 02:30',
        'ends_local' => '2026-10-01 03:30', 'timezone' => 'Europe/Berlin', 'recurrence_type' => 'weekly',
        'interval' => 2, 'days_of_week' => [0],
    ])->assertRedirect(route('maintenance.index'));
    $weeklyId = MaintenancePeriod::query()->where('recurring_maintenance_period_id', '!=', $windows[0]->recurring_maintenance_period_id)
        ->whereNotNull('recurring_maintenance_period_id')->firstOrFail()->recurring_maintenance_period_id;
    $dates = MaintenancePeriod::query()->where('recurring_maintenance_period_id', $weeklyId)
        ->orderBy('starts_at')->pluck('starts_at')->map(fn (CarbonImmutable $date): string => $date->format('Y-m-d'))->all();
    expect($dates)->toBe(['2026-10-04', '2026-10-18']);
});

it('switches a monthly series from nth weekday to day of month and back through HTTP', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-01T00:30:00Z'));
    $site = Site::factory()->create();
    $this->actingAs(maintenanceOwner())->post('/maintenance', [
        'target_type' => 'site', 'target_id' => $site->id, 'starts_local' => '2026-10-01 02:30',
        'ends_local' => '2026-10-01 03:30', 'timezone' => 'UTC', 'recurrence_type' => 'monthly',
        'nth_weekday' => 2, 'day_of_week' => 0,
    ])->assertRedirect(route('maintenance.index'));
    $series = MaintenancePeriod::query()->whereNotNull('recurring_maintenance_period_id')->firstOrFail()->recurring_maintenance_period_id;
    $this->put('/maintenance/series/'.$series, [
        'day_of_month' => 15, 'nth_weekday' => null, 'day_of_week' => null, 'days_of_week' => null,
    ])->assertRedirect(route('maintenance.index'));
    $updated = RecurringMaintenancePeriod::query()->findOrFail($series);
    expect($updated->day_of_month)->toBe(15)->and($updated->nth_weekday)->toBeNull();
    $this->put('/maintenance/series/'.$series, [
        'day_of_month' => null, 'nth_weekday' => 3, 'day_of_week' => 1, 'days_of_week' => null,
    ])->assertRedirect(route('maintenance.index'));
    expect($updated->fresh()->day_of_month)->toBeNull()->and($updated->fresh()->nth_weekday)->toBe(3);
});

it('returns a field error for a missing maintenance target on updates', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04T00:30:00Z'));
    $site = Site::factory()->create();
    $window = MaintenancePeriod::factory()->create(['site_id' => $site->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour()]);
    $this->actingAs(maintenanceOwner())->put('/maintenance/'.$window->id, [
        'target_type' => 'site', 'target_id' => 99999999, 'starts_local' => '2026-10-05 02:30',
        'ends_local' => '2026-10-05 03:30', 'timezone' => 'UTC', 'mute_alerts' => true, 'catch_up_at_end' => true,
    ])->assertSessionHasErrors('target_id');
    $series = RecurringMaintenancePeriod::factory()->create();
    $this->put('/maintenance/series/'.$series->id, ['target_type' => 'site', 'target_id' => 99999999])
        ->assertSessionHasErrors('target_id');
});

it('resolves effective site targets with bounded indexed work', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04T00:30:00Z'));
    $target = Site::factory()->create();
    $other = Site::factory()->create();
    DB::insert("INSERT INTO maintenance_periods (site_id, target_type, target_id, starts_at, ends_at, created_at, updated_at)
        SELECT ?, 'site', ?, CURRENT_TIMESTAMP - INTERVAL '1 hour', CURRENT_TIMESTAMP + INTERVAL '1 hour',
            CURRENT_TIMESTAMP, CURRENT_TIMESTAMP FROM generate_series(1, 5000)", [$other->id, $other->id]);
    MaintenancePeriod::factory()->create(['site_id' => $target->id, 'target_id' => $target->id,
        'starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);
    $target->attachTag('indexed-maintenance');
    $tagId = $target->tags()->firstOrFail()->id;
    MaintenancePeriod::query()->create(['site_id' => null, 'target_type' => 'tag', 'target_id' => $tagId,
        'starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);
    DB::statement('ANALYZE maintenance_periods');
    DB::statement('ANALYZE taggables');
    $query = MaintenancePeriod::forSite($target->id)->toBase();
    expect($query->count())->toBe(2)
        ->and(QueryPlanWork::scannedRows($query->toSql(), $query->getBindings()))->toBeLessThan(250);
});

it('ends only the selected site from a shared occurrence in the web site header', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04T00:30:00Z'));
    $owner = maintenanceOwner();
    $client = Client::factory()->create();
    $sites = Site::factory()->for($client)->count(2)->create();
    $window = MaintenancePeriod::query()->create(['target_type' => 'client', 'target_id' => $client->id,
        'starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);
    $this->actingAs($owner)->post('/sites/'.$sites[0]->id.'/maintenance/end')->assertRedirect();
    $this->getJson('/sites/'.$sites[0]->id.'/maintenance')->assertOk()->assertJsonPath('window', null);
    $this->get('/maintenance?site_id='.$sites[0]->id)->assertInertia(fn (Assert $page): Assert => $page
        ->where('windows.0.state', 'done')->where('windows.0.endsAt', CarbonImmutable::now()->toIso8601String()));
    expect(MaintenancePeriod::activeForSite($sites[1]->id, CarbonImmutable::now())->sole()->id)->toBe($window->id);
    $member = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $member->assignRole('Member');
    $member->clients()->attach($client);
    $this->actingAs($member)->post('/sites/'.$sites[1]->id.'/maintenance/end')->assertForbidden();
});

it('limits historical suppression and uptime exclusion to the site departure instant', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04T00:00:00Z'));
    $owner = maintenanceOwner();
    $client = Client::factory()->create();
    $sites = Site::factory()->for($client)->count(2)->create();
    $checks = $sites->map(fn (Site $site): Check => Check::factory()->for($site)->create(['first_result_at' => now()]));
    MaintenancePeriod::query()->create(['target_type' => 'client', 'target_id' => $client->id,
        'starts_at' => now(), 'ends_at' => now()->addHour()]);
    $this->travel(30)->minutes();
    $this->actingAs($owner)->post('/sites/'.$sites[0]->id.'/maintenance/end')->assertRedirect();
    $subject = new SuppressionSubject($checks[0]->id);
    expect(app(NotificationSuppression::class)->isSuppressed($subject, CarbonImmutable::parse('2026-10-04T00:15:00Z'))->suppressed)->toBeTrue()
        ->and(app(NotificationSuppression::class)->isSuppressed($subject, CarbonImmutable::parse('2026-10-04T00:30:01Z'))->suppressed)->toBeFalse();
    $from = CarbonImmutable::parse('2026-10-04T00:00:00Z');
    $until = $from->addHour();
    expect(app(UptimeReader::class)->percentage([$checks[0]->id], $from, $until))->toBe(100.0)
        ->and(app(UptimeReader::class)->percentage([$checks[1]->id], $from, $until))->toBeNull();
});

it('edits and cancels a planned occurrence and future series through authorized routes', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04T00:30:00Z'));
    $owner = maintenanceOwner();
    $site = Site::factory()->create();
    $input = ['target_type' => 'site', 'target_id' => $site->id, 'starts_local' => '2026-10-05 02:30',
        'ends_local' => '2026-10-05 03:30', 'timezone' => 'Europe/Berlin', 'recurrence_type' => 'once'];
    $this->actingAs($owner)->post('/maintenance', $input)->assertRedirect(route('maintenance.index'));
    $id = MaintenancePeriod::query()->sole()->id;
    $this->put('/maintenance/'.$id, [...$input, 'reason' => 'Migration', 'mute_alerts' => false,
        'catch_up_at_end' => false])->assertRedirect(route('maintenance.index'));
    expect(MaintenancePeriod::query()->findOrFail($id)->reason)->toBe('Migration')
        ->and(MaintenancePeriod::query()->findOrFail($id)->mute_alerts)->toBeFalse();
    $this->delete('/maintenance/'.$id)->assertRedirect(route('maintenance.index'));
    expect(MaintenancePeriod::query()->count())->toBe(0);
    $done = MaintenancePeriod::factory()->create(['site_id' => $site->id, 'starts_at' => now()->subHours(2),
        'ends_at' => now()->subHour()]);
    $this->put('/maintenance/'.$done->id, [...$input, 'mute_alerts' => false,
        'catch_up_at_end' => false])->assertStatus(409);
    $done->delete();

    $this->post('/maintenance', [...$input, 'recurrence_type' => 'weekly',
        'days_of_week' => [1]])->assertRedirect(route('maintenance.index'));
    $seriesId = MaintenancePeriod::query()->whereNotNull('recurring_maintenance_period_id')->firstOrFail()->recurring_maintenance_period_id;
    $this->put('/maintenance/series/'.$seriesId, ['reason' => 'Release'])->assertRedirect(route('maintenance.index'));
    expect(MaintenancePeriod::query()->where('recurring_maintenance_period_id', $seriesId)->firstOrFail()->reason)->toBe('Release');
    $this->delete('/maintenance/series/'.$seriesId)->assertRedirect(route('maintenance.index'));
    expect(MaintenancePeriod::query()->where('recurring_maintenance_period_id', $seriesId)->count())->toBe(0);
});

it('does not emit reminders while a muted window covers an already-open incident', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04T00:30:00Z'));
    $check = Check::factory()->create();
    $incident = Incident::factory()->create(['check_id' => $check->id, 'site_id' => $check->site_id, 'opened_at' => now()->subHours(2)]);
    $check->update(['latest_run_result' => 'failed']);
    MaintenancePeriod::factory()->create(['site_id' => $check->site_id, 'starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);
    app(EmitIncidentReminders::class)();
    expect(Event::query()->where('incident_id', $incident->id)->where('type', 'incident.still_down')->count())->toBe(0);
});
