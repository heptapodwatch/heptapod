<?php

declare(strict_types=1);

use Heptapod\Models\Client;
use Heptapod\Models\Invitation;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('negotiates the recipient page and JSON without consuming or changing the invitation', function (): void {
    $token = str_repeat('a', 64);
    $client = Client::factory()->create(['name' => 'Studio Mai']);
    Site::factory()->count(2)->create(['client_id' => $client->id]);
    $invitation = Invitation::factory()->create(['token_hash' => hash('sha256', $token), 'role' => 'Member', 'client_scope' => 'restricted', 'client_ids' => [$client->id]]);
    $before = $invitation->refresh()->getAttributes();
    $users = User::query()->count();

    $this->getJson(route('invitations.show', $token))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('email', $invitation->email)->assertJsonPath('clients.0.sites_count', 2)->assertJsonMissingPath('token_hash');
    $this->get(route('invitations.show', $token))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (Assert $page): Assert => $page->component('invitations/show')->where('state', 'valid')
            ->where('invitation.email', $invitation->email)->where('invitation.role', 'Member')
            ->where('invitation.client_scope', 'restricted')->where('invitation.clients.0.sites_count', 2)
            ->missing('invitation.token_hash')->has('passwordRules'));

    expect($invitation->refresh()->getAttributes())->toBe($before)->and(User::query()->count())->toBe($users);
    $this->assertGuest();
});

it('renders truthful unavailable states with the existing JSON statuses and no invite identity', function (bool $expired, int $status, string $state): void {
    $token = str_repeat('b', 64);
    if ($expired) {
        Invitation::factory()->create(['token_hash' => hash('sha256', $token), 'expires_at' => now()->subMinute()]);
    }

    $this->getJson(route('invitations.show', $token))->assertStatus($status)->assertHeader('Cache-Control', 'no-store, private');
    $this->get(route('invitations.show', $token))->assertStatus($status)->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (Assert $page): Assert => $page->component('invitations/show')->where('state', $state)->where('invitation', null));
    $this->assertGuest();
})->with(['missing or consumed or revoked' => [false, 404, 'unavailable'], 'expired' => [true, 410, 'expired']]);
