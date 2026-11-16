<?php

declare(strict_types=1);

use Heptapod\Models\User;
use Heptapod\Settings\InstanceSettings;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    config(['heptapod.health.browser_urls' => [], 'broadcasting.default' => 'log']);
});

it('renders the owner instance page with the existing safe configuration projection', function (): void {
    config(['mail.mailers.smtp.host' => 'smtp.example.test', 'mail.mailers.smtp.username' => 'private-user',
        'mail.mailers.smtp.password' => 'private-password', 'instance.heartbeat_url' => 'https://example.test/private-heartbeat']);
    $owner = User::factory()->create();
    $owner->assignRole('Owner');

    $response = $this->actingAs($owner)->get('/settings/instance')->assertInertia(fn (Assert $page): Assert => $page
        ->component('settings/instance')->where('auth.canManageInstance', true)
        ->where('instancePage.name', app(InstanceSettings::class)->name)
        ->where('instancePage.smtp.host', 'smtp.example.test')->where('instancePage.smtp.username_configured', true)
        ->where('instancePage.smtp.password_configured', true)->where('instancePage.heartbeat_configured', true)
        ->where('instancePage.health_url', url('/up'))->where('instancePage.defaults.editable', false)
        ->where('instancePage.defaults.source', 'check definitions')->where('instancePage.retention_editable', false)
        ->where('instancePage.retention.keep_all_backups_for_days', 7));
    expect($response->getContent())->not->toContain('private-user', 'private-password', 'private-heartbeat');
});

it('keeps instance page and navigation owner-only', function (string $role): void {
    $user = User::factory()->create();
    $user->assignRole($role);
    $original = app(InstanceSettings::class)->name;

    $this->actingAs($user)->get('/settings/instance')->assertForbidden();
    $this->patch('/settings/instance', ['name' => 'Forbidden'])->assertForbidden();
    $this->get('/settings/profile')->assertInertia(fn (Assert $page): Assert => $page->where('auth.canManageInstance', false));
    expect(app(InstanceSettings::class)->name)->toBe($original);
})->with(['Admin', 'Member']);

it('redirects unauthenticated instance page and name writes to login', function (): void {
    $this->get('/settings/instance')->assertRedirect('/login');
    $this->patch('/settings/instance', ['name' => 'Forbidden'])->assertRedirect('/login');
});

it('saves only the instance name and redirects the browser form to its page', function (): void {
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $timezone = app(InstanceSettings::class)->timezone;

    $this->actingAs($owner)->patch('/settings/instance', ['name' => '  Agency Monitor  ', 'timezone' => 'Pacific/Honolulu',
        'heartbeat_url' => 'http://127.0.0.1/private', 'smtp' => ['host' => 'changed']])->assertRedirect('/settings/instance');
    expect(app(InstanceSettings::class)->name)->toBe('Agency Monitor');
    expect(app(InstanceSettings::class)->timezone)->toBe($timezone);
    expect(config('mail.mailers.smtp.host'))->not->toBe('changed');
});

it('rejects invalid instance names without changing persisted identity', function (mixed $name): void {
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $original = app(InstanceSettings::class)->name;

    $this->actingAs($owner)->from('/settings/instance')->patch('/settings/instance', ['name' => $name])
        ->assertRedirect('/settings/instance')->assertSessionHasErrors('name');
    expect(app(InstanceSettings::class)->name)->toBe($original);
})->with(['empty' => '', 'whitespace' => '   ', 'too long' => str_repeat('a', 121), 'not a string' => [['name']]]);
