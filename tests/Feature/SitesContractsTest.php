<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Heptapod\Contracts\CheckResult;
use Heptapod\Enums\ClientScope;
use Heptapod\Models\Check;
use Heptapod\Models\Client;
use Heptapod\Models\DispatcherHeartbeat;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Queries\RunsReader;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-03T12:00:00Z'));
});

it('does not grant foreign clients to a restricted member with stale presentation selection', function (): void {
    $this->withoutVite();
    $member = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $member->assignRole('Member');
    $allowed = Client::factory()->create();
    $member->clients()->attach($allowed);
    $hidden = Client::factory()->create();

    Site::factory()->for($allowed)->create();
    Site::factory()->for($hidden)->create();
    $response = $this->actingAs($member)->withSession(['selected_client_id' => $hidden->id])->get('/sites', [
        'X-Inertia-Partial-Component' => 'sites/index',
        'X-Inertia-Partial-Data' => 'sites,clients',
    ])->assertOk();
    $response->assertSessionMissing('selected_client_id');
    expect(count($response->inertiaProps()['sites']))->toBe(1)
        ->and(collect($response->inertiaProps()['clients'])->pluck('id')->all())->toBe([$allowed->id]);
    $this->get('/clients/'.$hidden->id)->assertNotFound();
    $this->post('/client-scope', ['client_id' => $hidden->id])->assertNotFound();
});

it('shows every authorized client and clears stale presentation selection on the next request', function (): void {
    $this->withoutVite();
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $first = Site::factory()->create();
    $second = Site::factory()->create();
    Check::factory()->for($first)->create();
    Check::factory()->for($second)->create();
    DispatcherHeartbeat::factory()->create();
    $response = $this->actingAs($owner)->withSession(['selected_client_id' => $first->client_id])->get('/sites', [
        'X-Inertia-Partial-Component' => 'sites/index',
        'X-Inertia-Partial-Data' => 'sites,clients',
    ])->assertOk();
    $response->assertSessionMissing('selected_client_id');
    $props = $response->inertiaProps();
    expect(count($props['sites']))->toBe(2)
        ->and($props)->not->toHaveKey('clientScope')
        ->and(collect($props['clients'])->pluck('id')->all())->toContain($first->client_id, $second->client_id);
    $this->post('/client-scope', ['client_id' => $first->client_id])->assertNotFound();
});

it('keeps explicit list client filters independent of stale session selection and authorization', function (string $uri, string $rows, string $filter, string $role): void {
    $this->withoutVite();
    $this->freezeTime();
    $user = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $user->assignRole($role);
    $first = Client::factory()->create();
    $second = Client::factory()->create();
    $foreign = Client::factory()->create();
    $user->clients()->attach([$first->id, $second->id]);
    foreach ([$first, $second, $foreign] as $client) {
        $check = Check::factory()->for(Site::factory()->for($client))->create();
        Incident::factory()->for($check)->create();
        Event::factory()->for($check)->create(['occurred_at' => now(), 'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Warning]);
    }
    $this->actingAs($user)->withSession(['selected_client_id' => $first->id]);

    $headers = $uri === '/sites' ? [
        'X-Inertia-Partial-Component' => 'sites/index',
        'X-Inertia-Partial-Data' => 'sites',
    ] : [];
    $response = $this->get($uri, $headers)->assertOk()->assertSessionMissing('selected_client_id');
    expect($response->inertiaProps()[$rows])->toHaveCount($role === 'Member' ? 2 : 3);
    $response = $this->withSession(['selected_client_id' => $first->id])->get($uri.'?'.$filter.'='.$second->id, $headers)->assertOk();
    expect($response->inertiaProps()[$rows])->toHaveCount(1);
    $response = $this->get($uri.'?'.$filter.'='.$foreign->id, $headers)->assertOk();
    expect($response->inertiaProps()[$rows])->toHaveCount($role === 'Member' ? 0 : 1);
})->with([
    ['/sites', 'sites', 'filter[client_id]'],
    ['/incidents', 'incidents', 'filter[client_id]'],
    ['/activity', 'events', 'client'],
])->with(['Owner', 'Member']);

it('counts late runs with the check-page engine definition and excludes foreign paused disabled and queued checks', function (): void {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-10-03T12:00:00Z'));
    $user = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $user->assignRole('Member');
    $client = Client::factory()->create();
    $user->clients()->attach($client);
    $site = Site::factory()->for($client)->create();
    $late = Check::factory()->for($site)->create(['interval_seconds' => 60, 'next_run_at' => now()->subSeconds(61)]);
    Check::factory()->for(Site::factory()->for($client))->create(['interval_seconds' => 60, 'next_run_at' => now()->subSeconds(60)]);
    Check::factory()->for(Site::factory()->for($client))->create(['enabled' => false, 'next_run_at' => now()->subHour()]);
    Check::factory()->for(Site::factory()->for($client)->create(['paused_at' => now()]))->create(['next_run_at' => now()->subHour()]);
    Check::factory()->create(['next_run_at' => now()->subHour()]);

    $this->actingAs($user)->get('/sites')->assertOk()->assertInertia(fn ($page) => $page->missing('lateRunCount')
        ->loadDeferredProps(['sidebar'], fn ($deferred) => $deferred->missing('lateRunCount')));
    expect(app(RunsReader::class)->lateCount($user))->toBe(1);
    $this->get('/sites/'.$site->id.'/checks/'.$late->id)->assertOk()->assertInertia(fn ($page) => $page->where('schedule.run.phase', 'late'));
    $this->post('/sites/'.$site->id.'/checks/'.$late->id.'/run')->assertForbidden();
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $this->actingAs($owner)->post('/sites/'.$site->id.'/checks/'.$late->id.'/run')->assertRedirect();
    $this->actingAs($user);
    expect(app(RunsReader::class)->lateCount($user))->toBe(0);
    $this->get('/sites/'.$site->id.'/checks/'.$late->id)->assertOk()->assertInertia(fn ($page) => $page->where('schedule.run.phase', 'queued'));
});

it('keeps guest and seeded sites response headers within default reverse proxy buffers', function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DemoSeeder::class);
    $responses = [
        $this->get('/login')->assertOk(),
        $this->actingAs(User::query()->where('email', DatabaseSeeder::DEMO_EMAIL)->sole())->get('/sites')->assertOk(),
    ];

    foreach ($responses as $response) {
        $headerBytes = 2;
        foreach ($response->headers->all() as $name => $values) {
            foreach ($values as $value) {
                $headerBytes += strlen($name) + strlen($value) + 4;
            }
        }
        expect($headerBytes)->toBeLessThan(4096);
    }
});
