<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Actions\EnsureAccountChannel;
use Heptapod\Actions\Notifications\SaveNotificationChannel;
use Heptapod\Enums\RecipientKind;
use Heptapod\Models\Client;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Vite;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-28T12:00:00Z'));
    Vite::useHotFile(storage_path('framework/testing.hot'));
    config(['broadcasting.default' => 'null']);
});

it('adds a team channel, sets a category to the digest from its sheet and pauses it', function (): void {
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $this->actingAs($owner);

    $page = visit('/settings/notifications/team')->resize(1440, 1000)->assertSee('No channels yet')
        ->click('Add channel')->click('Webhook')->fill('Name', 'Status page')
        ->fill('url', 'https://status.example.test/hooks/heptapod')->fill('secret', 'browser-signing-secret-0123456789')
        ->click('[data-sheet-form] button[type="submit"]')->assertSee('Channel added.')
        // A new channel opens its sheet so its categories can be set at once.
        ->assertSee('What it gets');
    $channel = NotificationDestination::query()->where('recipient_kind', RecipientKind::Team)->sole();
    $page->click('[data-category="warning"] [role="radio"][value="digest"]')
        ->assertSee('warnings in the 08:00 digest')->click('[data-sheet-form] button[type="submit"]')->assertSee('Channel saved.')
        ->assertSee('Custom')->assertNoJavaScriptErrors();
    expect(DB::table('notification_preferences')->where('destination_id', $channel->id)->where('category', 'warning')->value('mode'))->toBe('digest');

    $page->click('Status page')->click('Pause')->assertSee('Paused: nothing is sent until you resume it.')->assertNoJavaScriptErrors();
    expect($channel->refresh()->enabled)->toBeFalse();
});

it('shows a Member only their own channels with the account email that cannot be removed', function (): void {
    $member = User::factory()->create(['locale' => 'de']);
    $member->assignRole('Member');
    app(EnsureAccountChannel::class)($member);
    app(SaveNotificationChannel::class)->add(RecipientKind::Team, null, null, ['channel' => 'mail', 'label' => 'Team inbox', 'locale' => 'en',
        'config' => ['email' => 'team@example.test']]);
    $this->actingAs($member);

    visit('/settings/notifications')->resize(390, 844)->assertSee('Meine Benachrichtigungen')->assertSee('Konto-E-Mail')
        ->assertDontSee('Team inbox')->click($member->name)->assertSee('Was er erhält')
        ->assertPresent('[data-category="outage"]')->assertMissing('[data-category="system"]')->assertDontSee('Kanal entfernen')
        ->assertNoJavaScriptErrors();
    visit('/settings/notifications/team')->assertSee('403');
});

it('tells the people managing a client that nothing goes to it automatically', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');
    $client = Client::factory()->create(['name' => 'Studio Nord']);
    app(SaveNotificationChannel::class)->add(RecipientKind::Client, null, $client, ['channel' => 'mail', 'label' => 'Purchasing',
        'config' => ['email' => 'purchasing@studio.test']]);
    $this->actingAs($admin);

    visit('/clients/'.$client->id.'/notifications')->assertSee('Nothing goes to this client automatically')
        ->assertSee('Gets only what you send by hand.')->click('Purchasing')->assertSee('What the client gets automatically')
        ->assertPresent('[data-category="outage"]')->assertMissing('[data-category="system"]')->assertNoJavaScriptErrors();
});
