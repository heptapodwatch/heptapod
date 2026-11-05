<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Enums\ClientScope;
use Heptapod\Models\Check;
use Heptapod\Models\Client;
use Heptapod\Models\Incident;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-03T12:00:00Z'));
});

it('reads only selected sites props during a live partial reload', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Owner');
    Check::factory()->create();
    $version = $this->actingAs($user)->get('/sites')->inertiaPage()['version'];
    $queries = [];
    DB::listen(static function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $response = $this->actingAs($user)->get('/sites', [
        'X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Inertia-Partial-Component' => 'sites/index', 'X-Inertia-Partial-Data' => 'tiles',
    ])->assertOk();

    expect($response->json('props'))->toHaveKey('tiles')->not->toHaveKeys(['sites', 'needs_attention', 'summary']);
    // The tags prop runs its own TagsReader query; a partial reload that does not ask for tags must not evaluate it.
    expect(collect($queries)->filter(static fn (string $sql): bool => str_contains($sql, 'from "tags"') || str_contains($sql, 'from "taggables"')))->toBeEmpty();
});

it('evaluates only requested detail props while ignoring stale presentation selection', function (): void {
    $user = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $user->assignRole('Member');
    $selected = Client::factory()->create(['name' => 'Selected client']);
    $other = Client::factory()->create();
    $user->clients()->attach([$selected->id, $other->id]);
    $check = Check::factory()->for(Site::factory()->for($other))->create();
    $incident = Incident::factory()->for($check)->create(['notes' => 'Review me']);
    $this->actingAs($user)->withSession(['selected_client_id' => $selected->id]);

    foreach ([
        ["/clients/{$other->id}", 'clients/show', 'incidents', 'WITH visible AS MATERIALIZED'],
        ["/incidents/{$incident->id}", 'incidents/show', 'internal_note', 'episode_events AS MATERIALIZED'],
        ["/sites/{$check->site_id}", 'sites/show', 'events', 'from "check_run_rollups"'],
        ["/sites/{$check->site_id}/checks/{$check->id}", 'sites/show', 'runs', 'from "check_run_rollups"'],
    ] as [$uri, $component, $prop, $excludedQuery]) {
        $version = $this->get($uri)->assertOk()->inertiaPage()['version'];
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $response = $this->get($uri, [
            'X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Inertia-Partial-Component' => $component, 'X-Inertia-Partial-Data' => $prop,
        ])->assertOk();
        expect($response->json('props'))->toHaveKey($prop)->not->toHaveKey('outside_scope');
        expect(collect($queries)->filter(static fn (string $sql): bool => str_contains($sql, $excludedQuery)))->toHaveCount(0);
    }

    $version = $this->get("/incidents/{$incident->id}")->inertiaPage()['version'];
    $response = $this->get("/incidents/{$incident->id}", [
        'X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Inertia-Partial-Component' => 'incidents/show', 'X-Inertia-Partial-Data' => 'outside_scope,scope_name',
    ])->assertOk();
    expect($response->json('props'))->not->toHaveKeys(['outside_scope', 'scope_name']);
    $this->get('/incidents/'.$incident->id)->assertOk()->assertSessionMissing('selected_client_id');
});

it('rejects an inaccessible incident before resolving unrelated partial props', function (): void {
    $user = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $user->assignRole('Member');
    $user->clients()->attach(Client::factory()->create());
    $hidden = Incident::factory()->create();
    $version = $this->actingAs($user)->get('/sites')->inertiaPage()['version'];

    $this->get("/incidents/{$hidden->id}", [
        'X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Inertia-Partial-Component' => 'incidents/show', 'X-Inertia-Partial-Data' => 'sidebarOpen',
    ])->assertNotFound();
});

it('authorizes entity detail before resolving unrelated partial props', function (string $page): void {
    $user = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $user->assignRole('Member');
    $user->clients()->attach(Client::factory()->create());
    $foreign = Client::factory()->create();
    $check = Check::factory()->for(Site::factory()->for($foreign))->create();
    $uri = match ($page) {
        'site' => "/sites/{$check->site_id}",
        'check' => "/sites/{$check->site_id}/checks/{$check->id}",
        'client' => "/clients/{$foreign->id}",
    };
    $component = $page === 'client' ? 'clients/show' : 'sites/show';
    $version = $this->actingAs($user)->get('/settings/profile')->inertiaPage()['version'];
    $headers = [
        'X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Inertia-Partial-Component' => $component, 'X-Inertia-Partial-Data' => 'sidebarOpen',
    ];

    $this->get($uri, $headers)->assertNotFound();
    $user->syncRoles([]);
    $this->get($uri, $headers)->assertForbidden();
})->with(['site', 'check', 'client']);

it('keeps collection routes forbidden on partial reloads for roleless users', function (string $uri, string $component): void {
    $user = User::factory()->create();
    $version = $this->actingAs($user)->get('/settings/profile')->inertiaPage()['version'];

    $this->get($uri, [
        'X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Inertia-Partial-Component' => $component, 'X-Inertia-Partial-Data' => 'sidebarOpen',
    ])->assertForbidden();
})->with([
    ['/activity', 'activity/index'],
    ['/clients', 'clients/index'],
    ['/incidents', 'incidents/index'],
    ['/sites', 'sites/index'],
]);

it('does not query member channel scopes for an unrelated partial prop', function (): void {
    $user = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $user->assignRole('Member');
    $user->clients()->attach(Client::factory()->create());
    $version = $this->actingAs($user)->get('/sites')->inertiaPage()['version'];
    $queries = [];
    DB::listen(static function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $response = $this->get('/sites', [
        'X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Inertia-Partial-Component' => 'sites/index', 'X-Inertia-Partial-Data' => 'sidebarOpen',
    ])->assertOk();

    expect($response->json('props'))->toHaveKey('sidebarOpen')->not->toHaveKey('liveChannels');
    expect(collect($queries)->filter(static fn (string $sql): bool => str_contains($sql, 'client_user')))->toBeEmpty();
});

it('skips overview history when refreshing only site events', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $check = Check::factory()->create();
    $uri = "/sites/{$check->site_id}";
    $version = $this->actingAs($user)->get($uri)->inertiaPage()['version'];
    $queries = [];
    DB::listen(static function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $response = $this->get($uri, [
        'X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Inertia-Partial-Component' => 'sites/show', 'X-Inertia-Partial-Data' => 'events',
    ])->assertOk();

    expect($response->json('props'))->toHaveKey('events')->not->toHaveKey('history');
    expect(collect($queries)->filter(static fn (string $sql): bool => str_contains($sql, 'check_run_rollups')))->toBeEmpty();
});

it('skips check rollups and history when refreshing only run rows', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $check = Check::factory()->create();
    $uri = "/sites/{$check->site_id}/checks/{$check->id}";
    $version = $this->actingAs($user)->get($uri)->inertiaPage()['version'];
    $queries = [];
    DB::listen(static function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $response = $this->get($uri, [
        'X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Inertia-Partial-Component' => 'sites/show', 'X-Inertia-Partial-Data' => 'runs',
    ])->assertOk();

    expect($response->json('props'))->toHaveKey('runs')->not->toHaveKey('metrics');
    expect(collect($queries)->filter(static fn (string $sql): bool => str_contains($sql, 'check_run_rollups')))->toBeEmpty();
});
