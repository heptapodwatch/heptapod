<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Enums\ClientScope;
use Heptapod\Models\Client;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Queries\PickerReader;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-28T12:00:00Z'));
});

it('searches and names only the sites and clients a client-restricted member can see', function (): void {
    $member = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $member->assignRole('Member');
    $mine = Client::factory()->create(['name' => 'Acme visible']);
    $member->clients()->attach($mine);
    $hidden = Client::factory()->create(['name' => 'Acme hidden']);
    $visibleSite = Site::factory()->for($mine)->create(['friendly_name' => 'Acme shop']);
    $hiddenSite = Site::factory()->for($hidden)->create(['friendly_name' => 'Acme blog']);
    $this->actingAs($member);

    $this->getJson('/pickers/sites?q=acme')->assertOk()->assertJsonPath('more', false)
        ->assertJsonCount(1, 'options')->assertJsonPath('options.0.id', $visibleSite->id)->assertJsonPath('options.0.client_name', 'Acme visible');
    $this->getJson('/pickers/clients?q=acme')->assertOk()->assertJsonCount(1, 'options')->assertJsonPath('options.0.id', $mine->id);
    // Each word matches on its own, the client name included, as the pickers matched before they searched on the server.
    $this->getJson('/pickers/sites?q='.urlencode('visible shop'))->assertOk()->assertJsonCount(1, 'options')->assertJsonPath('options.0.id', $visibleSite->id);
    $this->getJson('/pickers/sites?q='.urlencode('visible blog'))->assertOk()->assertJsonCount(0, 'options');
    // A hidden id is named exactly like a missing one: not at all.
    $this->getJson('/pickers/sites?'.http_build_query(['ids' => [$visibleSite->id, $hiddenSite->id]]))->assertOk()
        ->assertJsonCount(1, 'options')->assertJsonPath('options.0.id', $visibleSite->id);
    $this->getJson('/pickers/clients?'.http_build_query(['ids' => [$hidden->id]]))->assertOk()->assertJsonCount(0, 'options');
    $this->getJson('/pickers/sites?client='.$hidden->id)->assertOk()->assertJsonCount(0, 'options');
});

it('bounds a search and says that more match', function (): void {
    $owner = User::factory()->owner()->create();
    $client = Client::factory()->create(['name' => 'Many']);
    Site::factory()->for($client)->count(PickerReader::Limit + 1)->create();
    $this->actingAs($owner);

    $this->getJson('/pickers/sites?client='.$client->id)->assertOk()->assertJsonCount(PickerReader::Limit, 'options')->assertJsonPath('more', true);
    $this->getJson('/pickers/sites?'.http_build_query(['ids' => range(1, PickerReader::IdLimit + 1)]))->assertUnprocessable();
});
