<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckResult;
use Heptapod\Enums\ClientScope;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Client;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-29T12:00:00Z'));
    // Eloquent caches Event's guarded columns process-wide; exclude its one-time schema inspection.
    new Event()->isGuarded('payload');
});

it('scopes client list and detail summaries, sites and incidents without secrets', function (): void {
    $this->withoutVite();
    $this->travelTo(now()->startOfMinute());
    $allowed = Client::factory()->create();
    $hidden = Client::factory()->create();
    $user = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $user->assignRole('Member');
    $user->clients()->attach($allowed);
    $secret = 'client-page-real-secret';
    $site = Site::factory()->for($allowed)->create(['crawler_headers' => [['name' => 'Authorization', 'value' => $secret]]]);
    $check = Check::factory()->for($site)->create(['latest_run_result' => CheckResult::Failed]);
    $incident = Incident::factory()->for($check)->create(['evidence' => ['token' => $secret]]);
    CheckRun::factory()->for($check)->create(['started_at' => now()->subMinutes(2), 'ended_at' => now()->subMinute()]);
    $hiddenSite = Site::factory()->for($hidden)->create();
    Check::factory()->for($hiddenSite)->create(['latest_run_result' => CheckResult::Warning]);

    $list = $this->actingAs($user)->get('/clients')->assertInertia(fn (Assert $page): Assert => $page->component('clients/index')
        ->has('clients', 1)->where('clients.0.client.id', $allowed->id)->where('clients.0.summary.sites', 1)->where('clients.0.summary.down', 1)
        ->where('clients.0.summary.open_incidents', 1)->where('clients.0.summary.warning', 0)
        ->where('clients.0.summary.check_errors', 0)->where('clients.0.summary.uptime', null));
    $detail = $this->get('/clients/'.$allowed->id)->assertInertia(fn (Assert $page): Assert => $page->component('clients/show')
        ->where('client.id', $allowed->id)->where('summary.down', 1)->has('sites', 1)->where('sites.0.id', $site->id)
        ->has('incidents', 1)->where('incidents.0.id', $incident->id));
    foreach ([$list, $detail] as $response) {
        expect(json_encode($response->inertiaProps(), JSON_THROW_ON_ERROR))->not->toContain($secret);
    }
    $this->get('/clients/'.$hidden->id)->assertNotFound();
});

it('keeps client summaries batched as the client count grows', function (int $count): void {
    $this->withoutVite();
    $user = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $user->assignRole('Member');
    $addClients = static function (int $clientCount) use ($user): void {
        $clients = Client::factory()->count($clientCount)->create();
        $user->clients()->attach($clients);
        foreach ($clients as $client) {
            $site = Site::factory()->for($client)->create(['url' => 'https://client-'.$client->id.'.example.test']);
            Check::factory()->for($site)->create(['latest_run_result' => CheckResult::Warning]);
        }
    };
    $measure = function (int $total): int {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $response = $this->get('/clients');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $response->assertInertia(fn (Assert $page): Assert => $page->has('clients', min(50, $total))->where('pagination.total', $total)->where('clients.0.summary.warning', 1));

        return $queries;
    };
    $this->actingAs($user);
    $addClients(2);
    $few = $measure(2);
    $addClients($count - 2);
    $many = $measure($count);

    expect($many)->toBeLessThanOrEqual($few, 'the client list grew from '.$few.' to '.$many.' queries as clients grew from 2 to '.$count);
    // Upper budget: page shell and shell health (25458fc44/0a385128), the client page with batched summaries
    // and avatars (36aca5a1a), and the viewer's own channels needing repair (N4a).
    expect($few)->toBeLessThanOrEqual(22, 'the client list used '.$few.' queries, over its budget of 22');
})->with([30, 300]);

it('counts each site state explicitly without treating inactive or failed sites as healthy', function (int $clientCount): void {
    $this->withoutVite();
    $this->travelTo(now()->startOfMinute());
    $user = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $user->assignRole('Member');
    $client = Client::factory()->create(['name' => 'All states']);
    $others = Client::factory()->count($clientCount - 1)->create(['name' => 'Other client']);
    $user->clients()->attach([$client->id, ...$others->modelKeys()]);
    Check::factory()->create(['latest_run_result' => CheckResult::Succeeded]);
    foreach (['down', 'failed', 'check-error', 'warning', 'pending', 'ok', 'maintenance', 'paused', 'disabled'] as $state) {
        $site = Site::factory()->for($client)->create(['paused_at' => $state === 'paused' ? now() : null]);
        if ($state === 'disabled') {
            continue;
        }
        if ($state === 'maintenance') {
            MaintenancePeriod::factory()->create(['site_id' => $site->id]);
        }
        $check = Check::factory()->for($site)->create([
            'type' => $state === 'failed' ? CheckKey::CertificateHealth : CheckKey::Uptime,
            'latest_run_result' => match ($state) {
                'down', 'failed' => CheckResult::Failed,
                'check-error' => CheckResult::Errored,
                'warning' => CheckResult::Warning,
                'pending' => CheckResult::Pending,
                default => CheckResult::Succeeded,
            },
        ]);
        if ($state === 'down') {
            Incident::factory()->for($check)->create();
        }
    }
    $this->actingAs($user);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $response = $this->get('/clients')->assertOk();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    $rows = $response->inertiaProps()['clients'];
    expect($rows)->toHaveCount($clientCount);
    expect($rows[0]['client']['id'])->toBe($client->id);
    expect($rows[0]['summary']['sites'])->toBe(9);
    foreach (['down', 'failed', 'check_errors', 'warning', 'pending', 'ok', 'maintenance', 'paused', 'disabled'] as $key) {
        expect($rows[0]['summary'][$key] ?? null)->toBe(1);
        if ($clientCount > 1) {
            expect($rows[1]['summary'][$key] ?? null)->toBe(0);
        }
    }
    // The same upper budget as the client list above; growth with the client count is owned by that test.
    expect(count($queries))->toBeLessThanOrEqual(22, 'the client list used '.count($queries).' queries, over its budget of 22');
})->with([1, 50]);

it('sorts clients with failures first and filters attention across all pages', function (): void {
    $this->withoutVite();
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $healthy = Client::factory()->create(['name' => 'A healthy client']);
    $affected = Client::factory()->create(['name' => 'Z affected client']);
    Check::factory()->for(Site::factory()->for($healthy)->create())->create(['latest_run_result' => CheckResult::Succeeded]);
    Check::factory()->for(Site::factory()->for($affected)->create())->create(['latest_run_result' => CheckResult::Warning]);

    $this->actingAs($owner)->get('/clients')->assertInertia(fn (Assert $page): Assert => $page
        ->where('clients.0.client.id', $affected->id)->where('state', 'all'));
    $this->get('/clients?filter[state]=attention')->assertInertia(fn (Assert $page): Assert => $page
        ->has('clients', 1)->where('clients.0.client.id', $affected->id)->where('state', 'attention'));
    $this->get('/clients?filter[state]=invalid')->assertSessionHasErrors('filter.state');
});

it('denies roleless client reads', function (): void {
    $client = Client::factory()->create();
    $this->actingAs(User::factory()->create())->get('/clients')->assertForbidden();
    $this->get('/clients/'.$client->id)->assertForbidden();
});

it('paginates client sites with a fixed detail query budget', function (int $count): void {
    $this->withoutVite();
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $client = Client::factory()->create();
    $addSites = static function (int $siteCount, int $offset) use ($client): void {
        foreach (Site::factory()->count($siteCount)->for($client)->sequence(fn ($sequence): array => ['url' => 'https://site-'.($offset + $sequence->index).'.example.test'])->create() as $site) {
            Check::factory()->for($site)->create(['latest_run_result' => CheckResult::Warning]);
        }
    };
    $measure = function (int $total) use ($client): int {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $response = $this->get('/clients/'.$client->id);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $response->assertInertia(fn (Assert $page): Assert => $page->has('sites', min(50, $total))->where('pagination.total', $total)->where('summary.warning', $total));

        return $queries;
    };
    $this->actingAs($user);
    $addSites(2, 0);
    $few = $measure(2);
    $addSites($count - 2, 2);
    $many = $measure($count);

    expect($many)->toBeLessThanOrEqual($few, 'the client detail grew from '.$few.' to '.$many.' queries as sites grew from 2 to '.$count);
    // Upper budget: page shell and shell health (25458fc44/0a385128), the client with batched avatars (36aca5a1a),
    // and one page of sites with batched checks, states and dependency holds (bef4f4911).
    expect($few)->toBeLessThanOrEqual(37, 'the client detail used '.$few.' queries, over its budget of 37');
})->with([30, 300]);
