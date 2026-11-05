<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Heptapod\Models\Check;
use Heptapod\Models\Client;
use Heptapod\Models\Incident;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('serves real monitoring DTOs without a deployment flag', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-28T12:00:00Z'));
    $this->seed(DemoSeeder::class);
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $site = Site::query()->where('friendly_name', 'Backup')->firstOrFail();
    $check = Check::query()->where('site_id', $site->id)->where('type', 'cron')->firstOrFail();
    $incident = Incident::query()->where('site_id', $site->id)->firstOrFail();
    $client = Client::query()->findOrFail($site->client_id);

    $this->actingAs($user)->get('/dashboard')->assertMovedPermanently()->assertRedirect('/sites');
    $this->get('/')->assertMovedPermanently()->assertRedirect('/sites');
    $this->get('/sites')->assertInertia(fn (Assert $page): Assert => $page->component('sites/index')->where('tiles.sites', 74)->where('pagination.total', 74));
    $this->get('/sites/'.$site->id)->assertInertia(fn (Assert $page): Assert => $page->component('sites/show')->where('site.name', 'Backup'));
    $this->get('/sites/'.$site->id.'/checks/'.$check->id)->assertInertia(fn (Assert $page): Assert => $page->component('sites/show')->where('check.id', $check->id));
    $this->get('/sites/create')->assertInertia(fn (Assert $page): Assert => $page->component('sites/create')->has('checks'));
    $this->get('/incidents')->assertInertia(fn (Assert $page): Assert => $page->component('incidents/index')->has('incidents'));
    $this->get('/incidents/'.$incident->id)->assertInertia(fn (Assert $page): Assert => $page->component('incidents/show')->where('incident.id', $incident->id));
    // CI run 41561079596 failed here with a bare "Not a valid Inertia response"; name what came back instead.
    $clients = $this->get('/clients');
    expect($clients->status())->toBe(200, sprintf('GET /clients answered %d, Location %s, exception %s', $clients->status(), $clients->headers->get('Location') ?? 'none', $clients->exception?->getMessage() ?? 'none'));
    $clients->assertInertia(fn (Assert $page): Assert => $page->component('clients/index')->has('clients'));
    $this->get('/clients/'.$client->id)->assertInertia(fn (Assert $page): Assert => $page->component('clients/show')->where('client.id', $client->id));
    $this->getJson('/search?q=Backup')->assertOk()->assertJsonPath('sites.0.id', $site->id);
});
