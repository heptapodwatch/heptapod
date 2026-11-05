<?php

declare(strict_types=1);

use Heptapod\Enums\ClientScope;
use Heptapod\Models\Client;
use Heptapod\Models\Invitation;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

it('serves the Members page only to active operators and advertises its navigation accordingly', function (string $role, bool $allowed): void {
    $user = User::factory()->create();
    $user->assignRole($role);
    $this->actingAs($user)->get('/settings/profile')->assertInertia(fn (Assert $page): Assert => $page->where('auth.canManageMembers', $allowed));
    $response = $this->get('/settings/members');
    if ($allowed) {
        $response->assertOk()->assertInertia(fn (Assert $page): Assert => $page->component('settings/members')->has('roster.members', 1));
    } else {
        $response->assertForbidden();
    }
})->with([['Owner', true], ['Admin', true], ['Member', false]]);

it('returns truthful safe scope and lifecycle metadata in the page and existing JSON roster', function (): void {
    $owner = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $owner->assignRole('Owner');
    $archived = Client::factory()->create(['name' => 'Archived client', 'archived_at' => now()]);
    $legacy = User::factory()->create(['client_scope' => ClientScope::All]);
    $legacy->assignRole('Member');
    $legacy->clients()->attach($archived);
    $empty = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $empty->assignRole('Member');
    $disabled = User::factory()->create();
    $disabled->assignRole('Admin');
    $disabled->forceFill(['disabled_at' => now()])->save();
    $pending = Invitation::factory()->create(['email' => 'pending@example.test', 'role' => 'Member', 'inviter_id' => $owner->id, 'client_ids' => [$archived->id], 'client_scope' => 'restricted', 'expires_at' => now()->addDays(7)]);
    $expired = Invitation::factory()->create(['email' => 'expired@example.test', 'role' => 'Admin', 'inviter_id' => $owner->id, 'expires_at' => now()->subMinute()]);
    $this->actingAs($owner);
    $data = $this->getJson(route('settings.members.data'))->assertOk()->json();
    $members = collect($data['members'])->keyBy('id');
    expect($members[$owner->id]['is_last_owner'])->toBeTrue()->and($members[$owner->id]['can_deactivate'])->toBeFalse()
        ->and($members[$owner->id]['allowed_roles'])->toBe(['Owner'])
        ->and($members[$owner->id]['client_scope'])->toBe('restricted')->and($members[$owner->id]['effective_client_scope'])->toBe('all')
        ->and($members[$legacy->id]['client_scope'])->toBe('all')->and($members[$legacy->id]['client_ids'])->toBe([$archived->id])
        ->and($members[$empty->id]['client_scope'])->toBe('restricted')->and($members[$empty->id]['client_ids'])->toBe([])
        ->and($members[$empty->id]['last_active_at'])->toBeNull()
        ->and($members[$disabled->id]['disabled'])->toBeTrue()->and($members[$disabled->id]['can_edit'])->toBeFalse()
        ->and($members[$disabled->id]['can_reinvite'])->toBeTrue();
    $invites = collect($data['invitations'])->keyBy('id');
    expect($invites[$pending->id]['expired'])->toBeFalse()->and($invites[$expired->id]['expired'])->toBeTrue()
        ->and($invites[$pending->id]['inviter'])->toBe($owner->name)
        ->and($data['clients'][0]['archived'])->toBeTrue()->and($data['invite_roles'])->toBe(['Admin', 'Member']);
    $encoded = json_encode($data, JSON_THROW_ON_ERROR);
    expect($encoded)->not->toContain('token_hash', $pending->token_hash, 'password', 'two_factor', 'remember_token');
    $this->get('/settings/members')->assertInertia(fn (Assert $page): Assert => $page->where('roster', $data));
});

it('rejects unauthenticated and disabled access to the Members page', function (): void {
    $this->get('/settings/members')->assertRedirect(route('login'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $owner->forceFill(['disabled_at' => now()])->save();
    $this->actingAs($owner)->get('/settings/members')->assertUnauthorized();
});

it('uses existing role authority for per-row capabilities without inviter or client N plus one reads', function (): void {
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $admin = User::factory()->create();
    $admin->assignRole('Admin');
    $client = Client::factory()->create();
    $member = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $member->assignRole('Member');
    $member->clients()->attach($client);
    Invitation::factory()->create(['role' => 'Admin', 'inviter_id' => $owner->id]);
    $this->actingAs($admin)->getJson(route('settings.members.data'))->assertOk();
    DB::enableQueryLog();
    $data = $this->getJson(route('settings.members.data'))->assertOk()->json();
    $small = count(DB::getQueryLog());
    DB::disableQueryLog();
    $rows = collect($data['members'])->keyBy('id');
    expect($data['invite_roles'])->toBe(['Member'])->and($rows[$owner->id]['can_edit'])->toBeFalse()
        ->and($rows[$admin->id]['can_edit'])->toBeFalse()->and($rows[$member->id]['can_edit'])->toBeTrue()
        ->and($rows[$member->id]['allowed_roles'])->toBe(['Member'])->and($data['invitations'][0]['can_manage'])->toBeFalse();
    Invitation::factory()->count(20)->create(['role' => 'Member', 'inviter_id' => $owner->id]);
    User::factory()->count(20)->create()->each(function (User $user) use ($client): void {
        $user->assignRole('Member');
        $user->clients()->attach($client);
    });
    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->getJson(route('settings.members.data'))->assertOk()->assertJsonCount(23, 'members')->assertJsonCount(21, 'invitations');
    expect(count(DB::getQueryLog()))->toBe($small);
    DB::disableQueryLog();
});

it('localizes Members validation labels while retaining error keys and access', function (string $locale, string $requiredClients, string $duplicateEmail): void {
    $owner = User::factory()->create(['locale' => $locale]);
    $owner->assignRole('Owner');
    $client = Client::factory()->create();
    $member = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $member->assignRole('Member');
    $member->clients()->attach($client);
    $this->actingAs($owner);

    $this->postJson(route('settings.members.invite'), ['email' => 'validation@example.test', 'role' => 'Member', 'client_ids' => []])
        ->assertUnprocessable()->assertJsonValidationErrors('client_ids')->assertJsonPath('errors.client_ids.0', $requiredClients);
    $this->patchJson(route('settings.members.update', $member->id), ['role' => 'Member', 'client_ids' => []])
        ->assertUnprocessable()->assertJsonValidationErrors('client_ids')->assertJsonPath('errors.client_ids.0', $requiredClients);
    $this->postJson(route('settings.members.invite'), ['email' => $owner->email, 'role' => 'Admin', 'client_ids' => []])
        ->assertUnprocessable()->assertJsonValidationErrors('email')->assertJsonPath('errors.email.0', $duplicateEmail);

    expect($member->refresh()->client_scope)->toBe(ClientScope::Restricted)
        ->and($member->clients()->get()->modelKeys())->toBe([$client->id])
        ->and(Invitation::query()->count())->toBe(0);
})->with([
    ['en', 'The client selection field is required.', 'The Email has already been taken.'],
    ['de', 'Kundenauswahl muss ausgefüllt werden.', 'E-Mail ist bereits vergeben.'],
]);
