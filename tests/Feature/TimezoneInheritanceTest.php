<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckResult;
use Heptapod\Enums\NotificationMode;
use Heptapod\Models\Client;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\Invitation;
use Heptapod\Models\User;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/NotificationChannels.php';

beforeEach(function (): void {
    $settings = app(InstanceSettings::class);
    $settings->timezone = 'Asia/Tokyo';
    $settings->save();
});

it('stores no zone for a new client, the command-line Owner and No client, so they follow every instance zone change while explicit zones stay', function (): void {
    expect(Artisan::call('heptapod:user:create-owner', ['--email' => 'owner@example.test']))->toBe(0);
    $owner = User::query()->where('email', 'owner@example.test')->sole();
    $traveller = User::factory()->create(['timezone' => 'America/Chicago']);
    $traveller->assignRole('Member');
    $this->actingAs($owner)->postJson('/clients', ['name' => 'Follows'])->assertCreated();
    $this->postJson('/clients', ['name' => 'Fixed', 'timezone' => 'America/New_York'])->assertCreated();
    $follows = Client::query()->where('name', 'Follows')->sole();
    $fixed = Client::query()->where('name', 'Fixed')->sole();
    $internal = Client::query()->where('is_internal', true)->sole();
    expect([$owner->timezone, $follows->timezone, $internal->timezone, $fixed->timezone])->toBe([null, null, null, 'America/New_York']);

    $this->patch('/settings/instance', ['name' => 'Agency', 'timezone' => 'Europe/Berlin'])->assertRedirect();

    foreach ([$follows, $internal, $fixed] as $client) {
        $this->get('/clients/'.$client->id.'/settings')->assertInertia(fn (Assert $page): Assert => $page
            ->where('client.timezone', $client->timezone ?? 'Europe/Berlin')->where('client.timezone_override', $client->timezone));
    }
    $this->get('/settings/profile')->assertInertia(fn (Assert $page): Assert => $page->where('timezone', 'Europe/Berlin')->where('auth.user.timezone', null));
    $this->actingAs($traveller)->get('/settings/profile')->assertInertia(fn (Assert $page): Assert => $page->where('timezone', 'America/Chicago'));
    expect([$owner->refresh()->timezone, $follows->refresh()->timezone, $internal->refresh()->timezone])->toBe([null, null, null]);
});

it('clears a client zone back to the default with null', function (): void {
    $owner = User::factory()->owner()->create();
    $client = Client::factory()->create(['timezone' => 'America/New_York']);

    $this->actingAs($owner)->patchJson('/clients/'.$client->id, ['timezone' => null])->assertRedirect();

    expect($client->refresh()->timezone)->toBeNull();
});

it('stores an invited account\'s browser zone and no zone without a valid one', function (mixed $browserZone, ?string $expected): void {
    $token = str_repeat('a', 64);
    Invitation::factory()->create(['email' => 'new@example.test', 'token_hash' => hash('sha256', $token), 'role' => 'Member']);

    $this->post(route('invitations.accept', $token), ['name' => 'New', 'password' => 'password',
        'password_confirmation' => 'password', ...($browserZone === null ? [] : ['timezone' => $browserZone])])->assertRedirect(route('sites.index'));

    expect(User::query()->where('email', 'new@example.test')->sole()->timezone)->toBe($expected);
})->with([
    'browser zone' => ['Europe/Berlin', 'Europe/Berlin'],
    'missing' => [null, null],
    'unknown zone' => ['Mars/Olympus_Mons', null],
    'not a string' => [['Europe/Berlin'], null],
]);

it('keeps the zone of a re-activated account', function (): void {
    $token = str_repeat('b', 64);
    User::factory()->create(['email' => 'back@example.test', 'timezone' => 'America/Chicago', 'disabled_at' => now()]);
    Invitation::factory()->create(['email' => 'back@example.test', 'token_hash' => hash('sha256', $token), 'role' => 'Member']);

    $this->post(route('invitations.accept', $token), ['name' => 'Back', 'password' => 'password',
        'password_confirmation' => 'password', 'timezone' => 'Europe/Berlin'])->assertRedirect(route('sites.index'));

    expect(User::query()->where('email', 'back@example.test')->sole()->timezone)->toBe('America/Chicago');
});

it('plans a channel digest in the channel\'s own zone, else in its party\'s', function (?string $channelZone, string $due): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-01T23:00:00Z'));
    $instance = app(InstanceSettings::class);
    $instance->timezone = 'Europe/Berlin';
    $instance->save();
    Http::preventStrayRequests();
    $owner = User::factory()->owner()->create();
    $channel = notificationWebhook();
    if ($channelZone !== null) {
        $this->actingAs($owner)->patchJson(route('notification-channels.update', $channel->id), ['timezone' => $channelZone])->assertOk();
    }
    notificationSchedule($channel, ['digest_time' => '08:00']);
    notificationRule($channel, 'check.warning', NotificationMode::Digest);
    $warning = Event::factory()->create(['to_result' => CheckResult::Warning]);
    $warning->check->update(['latest_run_result' => CheckResult::Warning]);

    dispatchNotifications();

    expect(Delivery::query()->sole()->next_attempt_at->toIso8601String())->toBe($due);
})->with([
    'team channel without its own zone: the instance\'s 08:00' => [null, '2026-10-02T06:00:00+00:00'],
    'team channel with its own zone: its 08:00' => ['America/New_York', '2026-10-02T12:00:00+00:00'],
]);
