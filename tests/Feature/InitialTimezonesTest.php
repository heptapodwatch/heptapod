<?php

declare(strict_types=1);

use Heptapod\Models\Client;
use Heptapod\Models\Invitation;
use Heptapod\Models\User;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $settings = app(InstanceSettings::class);
    $settings->timezone = 'Asia/Tokyo';
    $settings->save();
});

it('starts an invited account in the browser zone and falls back to the instance zone', function (mixed $browserZone, string $expected): void {
    $token = str_repeat('a', 64);
    Invitation::factory()->create(['email' => 'new@example.test', 'token_hash' => hash('sha256', $token), 'role' => 'Member']);

    $this->post(route('invitations.accept', $token), ['name' => 'New', 'password' => 'password',
        'password_confirmation' => 'password', ...($browserZone === null ? [] : ['timezone' => $browserZone])])->assertRedirect(route('sites.index'));

    expect(User::query()->where('email', 'new@example.test')->sole()->timezone)->toBe($expected);
})->with([
    'browser zone' => ['Europe/Berlin', 'Europe/Berlin'],
    'missing' => [null, 'Asia/Tokyo'],
    'unknown zone' => ['Mars/Olympus_Mons', 'Asia/Tokyo'],
    'not a string' => [['Europe/Berlin'], 'Asia/Tokyo'],
]);

it('keeps the zone of a re-activated account', function (): void {
    $token = str_repeat('b', 64);
    User::factory()->create(['email' => 'back@example.test', 'timezone' => 'America/Chicago', 'disabled_at' => now()]);
    Invitation::factory()->create(['email' => 'back@example.test', 'token_hash' => hash('sha256', $token), 'role' => 'Member']);

    $this->post(route('invitations.accept', $token), ['name' => 'Back', 'password' => 'password',
        'password_confirmation' => 'password', 'timezone' => 'Europe/Berlin'])->assertRedirect(route('sites.index'));

    expect(User::query()->where('email', 'back@example.test')->sole()->timezone)->toBe('America/Chicago');
});

it('starts the command-line Owner in the instance zone', function (): void {
    expect(Artisan::call('heptapod:user:create-owner', ['--email' => 'owner@example.test']))->toBe(0);

    expect(User::query()->where('email', 'owner@example.test')->sole()->timezone)->toBe('Asia/Tokyo');
});

it('starts a new client in its creator zone unless the form names one', function (): void {
    $owner = User::factory()->create(['timezone' => 'Europe/Berlin']);
    $owner->assignRole('Owner');

    $this->actingAs($owner)->postJson('/clients', ['name' => 'Implicit'])->assertCreated()->assertJsonPath('timezone', 'Europe/Berlin');
    $this->postJson('/clients', ['name' => 'Explicit', 'timezone' => 'America/New_York'])->assertCreated();

    expect(Client::query()->where('name', 'Explicit')->sole()->timezone)->toBe('America/New_York');
});
