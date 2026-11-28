<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CronTaskStore;
use Heptapod\Enums\ClientScope;
use Heptapod\Http\ListPageSize;
use Heptapod\Models\Check;
use Heptapod\Models\Client;
use Heptapod\Models\Event;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\RecurringMaintenancePeriod;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('filters upcoming dates by client without applying stale session selection or expanding member reach', function (string $role): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04T00:30:00Z'));
    $user = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $user->assignRole($role);
    $first = Client::factory()->create(['name' => 'First client']);
    $second = Client::factory()->create(['name' => 'Second client']);
    $foreign = Client::factory()->create(['name' => 'Foreign client']);
    $user->clients()->attach([$first->id, $second->id]);
    $periods = [];
    foreach ([$first, $second, $foreign] as $client) {
        $site = Site::factory()->for($client)->create();
        $periods[$client->id] = MaintenancePeriod::factory()->create(['site_id' => $site->id,
            'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour()]);
    }
    $this->actingAs($user)->withSession(['selected_client_id' => $first->id]);

    $response = $this->get('/upcoming?days=30&type=maintenance')->assertOk()->assertSessionMissing('selected_client_id');
    expect($response->inertiaProps()['items'])->toHaveCount($role === 'Owner' ? 3 : 2);
    $response = $this->withSession(['selected_client_id' => $first->id])->get('/upcoming?days=30&type=maintenance&client_id='.$second->id)->assertOk();
    expect(array_column($response->inertiaProps()['items'], 'key'))->toBe(['maintenance:'.$periods[$second->id]->id])
        ->and($response->inertiaProps('filters.client_id.value'))->toBe((string) $second->id)
        ->and($response->inertiaProps('filters.days.value'))->toBe('30')->and($response->inertiaProps('filters.type.value'))->toBe('maintenance');
    $response = $this->get('/upcoming?client_id='.$foreign->id)->assertOk();
    expect($response->inertiaProps()['items'])->toHaveCount($role === 'Owner' ? 1 : 0);
    if ($role === 'Member') {
        expect(array_column($response->inertiaProps()['clients'], 'id'))->not->toContain($foreign->id);
    }
    $this->get('/upcoming?client_id=0')->assertSessionHasErrors('client_id');
})->with(['Owner', 'Member']);

it('shows only scoped dated observations and maintenance in chronological order', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04T00:30:00Z'));
    $visible = Client::factory()->create();
    $hidden = Client::factory()->create();
    $site = Site::factory()->for($visible)->create(['friendly_name' => 'Visible']);
    $other = Site::factory()->for($hidden)->create(['friendly_name' => 'Hidden']);
    $user = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $user->assignRole('Member');
    $user->clients()->attach($visible);
    $check = Check::factory()->for($site)->create(['type' => CheckKey::Domain]);
    $hiddenCheck = Check::factory()->for($other)->create(['type' => CheckKey::Domain]);
    foreach ([[$check, '2026-10-20T00:00:00Z'], [$hiddenCheck, '2026-10-06T00:00:00Z']] as [$record, $expiry]) {
        DB::table('domain_snapshots')->insert(['site_id' => $record->site_id, 'check_id' => $record->id, 'run_id' => $record->id, 'claim_token' => (string) Str::uuid(), 'run_ended_at' => now(), 'snapshot' => json_encode(['domain' => 'site.example', 'expires_at' => $expiry, 'registrar' => 'Registrar'], JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }
    DB::table('domain_snapshots')->insert(['site_id' => $site->id, 'check_id' => $check->id, 'run_id' => 10001, 'claim_token' => (string) Str::uuid(), 'run_ended_at' => now()->subMinute(), 'snapshot' => json_encode(['domain' => 'site.example', 'expires_at' => null, 'registrar' => null], JSON_THROW_ON_ERROR), 'created_at' => now()->subMinute()]);
    MaintenancePeriod::factory()->create(['site_id' => $site->id, 'name' => 'Deploy', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour()]);

    $this->actingAs($user)->get('/upcoming?days=30')->assertOk()
        ->assertInertia(fn ($page) => $page->component('upcoming/index')->has('items', 2)
            ->where('items.0.type', 'maintenance')->where('items.1.type', 'domain')
            ->where('items.1.site_name', 'Visible')->where('items.1.detail', 'Registrar'));
    $this->get('/upcoming?days=300')->assertSessionHasErrors('days');
});

it('keeps Maintenance visibility while excluding paused deleting and disabled addon sources from upcoming dates', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04T00:30:00Z'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $active = Site::factory()->create();
    $paused = Site::factory()->create(['paused_at' => now()]);
    $deleting = Site::factory()->create(['marked_for_deletion_at' => now()]);
    $enabled = Check::factory()->for($active)->create(['type' => CheckKey::Domain]);
    $disabled = Check::factory()->for($active)->create(['type' => CheckKey::CertificateHealth, 'enabled' => false]);
    DB::table('domain_snapshots')->insert(['site_id' => $active->id, 'check_id' => $enabled->id, 'run_id' => $enabled->id, 'claim_token' => (string) Str::uuid(), 'run_ended_at' => now(), 'snapshot' => json_encode(['expires_at' => '2026-10-10T00:30:00Z'], JSON_THROW_ON_ERROR), 'created_at' => now()]);
    DB::table('certificate_health_snapshots')->insert(['site_id' => $active->id, 'check_id' => $disabled->id, 'run_id' => $disabled->id, 'claim_token' => (string) Str::uuid(), 'run_ended_at' => now(), 'snapshot' => json_encode(['certificate_details' => ['valid_until' => '2026-10-10T00:30:00Z']], JSON_THROW_ON_ERROR), 'created_at' => now()]);
    foreach ([$active, $paused, $deleting] as $site) {
        MaintenancePeriod::factory()->create(['site_id' => $site->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour()]);
    }

    $response = $this->actingAs($owner)->get('/upcoming?days=14')->assertOk();
    $items = $response->inertiaProps()['items'];
    expect($items)->toHaveCount(4)->and(array_column($items, 'type'))->toBe(['maintenance', 'maintenance', 'maintenance', 'domain']);
});

it('does not share the late runs count with guests or users without a monitoring role', function (): void {
    $guest = $this->get('/login')->assertOk()->inertiaPage();
    expect($guest['props'])->not->toHaveKey('lateRunsCount');

    $roleless = $this->actingAs(User::factory()->create())->get('/settings/profile')->assertOk()->inertiaPage();
    expect($roleless['props'])->not->toHaveKey('lateRunsCount');
});

it('reads offsetless certificate and domain expiry in UTC under a non-UTC database session', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04T00:30:00Z'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $site = Site::factory()->create();
    foreach ([[CheckKey::Domain, 'domain_snapshots', ['expires_at' => '2026-10-18T00:30:00']], [CheckKey::CertificateHealth, 'certificate_health_snapshots', ['certificate_details' => ['valid_until' => '2026-10-18T00:30:00']]]] as [$type, $table, $snapshot]) {
        $check = Check::factory()->for($site)->create(['type' => $type]);
        DB::table($table)->insert(['site_id' => $site->id, 'check_id' => $check->id, 'run_id' => $check->id, 'claim_token' => (string) Str::uuid(), 'run_ended_at' => now(), 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }
    $offsetSite = Site::factory()->create();
    $offsetCheck = Check::factory()->for($offsetSite)->create(['type' => CheckKey::Domain]);
    DB::table('domain_snapshots')->insert(['site_id' => $offsetSite->id, 'check_id' => $offsetCheck->id, 'run_id' => $offsetCheck->id, 'claim_token' => (string) Str::uuid(), 'run_ended_at' => now(), 'snapshot' => json_encode(['expires_at' => '2026-10-18T02:30:00+02:00'], JSON_THROW_ON_ERROR), 'created_at' => now()]);
    DB::statement("SET LOCAL TIME ZONE 'Pacific/Honolulu'");
    expect(DB::scalar("SELECT current_setting('TimeZone')"))->toBe('Pacific/Honolulu');

    $response = $this->actingAs($owner)->get('/upcoming?days=14')->assertOk();
    expect(array_column($response->inertiaProps()['items'], 'type'))->toBe(['certificate', 'domain', 'domain']);
});

it('projects certified expiry and only rare scheduled tasks from addon facts', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04T00:30:00Z'));
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $site = Site::factory()->create();
    $certificate = Check::factory()->for($site)->create(['type' => CheckKey::CertificateHealth]);
    $cron = Check::factory()->for($site)->create(['type' => CheckKey::Cron]);
    DB::table('certificate_health_snapshots')->insert(['site_id' => $site->id, 'check_id' => $certificate->id, 'run_id' => 1001, 'claim_token' => (string) Str::uuid(), 'run_ended_at' => now(), 'snapshot' => json_encode(['certificate_details' => ['valid_until' => '2026-10-14T00:00:00Z', 'issuer' => 'Issuer']], JSON_THROW_ON_ERROR), 'created_at' => now()]);
    DB::table('certificate_health_snapshots')->insert(['site_id' => $site->id, 'check_id' => $certificate->id, 'run_id' => 1002, 'claim_token' => (string) Str::uuid(), 'run_ended_at' => now()->subMinute(), 'snapshot' => json_encode(['certificate_details' => null], JSON_THROW_ON_ERROR), 'created_at' => now()->subMinute()]);
    $tasks = app(CronTaskStore::class);
    $tasks->create($cron->id, ['name' => 'Minute job', 'type' => 'simple', 'frequency_in_minutes' => 1]);
    $tasks->create($cron->id, ['name' => 'Monthly job', 'type' => 'cron', 'cron_expression' => '0 6 1 * *', 'server_timezone' => 'UTC']);

    $response = $this->actingAs($user)->get('/upcoming?days=30')->assertOk();
    expect(array_column($response->inertiaProps()['items'], 'type'))->toBe(['certificate', 'task'])
        ->and(array_column($response->inertiaProps()['items'], 'detail'))->toBe(['Issuer', 'Monthly job']);
    $this->get('/upcoming?type=task')->assertInertia(fn ($page) => $page->has('items', 1)->where('items.0.detail', 'Monthly job'));
});

it('includes a recurring maintenance window on the next local day within the UTC range', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04T15:00:00Z'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $site = Site::factory()->create();
    RecurringMaintenancePeriod::factory()->create(['site_id' => $site->id, 'timezone' => 'Pacific/Auckland', 'start_time' => '02:00', 'end_time' => '03:00']);

    $this->actingAs($owner)->get('/upcoming?days=30&type=maintenance')->assertOk()
        ->assertInertia(fn ($page) => $page->has('items', 30)->where('items.29.date', '2026-11-03T13:00:00+00:00'));
});

it('bounds All to twelve months and pages its chronological dates with a stable query budget', function (int $count): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04T00:30:00Z'));
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $client = Client::factory()->create();
    $addSites = static function (int $siteCount) use ($client): void {
        foreach (Site::factory()->count($siteCount)->for($client)->create() as $site) {
            RecurringMaintenancePeriod::factory()->create(['site_id' => $site->id, 'name' => 'Daily window']);
        }
    };
    new Event()->isGuarded('payload');
    $this->actingAs($user);
    $addSites(1);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->get('/upcoming?days=all')->assertOk()->assertInertia(fn ($page) => $page->has('items', 50)->where('per_page', 50));
    $fewQueries = count(DB::getQueryLog());
    DB::disableQueryLog();
    $addSites($count - 1);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $response = $this->get('/upcoming?days=all');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    $response->assertOk()->assertInertia(fn ($page) => $page->component('upcoming/index')->has('items', 50)->where('filters.days.value', 'all')->where('previous_page_url', null));
    // The agenda pages in date order: the second page of 100 continues where the first ended, and the size is remembered.
    $first = $this->get('/upcoming?days=all&per_page=100')->assertCookie(ListPageSize::Cookie, json_encode(['upcoming' => 100]), false)->inertiaProps();
    $second = $this->get('/upcoming?days=all&per_page=100&page=2')->inertiaProps();
    expect($first['items'])->toHaveCount(100)->and($second['items'])->toHaveCount(100)
        ->and($first['next_page_url'])->not->toBeNull()->and($second['previous_page_url'])->not->toBeNull()
        ->and([$first['items'][99]['date'], $first['items'][99]['key']] <=> [$second['items'][0]['date'], $second['items'][0]['key']])->toBe(-1);
    expect(count($queries))->toBeLessThanOrEqual($fewQueries, 'upcoming grew from '.$fewQueries.' to '.count($queries).' queries as maintenance sites grew from 1 to '.$count);
    // Upper budget: page shell and shell health (25458fc44/0a385128), the bounded maintenance, domain and
    // certificate reads, batched client avatars (36aca5a1a), and the sidebar's late runs count, which every page
    // now reads inline instead of in a second, deferred request, as it does Running now (visible sites, check runs, imports, backup).
    expect($fewQueries)->toBeLessThanOrEqual(28, 'upcoming used '.$fewQueries.' queries, over its budget of 28');
    expect(collect($response->inertiaProps()['items'])->max('date'))->toBeLessThanOrEqual('2027-10-04T00:30:00+00:00');
})->with([2, 20]);

it('keeps standalone maintenance pages disjoint when equal start times cross digit boundaries', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04T00:30:00Z'));
    $owner = User::factory()->owner()->create();
    $site = Site::factory()->create();
    $startsAt = CarbonImmutable::parse('2026-10-10T02:00:00Z');
    // 15 standalone windows with identical starts_at (IDs crossing 9 -> 10): SQL and merge must use the same key order.
    foreach (range(1, 15) as $index) {
        MaintenancePeriod::factory()->create([
            'site_id' => $site->id, 'target_type' => 'site', 'target_id' => $site->id,
            'name' => 'Window '.$index, 'starts_at' => $startsAt, 'ends_at' => $startsAt->addHour(),
        ]);
    }
    $page1 = array_column($this->actingAs($owner)->get('/upcoming?type=maintenance&per_page=10&page=1')->assertOk()->inertiaProps()['items'], 'key');
    $page2 = array_column($this->get('/upcoming?type=maintenance&per_page=10&page=2')->assertOk()->inertiaProps()['items'], 'key');

    expect($page1)->toHaveCount(10)->and($page2)->toHaveCount(5)
        ->and(array_intersect($page1, $page2))->toBe([])
        ->and(array_unique([...$page1, ...$page2]))->toHaveCount(15);
});

it('caps deep upcoming page requests at the server ceiling without scanning unbounded cron tasks', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04T00:30:00Z'));
    $owner = User::factory()->owner()->create();
    $site = Site::factory()->create();
    $cron = Check::factory()->for($site)->create(['type' => CheckKey::Cron]);
    $tasks = app(CronTaskStore::class);
    $tasks->create($cron->id, ['name' => 'Weekly job', 'type' => 'simple', 'frequency_in_minutes' => 10080]);

    $this->actingAs($owner);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $response = $this->get('/upcoming?type=task&per_page=100&page=1000')->assertOk();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($response->inertiaProps()['items'])->toBe([])
        ->and($response->inertiaProps()['next_page_url'])->toBeNull();
    expect(array_filter($queries, static fn (array $q): bool => str_contains($q['query'], 'cron_checks')))->toBe([]);

    // Extreme integer page numbers must check the ceiling before multiplying so offset arithmetic cannot overflow to float.
    $extreme = $this->get('/upcoming?type=task&per_page=100&page='.PHP_INT_MAX)->assertOk();
    expect($extreme->inertiaProps()['items'])->toBe([])
        ->and($extreme->inertiaProps()['next_page_url'])->toBeNull();
});

it('ignores a stale certificate expiry once a newer accepted observation has no certificate', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04T00:30:00Z'));
    $owner = User::factory()->owner()->create();
    $site = Site::factory()->create();
    $check = Check::factory()->for($site)->create(['type' => CheckKey::CertificateHealth]);
    DB::table('certificate_health_snapshots')->insert([
        ['site_id' => $site->id, 'check_id' => $check->id, 'run_id' => 1001, 'claim_token' => (string) Str::uuid(), 'run_ended_at' => now(),
            'snapshot' => json_encode(['certificate_details' => ['valid_until' => '2026-10-14T00:00:00Z', 'issuer' => 'Old Issuer']], JSON_THROW_ON_ERROR), 'created_at' => now()],
        ['site_id' => $site->id, 'check_id' => $check->id, 'run_id' => 1002, 'claim_token' => (string) Str::uuid(), 'run_ended_at' => now()->addMinute(),
            'snapshot' => json_encode(['certificate_details' => null], JSON_THROW_ON_ERROR), 'created_at' => now()->addMinute()],
    ]);

    $response = $this->actingAs($owner)->get('/upcoming?days=30&type=certificate')->assertOk();
    expect($response->inertiaProps()['items'])->toBe([]);
});

it('ignores a stale domain expiry once a newer accepted observation has no expiry', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04T00:30:00Z'));
    $owner = User::factory()->owner()->create();
    $site = Site::factory()->create();
    $check = Check::factory()->for($site)->create(['type' => CheckKey::Domain]);
    DB::table('domain_snapshots')->insert([
        ['site_id' => $site->id, 'check_id' => $check->id, 'run_id' => 1001, 'claim_token' => (string) Str::uuid(), 'run_ended_at' => now(),
            'snapshot' => json_encode(['domain' => 'site.example', 'expires_at' => '2026-10-14T00:00:00Z', 'registrar' => 'Old Registrar'], JSON_THROW_ON_ERROR), 'created_at' => now()],
        ['site_id' => $site->id, 'check_id' => $check->id, 'run_id' => 1002, 'claim_token' => (string) Str::uuid(), 'run_ended_at' => now()->addMinute(),
            'snapshot' => json_encode(['domain' => 'site.example', 'expires_at' => null, 'registrar' => 'New Registrar'], JSON_THROW_ON_ERROR), 'created_at' => now()->addMinute()],
    ]);

    $response = $this->actingAs($owner)->get('/upcoming?days=30&type=domain')->assertOk();
    expect($response->inertiaProps()['items'])->toBe([]);
});

it('keeps daily cron entries in Upcoming across DST transitions while excluding sub-daily schedules', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-03-27T12:00:00Z'));
    $owner = User::factory()->owner()->create();
    $site = Site::factory()->create();
    $cron = Check::factory()->for($site)->create(['type' => CheckKey::Cron]);
    $tasks = app(CronTaskStore::class);
    $tasks->create($cron->id, ['name' => 'Daily Berlin 03:00', 'type' => 'cron', 'cron_expression' => '0 3 * * *', 'server_timezone' => 'Europe/Berlin']);
    $tasks->create($cron->id, ['name' => 'Twice daily Berlin', 'type' => 'cron', 'cron_expression' => '0 3,15 * * *', 'server_timezone' => 'Europe/Berlin']);

    $spring = $this->actingAs($owner)->get('/upcoming?days=14&type=task')->assertOk()->inertiaProps()['items'];
    expect(array_column($spring, 'detail'))->toBe(['Daily Berlin 03:00']);

    // Fall-back day (25h in Europe/Berlin): a 00:00 & 23:00 task spans 24 UTC hours but still runs twice daily.
    $this->travelTo(CarbonImmutable::parse('2026-10-24T21:30:00Z'));
    $fallCron = Check::factory()->for(Site::factory()->create())->create(['type' => CheckKey::Cron]);
    $tasks->create($fallCron->id, ['name' => 'Twice on fallback', 'type' => 'cron', 'cron_expression' => '0 0,23 * * *', 'server_timezone' => 'Europe/Berlin']);
    $tasks->create($fallCron->id, ['name' => 'Daily 02:30 fallback', 'type' => 'cron', 'cron_expression' => '30 2 * * *', 'server_timezone' => 'Europe/Berlin']);

    $fall = $this->actingAs($owner)->get('/upcoming?days=14&type=task')->assertOk()->inertiaProps()['items'];
    expect(array_column($fall, 'detail'))->toBe(['Daily 02:30 fallback']);
});
