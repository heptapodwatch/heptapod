<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Contracts\LiveConnectionTerminator;
use Heptapod\Enums\ClientScope;
use Heptapod\Models\Client;
use Heptapod\Models\Invitation;
use Heptapod\Models\User;
use Heptapod\Testing\FakeLiveConnectionTerminator;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Vite;

it('edits real member access, keeps invalid drafts, deactivates and invites the same identity back', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-26T12:00:00Z'));
    Vite::useHotFile(storage_path('framework/testing.hot'));
    config(['broadcasting.default' => 'null']);
    expect(config('mail.default'))->toBe('array')->and(config('queue.default'))->toBe('sync');
    expect(Mail::mailer()->getSymfonyTransport())->toBeInstanceOf(ArrayTransport::class);
    $terminator = new FakeLiveConnectionTerminator;
    app()->instance(LiveConnectionTerminator::class, $terminator);
    $owner = User::factory()->create(['name' => 'Fixture Owner', 'locale' => 'en']);
    $owner->assignRole('Owner');
    $one = Client::factory()->create(['name' => 'Nordlicht']);
    $two = Client::factory()->create(['name' => 'Hafenkino', 'archived_at' => now()]);
    $member = User::factory()->create(['name' => 'Rafael Kühn', 'email' => 'rafael@example.test', 'client_scope' => ClientScope::Restricted]);
    $member->assignRole('Member');
    $member->clients()->attach([$one->id, $two->id]);
    $this->actingAs($owner);
    $page = visit('/settings/profile', ['viewport' => ['width' => 1440, 'height' => 900]])
        ->click('nav[aria-label="Team"] a[href="/settings/members"]')->assertPathIs('/settings/members')->assertSee('Rafael Kühn');
    $page->script('localStorage.setItem("appearance", "dark"); document.documentElement.classList.add("dark")');
    $page->click('[data-test="edit-member-'.$member->id.'"]')->assertSee('Save access')
        ->uncheck('#access-client-'.$two->id)->assertSee('Access will be removed on save: Hafenkino')
        ->screenshot(filename: 'members-fixture-desktop-edit')->click('Cancel');
    expect($member->clients()->count())->toBe(2);
    $mobile = visit('/settings/members', ['viewport' => ['width' => 390, 'height' => 844]])->assertSee('Rafael Kühn');
    $mobile->script('localStorage.setItem("appearance", "dark"); document.documentElement.classList.add("dark")');
    $mobile->click('[data-test="edit-member-'.$member->id.'"]')->uncheck('#access-client-'.$two->id)
        ->assertSee('Access will be removed on save: Hafenkino')->screenshot(filename: 'members-fixture-mobile-edit')->click('Cancel')->assertNoJavaScriptErrors();
    $page->click('[data-test="edit-member-'.$member->id.'"]')->uncheck('#access-client-'.$one->id)->uncheck('#access-client-'.$two->id)
        ->click('Save access')->assertSee('The client selection field is required.')
        ->assertSee('Save access')->check('#access-client-'.$one->id)->click('Save access')->assertSee('Access saved.');
    expect($member->clients()->get()->modelKeys())->toBe([$one->id]);
    expect($terminator->terminated)->toBe([$member->id]);
    $page->click('[data-test="edit-member-'.$member->id.'"]')->click('Remove from team')
        ->assertSee('History is retained.')->click('[data-test="confirm-remove-member"]')->assertSee('Member deactivated.');
    expect($member->refresh()->disabled_at)->not->toBeNull();
    $page->assertSee('Disabled')->screenshot(filename: 'members-fixture-desktop-disabled')->click('[data-test="reinvite-member-'.$member->id.'"]')
        ->assertValue('#invite-email', $member->email)->check('#invite-client-'.$one->id)->click('Send invite')
        ->assertSee('Invitation sent.')->assertSee('Awaiting acceptance');
    expect(User::query()->where('email', $member->email)->count())->toBe(1)
        ->and($member->refresh()->disabled_at)->not->toBeNull();
    $invitation = Invitation::query()->where('email', $member->email)->sole();
    expect($invitation->client_ids)->toBe([$one->id]);
    $message = Mail::mailer()->getSymfonyTransport()->messages()->last();
    preg_match('~/invitations/([a-f0-9]{64})~', $message->getOriginalMessage()->getHtmlBody(), $match);
    expect($match)->toHaveCount(2);
    $this->post(route('logout'));
    $this->post(route('invitations.accept', $match[1]), ['name' => 'Rafael Kühn', 'password' => 'password', 'password_confirmation' => 'password'])
        ->assertRedirect(route('sites.index'));
    expect(User::query()->where('email', $member->email)->count())->toBe(1)
        ->and($member->refresh()->disabled_at)->toBeNull()->and($member->clients()->get()->modelKeys())->toBe([$one->id]);
    $this->actingAs($owner);
    $page->click('Refresh')->assertDontSee('Disabled')
        ->assertEnabled('[data-test="edit-member-'.$member->id.'"]')
        ->assertMissing('[data-test="reinvite-member-'.$member->id.'"]')->assertSee('Nordlicht')->assertDontSee('Invitation sent.')
        ->screenshot(filename: 'members-fixture-desktop-reactivated')->assertNoJavaScriptErrors();
});

it('shows expired invitations and preserves a rejected invite draft on mobile in German', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-06T07:00:00Z'));
    Vite::useHotFile(storage_path('framework/testing.hot'));
    config(['broadcasting.default' => 'null']);
    expect(config('mail.default'))->toBe('array')->and(config('queue.default'))->toBe('sync');
    expect(Mail::mailer()->getSymfonyTransport())->toBeInstanceOf(ArrayTransport::class);
    $owner = User::factory()->create(['name' => 'Fixture Owner', 'locale' => 'de', 'timezone' => 'Europe/Berlin']);
    $owner->assignRole('Owner');
    $client = Client::factory()->create(['name' => 'Studio Mai']);
    $expired = Invitation::factory()->create(['email' => 'expired@example.test', 'role' => 'Member', 'inviter_id' => $owner->id, 'client_scope' => 'restricted', 'client_ids' => [$client->id], 'expires_at' => now()->subMinute()]);
    Invitation::factory()->create(['email' => 'future@example.test', 'role' => 'Member', 'inviter_id' => $owner->id, 'client_scope' => 'restricted', 'client_ids' => [$client->id], 'expires_at' => now()->addHours(3)]);
    $this->actingAs($owner);
    $page = visit('/settings/members', ['viewport' => ['width' => 390, 'height' => 844]])->assertSee('Abgelaufen')
        ->assertSee('Abgelaufen am Dienstag, 6. Oktober 2026 um 08:59:00')
        ->assertSee('Gültig bis Dienstag, 6. Oktober 2026 um 12:00:00')
        ->screenshot(filename: 'members-fixture-mobile-de-expired');
    $page->click('Einladen')->fill('#invite-email', $owner->email)->check('#invite-client-'.$client->id)
        ->click('Einladung senden')->assertSee('E-Mail ist bereits vergeben.')->assertValue('#invite-email', $owner->email)
        ->screenshot(filename: 'members-fixture-mobile-de-error')->click('Abbrechen');
    $oldHash = $expired->token_hash;
    $page->click('button[aria-label$="expired@example.test"]')->click('[data-test="resend-invitation-'.$expired->id.'"]')->assertSee('Einladung erneut gesendet.')->assertDontSee('Abgelaufen');
    expect($expired->refresh()->token_hash)->not->toBe($oldHash);
    $page->click('button[aria-label$="expired@example.test"]')->click('[data-test="revoke-invitation-'.$expired->id.'"]')->assertSee('Einladung widerrufen.')->assertDontSee('expired@example.test');
    expect(Invitation::query()->find($expired->id))->toBeNull();
    $page->assertNoJavaScriptErrors();
});

it('requires explicit consent before converting legacy all-client Member access', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    config(['broadcasting.default' => 'null']);
    $terminator = new FakeLiveConnectionTerminator;
    app()->instance(LiveConnectionTerminator::class, $terminator);
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $client = Client::factory()->create(['name' => 'Legacy assignment']);
    $member = User::factory()->create(['client_scope' => ClientScope::All]);
    $member->assignRole('Member');
    $member->clients()->attach($client);
    $this->actingAs($owner);
    $page = visit('/settings/members');
    $page->click('[data-test="edit-member-'.$member->id.'"]')->assertSee('On save, access will be limited to the selected clients.')
        ->assertDisabled('button[type="submit"]')->click('Cancel');
    expect($member->refresh()->client_scope)->toBe(ClientScope::All);
    $page->click('[data-test="edit-member-'.$member->id.'"]')->check('Replace legacy all-client access with the selected clients on save.')
        ->click('Save access')->assertSee('Access saved.');
    expect($member->refresh()->client_scope)->toBe(ClientScope::Restricted)->and($terminator->terminated)->toBe([$member->id]);
    $page->assertNoJavaScriptErrors();
});

it('keeps a stale access draft and shows failure when the server no longer permits the edit', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    config(['broadcasting.default' => 'null']);
    $terminator = new FakeLiveConnectionTerminator;
    app()->instance(LiveConnectionTerminator::class, $terminator);
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $admin = User::factory()->create();
    $admin->assignRole('Admin');
    $one = Client::factory()->create(['name' => 'First client']);
    $two = Client::factory()->create(['name' => 'Second client']);
    $member = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $member->assignRole('Member');
    $member->clients()->attach([$one->id, $two->id]);
    $this->actingAs($admin);
    $page = visit('/settings/members');
    $page->click('[data-test="edit-member-'.$member->id.'"]')->uncheck('#access-client-'.$two->id);
    $this->actingAs($owner)->patchJson(route('settings.members.update', $member->id), ['role' => 'Admin', 'client_ids' => []])->assertNoContent();
    $this->actingAs($admin);
    $page->click('Save access')->assertSee('Change failed. Check your connection and permissions, then try again.')
        ->assertDontSee('Access saved.')->assertNotChecked('#access-client-'.$two->id)->assertEnabled('button[type="submit"]')
        ->screenshot(filename: 'members-fixture-stale-access-error')->click('Cancel')->click('Refresh')
        ->assertMissing('[data-test="edit-member-'.$member->id.'"]');
    expect($member->refresh()->hasRole('Admin'))->toBeTrue()->and($terminator->terminated)->toBe([$member->id]);
    $page->assertNoJavaScriptErrors();
});
