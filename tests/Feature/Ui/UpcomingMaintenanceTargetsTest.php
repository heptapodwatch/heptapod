<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Actions\GenerateMaintenancePeriods;
use Heptapod\Enums\ClientScope;
use Heptapod\Models\Client;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\RecurringMaintenancePeriod;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;

it('uses the same future maintenance visibility and target facts for Upcoming and its sidebar count', function (string $role, string $targetType, bool $recurring): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04T00:30:00Z'));
    $user = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $user->assignRole($role);
    $visible = Client::factory()->create(['name' => 'Visible client']);
    $hidden = Client::factory()->create(['name' => 'Hidden client']);
    $user->clients()->attach($visible);
    $site = Site::factory()->for($visible)->create(['friendly_name' => 'Visible site']);
    $other = Site::factory()->for($hidden)->create(['friendly_name' => 'Hidden site']);
    $site->attachTag('Cross-client target', 'sites');
    $other->attachTag('Cross-client target', 'sites');
    $tagId = $site->tags()->firstOrFail()->id;
    $targetId = match ($targetType) {
        'site' => $site->id, 'client' => $visible->id, 'tag' => $tagId
    };
    $attributes = ['site_id' => $targetType === 'site' ? $site->id : null, 'target_type' => $targetType, 'target_id' => $targetId];
    if ($recurring) {
        $series = RecurringMaintenancePeriod::factory()->create([...$attributes, 'timezone' => 'UTC', 'start_time' => '01:00', 'end_time' => '02:00']);
        app(GenerateMaintenancePeriods::class)($series, now()->addDays(14)->toImmutable());
        $expectedDates = array_map(static fn (int $day): string => sprintf('2026-10-%02dT01:00:00+00:00', $day), range(4, 17));
    } else {
        MaintenancePeriod::factory()->create([...$attributes, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour()]);
        MaintenancePeriod::factory()->create([...$attributes, 'starts_at' => now()->subDay(), 'ends_at' => now()->subDay()->addHour()]);
        MaintenancePeriod::factory()->create([...$attributes, 'starts_at' => now()->addDays(14)->addSecond(), 'ends_at' => now()->addDays(15)]);
        $expectedDates = ['2026-10-05T00:30:00+00:00'];
    }
    MaintenancePeriod::factory()->create(['site_id' => $other->id, 'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(3)->addHour()]);
    $this->actingAs($user);

    $maintenance = collect($this->get('/maintenance')->assertOk()->inertiaProps()['windows'])
        ->filter(static fn (array $window): bool => $window['startsAt'] >= '2026-10-04T00:30:00+00:00' && $window['startsAt'] <= '2026-10-18T00:30:00+00:00');
    $response = $this->get('/upcoming?days=14&type=maintenance')->assertOk();
    $items = collect($response->inertiaProps()['items']);
    expect($items->pluck('date')->all())->toEqualCanonicalizing($maintenance->pluck('startsAt')->all());
    $targetItems = $items->where('target_type', $targetType)->where('target_id', $targetId)->values();
    $allowed = $role === 'Owner' || $targetType !== 'tag';
    expect($targetItems->pluck('date')->all())->toBe($allowed ? $expectedDates : []);
    if ($allowed) {
        $first = $targetItems->first();
        expect($first['target_label'])->toBe(match ($targetType) {
            'site' => 'Visible site', 'client' => 'Visible client', 'tag' => 'Cross-client target'
        })
            ->and($first['site_count'])->toBe($targetType === 'tag' ? 2 : 1);
        expect($first['site_name'])->toBe($targetType === 'site' ? 'Visible site' : null);
    }
    $response->assertInertia(fn ($page) => $page->loadDeferredProps(['sidebar'], fn ($deferred) => $deferred->where('upcomingCount', $maintenance->count())));
    $filtered = collect($this->get('/upcoming?days=14&type=maintenance&client_id='.$visible->id)->assertOk()->inertiaProps()['items']);
    expect($filtered->pluck('key')->all())->toBe($targetItems->pluck('key')->all());
    if ($role === 'Member') {
        expect(json_encode($items, JSON_THROW_ON_ERROR))->not->toContain('Cross-client target', 'Hidden client', 'Hidden site');
        expect($this->get('/upcoming?client_id='.$hidden->id)->assertOk()->inertiaProps()['items'])->toBe([]);
    }
})->with(['Owner', 'Member'])->with(['site', 'client', 'tag'])->with(['one-off' => false, 'recurring' => true]);

it('keeps empty client and excluded-site maintenance facts equal to the Maintenance list', function (string $role): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04T00:30:00Z'));
    $user = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $user->assignRole($role);
    $empty = Client::factory()->create(['name' => 'Empty client']);
    $client = Client::factory()->create();
    $user->clients()->attach([$empty->id, $client->id]);
    $site = Site::factory()->for($client)->create(['paused_at' => now()]);
    $window = MaintenancePeriod::factory()->create(['site_id' => $site->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour()]);
    DB::table('maintenance_site_exclusions')->insert(['maintenance_period_id' => $window->id, 'site_id' => $site->id, 'left_at' => now()->subMinute(), 'created_at' => now(), 'updated_at' => now()]);
    $emptyWindow = MaintenancePeriod::factory()->create(['site_id' => null, 'target_type' => 'client', 'target_id' => $empty->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour()]);
    $this->actingAs($user);

    $response = $this->get('/upcoming?days=14&type=maintenance')->assertOk();
    expect(collect($response->inertiaProps()['items'])->pluck('site_count')->all())->toBe([0, 0]);
    $response->assertInertia(fn ($page) => $page->loadDeferredProps(['sidebar'], fn ($deferred) => $deferred->where('upcomingCount', 2)));
    $this->get('/maintenance?client_id='.$empty->id)->assertOk()->assertInertia(fn ($page) => $page->has('windows', 1)->where('windows.0.siteCount', 0));
    $this->get('/upcoming?client_id='.$empty->id)->assertOk()->assertInertia(fn ($page) => $page
        ->has('items', 1)->where('items.0.key', 'maintenance:'.$emptyWindow->id)->where('items.0.target_label', 'Empty client')->where('items.0.site_count', 0));
})->with(['Owner', 'Member']);

it('bounds target presentation queries and keeps count uncapped without fetching standalone windows', function (int $count): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04T00:30:00Z'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $client = Client::factory()->create();
    MaintenancePeriod::factory()->count($count)->create(['site_id' => null, 'target_type' => 'client', 'target_id' => $client->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour()]);
    $this->actingAs($owner);
    DB::enableQueryLog();
    DB::flushQueryLog();

    $response = $this->get('/upcoming?days=14&type=maintenance')->assertOk();
    $queries = DB::getQueryLog();
    expect($response->inertiaProps()['items'])->toHaveCount(min($count, 100));
    expect(count($queries))->toBeLessThanOrEqual(20);
    $version = $response->inertiaPage()['version'];
    DB::flushQueryLog();
    $this->get('/sites', ['X-Inertia' => 'true', 'X-Inertia-Version' => $version,
        'X-Inertia-Partial-Component' => 'sites/index', 'X-Inertia-Partial-Data' => 'upcomingCount'])
        ->assertOk()->assertJsonPath('props.upcomingCount', $count);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    $maintenanceQueries = collect($queries)->filter(static fn (array $query): bool => str_contains($query['query'], 'from "maintenance_periods"'));
    expect($maintenanceQueries)->toHaveCount(1);
    expect($maintenanceQueries->first()['query'])->toContain('count(*)');
})->with([1, 150]);
