<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Models\Client;
use Heptapod\Models\User;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-26T12:00:00Z'));
});

it('creates a client through the existing endpoint and returns its selectable identity for JSON callers', function (): void {
    $owner = User::factory()->owner()->create();
    $response = $this->actingAs($owner)->postJson('/clients', ['name' => 'New Studio', 'short_name' => 'NS', 'contact_name' => 'Studio Contact', 'locale' => 'de', 'timezone' => 'Europe/Berlin'])->assertCreated()
        ->assertJsonPath('name', 'New Studio')->assertJsonPath('is_internal', false);
    $client = Client::query()->findOrFail($response->json('id'));
    expect($client->name)->toBe('New Studio');
    expect($client->short_name)->toBe('NS');
    expect($client->contact_name)->toBe('Studio Contact');
    expect($client->locale)->toBe('de');
    expect($client->timezone)->toBe('Europe/Berlin');
    expect($client->archived_at)->toBeNull();
    $this->post('/clients', ['name' => 'HTML Studio'])->assertRedirect();
    expect(Client::query()->where('name', 'HTML Studio')->exists())->toBeTrue();
});

it('accepts the server IANA zones the timezone picker offers on create and update', function (): void {
    $owner = User::factory()->owner()->create();
    $response = $this->actingAs($owner)->postJson('/clients', ['name' => 'Kolkata Studio', 'timezone' => 'Asia/Kolkata'])->assertCreated();
    $client = Client::query()->findOrFail($response->json('id'));
    expect($client->timezone)->toBe('Asia/Kolkata');
    $this->actingAs($owner)->patch('/clients/'.$client->id, ['timezone' => 'Europe/Kyiv'])->assertRedirect();
    expect($client->refresh()->timezone)->toBe('Europe/Kyiv');
});

it('uses the existing client validation without writing an invalid client', function (): void {
    $owner = User::factory()->owner()->create();
    $count = Client::query()->count();
    $this->actingAs($owner)->postJson('/clients', ['name' => str_repeat('x', 256)])->assertUnprocessable()->assertJsonValidationErrors('name');
    expect(Client::query()->count())->toBe($count);
});

it('does not allow a Member to create a client inline', function (): void {
    $member = User::factory()->member()->create();
    $count = Client::query()->count();
    $this->actingAs($member)->postJson('/clients', ['name' => 'Not allowed'])->assertForbidden();
    expect(Client::query()->count())->toBe($count);
});
