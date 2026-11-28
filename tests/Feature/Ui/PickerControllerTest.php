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
    $this->getJson('/pickers/sites?ids='.implode(',', [$visibleSite->id, $hiddenSite->id]))->assertOk()
        ->assertJsonCount(1, 'options')->assertJsonPath('options.0.id', $visibleSite->id);
    $this->getJson('/pickers/clients?ids='.implode(',', [$hidden->id]))->assertOk()->assertJsonCount(0, 'options');
    $this->getJson('/pickers/sites?client='.$hidden->id)->assertOk()->assertJsonCount(0, 'options');
});

it('bounds a search, names every chosen id in one read and throttles per user', function (): void {
    $owner = User::factory()->owner()->create();
    $client = Client::factory()->create(['name' => 'Many']);
    $sites = Site::factory()->for($client)->count(PickerReader::Limit + 5)->create();
    $this->actingAs($owner);

    $this->getJson('/pickers/sites?client='.$client->id)->assertOk()->assertJsonCount(PickerReader::Limit, 'options')->assertJsonPath('more', true);
    // Naming chosen values is no search: every visible id comes back.
    $this->getJson('/pickers/sites?ids='.$sites->pluck('id')->implode(','))->assertOk()
        ->assertJsonCount(PickerReader::Limit + 5, 'options')->assertJsonPath('more', false);
    $this->getJson('/pickers/sites?ids='.implode(',', range(1, PickerReader::IdLimit + 1)))->assertUnprocessable();
    $this->getJson('/pickers/sites?ids=1,,2')->assertUnprocessable();

    foreach (range(5, 120) as $read) {
        $this->getJson('/pickers/clients')->assertOk();
    }
    $this->getJson('/pickers/clients')->assertTooManyRequests();
});

it('offers archived clients\' sites only where asked, labels No client and tells same-named sites apart', function (): void {
    $owner = User::factory()->owner()->create();
    $archived = Client::factory()->create(['name' => 'Old Co', 'archived_at' => now()]);
    $old = Site::factory()->for($archived)->create(['friendly_name' => 'Old shop']);
    $internal = Client::query()->where('is_internal', true)->firstOrFail();
    $shop = Site::factory()->for($internal)->create(['friendly_name' => 'Shop', 'url' => 'https://shop.test/de']);
    $twin = Site::factory()->for($internal)->create(['friendly_name' => 'Shop', 'url' => 'https://shop.test/en']);
    $this->actingAs($owner);

    $this->getJson('/pickers/sites?q=old')->assertOk()->assertJsonCount(0, 'options');
    $this->getJson('/pickers/sites?archived=1&q=old')->assertOk()->assertJsonPath('options.0.id', $old->id);
    $answer = $this->getJson('/pickers/sites?q='.urlencode('no client shop'))->assertOk()->assertJsonCount(2, 'options');
    $byId = collect($answer->json('options'))->keyBy('id');
    expect($byId[$shop->id]['client_name'])->toBe('No client')->and($byId[$shop->id]['client_is_internal'])->toBeTrue()
        ->and($byId[$shop->id]['label'])->not->toBe($byId[$twin->id]['label'])
        ->and($byId[$shop->id]['detail'])->toContain('/de');
});
