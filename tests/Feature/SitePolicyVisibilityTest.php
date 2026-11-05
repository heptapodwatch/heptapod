<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Enums\ClientScope;
use Heptapod\Models\Client;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Policies\SitePolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\QueryPlanWork;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-03T12:00:00Z'));
});

it('treats Owner and Admin as instance-wide despite stale membership and cleans only operator scope rows', function (string $role): void {
    $allowed = Client::factory()->create();
    $allowedSite = Site::factory()->for($allowed)->create();
    $otherSite = Site::factory()->create();
    $operator = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $operator->assignRole($role);
    $operator->clients()->attach($allowed);
    $member = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $member->assignRole('Member');
    $member->clients()->attach($allowed);
    $policy = app(SitePolicy::class);
    expect($policy->visibleSiteIds($operator))->toContain($allowedSite->id, $otherSite->id)
        ->and($operator->canAccessClient($otherSite->client_id))->toBeTrue()
        ->and($policy->visibleSiteIds($member))->toContain($allowedSite->id)->not->toContain($otherSite->id);
    $migration = require database_path('migrations/2026_10_04_034303_clear_operator_client_scopes.php');
    $migration->up();
    $migration->up();
    expect($operator->refresh()->client_scope)->toBe(ClientScope::All)
        ->and($operator->clients()->count())->toBe(0)
        ->and($member->refresh()->client_scope)->toBe(ClientScope::Restricted)
        ->and($member->clients()->count())->toBe(1);
})->with(['Owner', 'Admin']);

it('refreshes restricted membership between requests and reads current membership outside HTTP', function (): void {
    $this->withoutVite();
    $user = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $user->assignRole('Member');
    $client = Client::factory()->create();
    $site = Site::factory()->for($client)->create();

    expect(request()->route())->toBeNull();
    expect(app(SitePolicy::class)->visibleSiteIds($user))->toBe([]);
    $user->clients()->attach($client);
    expect(app(SitePolicy::class)->visibleSiteIds($user))->toBe([$site->id]);

    $this->actingAs($user)->get('/sites/create')->assertOk()->assertInertia(fn (Assert $page): Assert => $page->has('clients', 1));
    $user->clients()->detach($client);
    $this->get('/sites/create')->assertOk()->assertInertia(fn (Assert $page): Assert => $page->has('clients', 0));
});

it('keeps visible sites bounded with production-scale statistics', function (): void {
    $user = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $user->assignRole('Member');
    $clients = Client::factory()->count(300)->create();
    $user->clients()->attach($clients);
    $sites = [];
    foreach ($clients as $client) {
        foreach (range(1, 10) as $index) {
            $sites[] = ['client_id' => $client->id, 'url' => "https://site-{$client->id}-{$index}.example", 'badge_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()];
        }
    }
    foreach (array_chunk($sites, 500) as $batch) {
        DB::table('sites')->insert($batch);
    }
    Site::factory()->create();
    DB::statement('ANALYZE clients, client_user, sites');

    $this->actingAs($user)->get('/sites/create')->assertOk()->assertInertia(fn (Assert $page): Assert => $page->has('clients', 300)->missing('tags'));
    $visibility = app(SitePolicy::class)->visibleSites($user)->getQuery()->select('sites.id');
    expect($visibility->count())->toBe(3000);
    expect(QueryPlanWork::forQuery($visibility->toSql(), $visibility->getBindings()))->toBeLessThan(12 * 3000);
});
