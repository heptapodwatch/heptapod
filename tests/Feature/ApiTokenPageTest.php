<?php

declare(strict_types=1);

use Heptapod\Enums\ClientScope;
use Heptapod\Models\Client;
use Heptapod\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('requires a verified session for the self token page', function (): void {
    $this->get('/settings/api-tokens')->assertRedirect('/login');
    $this->actingAs(User::factory()->unverified()->create())->get('/settings/api-tokens')->assertRedirect('/email/verify');
});

it('keeps self-token reads available without introducing a team role gate', function (): void {
    $user = User::factory()->create();
    $client = Client::factory()->create(['name' => 'Role-protected client']);
    $token = $user->createToken('Own token', ['monitors:read']);
    $token->accessToken->forceFill(['client_id' => $client->id])->save();
    $this->actingAs($user)->getJson(route('settings.api-tokens.data'))->assertOk()
        ->assertJsonCount(1, 'tokens')->assertJsonCount(0, 'clients')->assertJsonPath('tokens.0.client_name', null);
    $this->get('/settings/api-tokens')->assertOk();
});

it('renders only safe own metadata and accessible clients including empty clients', function (): void {
    $visible = Client::factory()->create(['name' => 'Empty accessible client']);
    $hidden = Client::factory()->create(['name' => 'Hidden client name']);
    $member = User::factory()->create(['client_scope' => ClientScope::Restricted, 'timezone' => 'Europe/Berlin']);
    $member->assignRole('Member');
    $member->clients()->attach($visible);
    $own = $member->createToken('Old constrained token', ['monitors:read']);
    $own->accessToken->forceFill(['client_id' => $hidden->id, 'expires_at' => now()->subDay()])->save();
    $other = User::factory()->create();
    $other->createToken('Other account token', ['monitors:read']);

    $response = $this->actingAs($member)->get('/settings/api-tokens')->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->component('settings/api-tokens')->has('tokensPage.tokens', 1)
        ->where('tokensPage.tokens.0.id', $own->accessToken->id)
        ->where('tokensPage.tokens.0.client_id', $hidden->id)
        ->where('tokensPage.tokens.0.client_name', null)
        ->where('tokensPage.tokens.0.last_used_at', null)
        ->has('tokensPage.clients', 1)
        ->where('tokensPage.clients.0.id', $visible->id)
        ->where('tokensPage.clients.0.name', $visible->name)
        ->where('tokensPage.timezone', 'Europe/Berlin')->etc());
    expect(str_contains($response->getContent(), $own->plainTextToken))->toBeFalse();
    expect(str_contains($response->getContent(), $own->accessToken->token))->toBeFalse();
    expect(str_contains($response->getContent(), $hidden->name))->toBeFalse();
    expect(str_contains($response->getContent(), 'Other account token'))->toBeFalse();

    $member->clients()->detach($visible);
    $this->getJson(route('settings.api-tokens.data'))->assertOk()->assertJsonCount(0, 'clients')
        ->assertJsonPath('tokens.0.client_id', $hidden->id)->assertJsonPath('tokens.0.client_name', null);
});
