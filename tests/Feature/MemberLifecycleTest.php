<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Contracts\LiveConnectionTerminator;
use Heptapod\Enums\ClientScope;
use Heptapod\Enums\NotificationCategory;
use Heptapod\Models\Check;
use Heptapod\Models\Client;
use Heptapod\Models\Invitation;
use Heptapod\Models\User;
use Heptapod\Notifications\PasswordReset;
use Heptapod\Notifications\TeamInvitation;
use Heptapod\Testing\FakeLiveConnectionTerminator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Passkeys\Passkeys;

require_once __DIR__.'/../Support/NotificationChannels.php';

it('issues a seven-day scoped invite and consumes it once into a verified member account', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05T00:30:00Z'));
    Notification::fake();
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $client = Client::factory()->create();
    $this->actingAs($owner)->postJson(route('settings.members.invite'), [
        'email' => 'colleague@example.test', 'role' => 'Member', 'client_ids' => [$client->id],
    ])->assertCreated();
    $invitation = Invitation::query()->sole();
    expect($invitation->expires_at->toIso8601String())->toBe('2026-10-12T00:30:00+00:00');
    $url = null;
    Notification::assertSentOnDemand(TeamInvitation::class, function (TeamInvitation $notification) use (&$url): bool {
        $url = $notification->actionUrl();

        return true;
    });
    expect($url)->toBeString()->and($invitation->token_hash)->not->toContain(basename(parse_url($url, PHP_URL_PATH)));
    $this->post(route('logout'));
    $token = basename(parse_url($url, PHP_URL_PATH));
    $this->getJson(route('invitations.show', $token))->assertOk()->assertJsonPath('clients.0.id', $client->id)
        ->assertJsonMissing(['token_hash' => $invitation->token_hash]);
    $this->post(route('invitations.accept', $token), ['name' => 'Colleague', 'email' => 'changed@example.test',
        'password' => 'password', 'password_confirmation' => 'password'])->assertSessionHasErrors('email');
    $this->post(route('invitations.accept', $token), ['name' => 'Colleague', 'password' => 'password',
        'password_confirmation' => 'password'])->assertRedirect(route('sites.index'));
    $member = User::query()->where('email', 'colleague@example.test')->sole();
    expect($member->hasVerifiedEmail())->toBeTrue()->and($member->client_scope)->toBe(ClientScope::Restricted)
        ->and($member->clients()->get()->modelKeys())->toBe([$client->id]);
    $this->post(route('logout'));
    $this->post(route('invitations.accept', $token), ['name' => 'Replay', 'password' => 'password',
        'password_confirmation' => 'password'])->assertNotFound();
});

it('cuts revoked member access across sessions tokens and live channels and blocks sign-in and reset', function (): void {
    Notification::fake();
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $client = Client::factory()->create();
    $member = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $member->assignRole('Member');
    $member->clients()->attach($client);
    $token = $member->createToken('prior', ['monitors:read'])->plainTextToken;
    $terminator = new FakeLiveConnectionTerminator;
    app()->instance(LiveConnectionTerminator::class, $terminator);
    DB::table('sessions')->insert(['id' => 'member-session', 'user_id' => $member->id,
        'payload' => '', 'last_activity' => now()->timestamp]);
    $this->actingAs($owner)->deleteJson(route('settings.members.remove', $member->id))->assertNoContent();
    expect($member->refresh()->disabled_at)->not->toBeNull();
    expect($terminator->terminated)->toBe([$member->id]);
    expect($member->tokens()->count())->toBe(0);
    expect(DB::table('sessions')->where('user_id', $member->id)->count())->toBe(0);
    $this->withToken($token)->getJson('/api/monitors')->assertUnauthorized();
    config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret', 'broadcasting.connections.reverb.app_id' => 'test-app']);
    $this->actingAs($member)->postJson('/broadcasting/auth', ['socket_id' => '1.1',
        'channel_name' => 'presence-client.'.$client->id])->assertUnauthorized();
    $this->post(route('login'), ['email' => $member->email, 'password' => 'password'])->assertSessionHasErrors('email');
    $this->post(route('password.email'), ['email' => $member->email]);
    Notification::assertNotSentTo($member, PasswordReset::class);
    $passkey = $member->passkeys()->create(['name' => 'Before removal', 'credential_id' => 'removed-key', 'credential' => []]);
    expect(Passkeys::allowsLogin(request(), $passkey))->toBeFalse();
    // A removed member's own channel no longer hears alerts: the explanation names the lost access.
    $channel = notificationAccountChannel($member, [NotificationCategory::Outage]);
    $check = Check::factory()->create();
    $recipients = collect($this->actingAs($owner)->getJson(route('notifications.explain', [
        'check_id' => $check->id, 'category' => NotificationCategory::Outage->value, 'at' => now()->utc()->format('Y-m-d\TH:i:sP'),
    ]))->assertOk()->json('recipients'))->keyBy('channel_id');
    expect($recipients[$channel->id])->toMatchArray(['status' => 'not_sent', 'reason' => 'no_site_access']);
});

it('revokes scope immediately and denies a revoked Reverb channel on resubscription', function (): void {
    config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret', 'broadcasting.connections.reverb.app_id' => 'test-app']);
    $terminator = new FakeLiveConnectionTerminator;
    app()->instance(LiveConnectionTerminator::class, $terminator);
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $one = Client::factory()->create();
    $two = Client::factory()->create();
    $member = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $member->assignRole('Member');
    $member->clients()->attach([$one->id, $two->id]);
    $member->createToken('before-scope-change', ['monitors:read']);
    $this->actingAs($member)->postJson('/broadcasting/auth', ['socket_id' => '1.1',
        'channel_name' => 'presence-client.'.$two->id])->assertOk();
    $this->actingAs($owner)->patchJson(route('settings.members.update', $member->id), [
        'role' => 'Member', 'client_ids' => [$one->id],
    ])->assertNoContent();
    expect($member->tokens()->count())->toBe(0)->and($terminator->terminated)->toBe([$member->id]);
    $this->actingAs($member->refresh())->postJson('/broadcasting/auth', ['socket_id' => '1.1',
        'channel_name' => 'presence-client.'.$two->id])->assertForbidden();
    $this->postJson('/broadcasting/auth', ['socket_id' => '1.1',
        'channel_name' => 'presence-client.'.$one->id])->assertOk();
});

it('revokes legacy all-client access when only the persisted scope changes', function (): void {
    $terminator = new FakeLiveConnectionTerminator;
    app()->instance(LiveConnectionTerminator::class, $terminator);
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $client = Client::factory()->create();
    $member = User::factory()->create(['client_scope' => ClientScope::All]);
    $member->assignRole('Member');
    $member->clients()->attach($client);
    $member->createToken('legacy-access', ['monitors:read']);
    DB::table('sessions')->insert(['id' => 'legacy-member-session', 'user_id' => $member->id,
        'payload' => '', 'last_activity' => now()->timestamp]);

    $this->actingAs($owner)->patchJson(route('settings.members.update', $member->id), [
        'role' => 'Member', 'client_ids' => [$client->id],
    ])->assertNoContent();

    expect($member->refresh()->client_scope)->toBe(ClientScope::Restricted)
        ->and($member->clients()->get()->modelKeys())->toBe([$client->id])
        ->and($member->tokens()->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $member->id)->count())->toBe(0)
        ->and($terminator->terminated)->toBe([$member->id]);
});

it('protects the last Owner from removal or demotion', function (): void {
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $this->actingAs($owner)->deleteJson(route('settings.members.remove', $owner->id))->assertUnprocessable();
    $this->patchJson(route('settings.members.update', $owner->id), ['role' => 'Admin', 'client_ids' => []])->assertUnprocessable();
    expect($owner->refresh()->hasRole('Owner'))->toBeTrue()->and($owner->disabled_at)->toBeNull();
});

it('tracks the last authenticated request without writing on every request', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05T00:30:00Z'));
    $member = User::factory()->create();
    $member->assignRole('Member');
    $this->actingAs($member)->getJson(route('settings.notifications.data'))->assertOk();
    $first = $member->refresh()->last_active_at;
    $this->travel(30)->seconds();
    $this->getJson(route('settings.notifications.data'))->assertOk();
    expect($member->refresh()->last_active_at->equalTo($first))->toBeTrue();
    $this->travel(31)->seconds();
    $this->getJson(route('settings.notifications.data'))->assertOk();
    expect($member->refresh()->last_active_at->greaterThan($first))->toBeTrue();
});

it('reactivates an invited account without creating a second user', function (): void {
    Notification::fake();
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $client = Client::factory()->create();
    $member = User::factory()->create(['email' => 'returning@example.test', 'client_scope' => ClientScope::Restricted]);
    $member->assignRole('Member');
    $member->forceFill(['disabled_at' => now()])->save();
    $this->actingAs($owner)->postJson(route('settings.members.invite'), [
        'email' => $member->email, 'role' => 'Member', 'client_ids' => [$client->id],
    ])->assertCreated();
    $url = null;
    Notification::assertSentOnDemand(TeamInvitation::class, function (TeamInvitation $notification) use (&$url): bool {
        $url = $notification->actionUrl();

        return true;
    });
    $this->post(route('logout'));
    $this->post(route('invitations.accept', basename(parse_url($url, PHP_URL_PATH))), [
        'name' => 'Returned', 'password' => 'new-password', 'password_confirmation' => 'new-password',
    ])->assertRedirect(route('sites.index'));
    expect(User::query()->where('email', $member->email)->count())->toBe(1)
        ->and($member->refresh()->disabled_at)->toBeNull()
        ->and($member->hasVerifiedEmail())->toBeTrue()
        ->and($member->clients()->get()->modelKeys())->toBe([$client->id]);
});

it('binds protected presence subscriptions to numeric IDs without disclosing profile data', function (): void {
    config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret', 'broadcasting.connections.reverb.app_id' => 'test-app']);
    $member = User::factory()->create();
    $member->assignRole('Member');
    $response = $this->actingAs($member)->postJson('/broadcasting/auth', [
        'socket_id' => '1.1', 'channel_name' => 'presence-instance',
    ])->assertOk();
    expect(json_decode($response->json('channel_data'), true, 512, JSON_THROW_ON_ERROR))
        ->toBe(['user_id' => (string) $member->id, 'user_info' => []]);
    expect($response->getContent())->not->toContain($member->name, $member->email);
    $client = Client::factory()->create();
    $this->postJson('/broadcasting/auth', ['socket_id' => '1.1', 'channel_name' => 'presence-client.'.$client->id])->assertOk();
    $this->postJson('/broadcasting/auth', ['socket_id' => '1.1', 'channel_name' => 'private-instance'])->assertForbidden();
    $this->postJson('/broadcasting/auth', ['socket_id' => '1.1', 'channel_name' => 'private-client.'.$client->id])->assertForbidden();
});

it('invalidates the old bearer link on resend', function (): void {
    Notification::fake();
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $this->actingAs($owner)->postJson(route('settings.members.invite'), [
        'email' => 'invitee@example.test', 'role' => 'Admin', 'client_ids' => [],
    ])->assertCreated();
    $invitation = Invitation::query()->sole();
    $previousHash = $invitation->token_hash;
    $this->postJson(route('settings.members.resend', $invitation->id))->assertNoContent();
    expect($invitation->refresh()->token_hash)->not->toBe($previousHash);
    Notification::assertSentOnDemand(TeamInvitation::class, 2);
});

it('rejects expired and revoked invites and prevents Admin from inviting Admin', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05T00:30:00Z'));
    Notification::fake();
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $admin = User::factory()->create();
    $admin->assignRole('Admin');
    $client = Client::factory()->create();
    $this->actingAs($admin)->postJson(route('settings.members.invite'), [
        'email' => 'another@example.test', 'role' => 'Admin', 'client_ids' => [],
    ])->assertUnprocessable();
    $this->actingAs($owner)->postJson(route('settings.members.invite'), [
        'email' => 'another@example.test', 'role' => 'Member', 'client_ids' => [$client->id],
    ])->assertCreated();
    $url = null;
    Notification::assertSentOnDemand(TeamInvitation::class, function (TeamInvitation $notification) use (&$url): bool {
        $url = $notification->actionUrl();

        return true;
    });
    $token = basename(parse_url($url, PHP_URL_PATH));
    $this->post(route('logout'));
    $this->travelTo(CarbonImmutable::parse('2026-10-12T00:30:01Z'));
    $this->get(route('invitations.show', $token))->assertGone();
    $this->actingAs($owner)->deleteJson(route('settings.members.revoke', Invitation::query()->sole()->id))->assertNoContent();
    $this->post(route('logout'));
    $this->get(route('invitations.show', $token))->assertNotFound();
});
