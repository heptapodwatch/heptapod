<?php

declare(strict_types=1);

use Heptapod\Models\User;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Support\Facades\Http;

it('keeps mail and heartbeat secrets out of instance props and restricts edits to Owner', function (): void {
    config(['heptapod.health.browser_urls' => [], 'broadcasting.default' => 'log',
        'mail.mailers.smtp.host' => 'smtp.example.test',
        'mail.mailers.smtp.username' => 'secret-user', 'mail.mailers.smtp.password' => 'secret-password',
        'instance.heartbeat_url' => 'https://example.test/secret-heartbeat']);
    $member = User::factory()->create();
    $member->assignRole('Member');
    $admin = User::factory()->create();
    $admin->assignRole('Admin');
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $this->getJson(route('settings.instance.data'))->assertUnauthorized();
    $this->actingAs($member)->getJson(route('settings.instance.data'))->assertForbidden();
    $this->actingAs($admin)->patchJson(route('settings.instance.update'), ['name' => 'No'])->assertForbidden();
    $this->actingAs($owner)->patchJson(route('settings.instance.update'), ['name' => 'Agency Monitor'])
        ->assertOk()->assertJsonPath('name', 'Agency Monitor');
    $response = $this->getJson(route('settings.instance.data'))->assertOk()
        ->assertJsonPath('name', 'Agency Monitor')->assertJsonPath('smtp.password_configured', true)
        ->assertJsonPath('heartbeat_configured', true);
    expect($response->getContent())->toContain('Agency Monitor')
        ->not->toContain('secret-user')->not->toContain('secret-password')->not->toContain('secret-heartbeat');
    expect(app(InstanceSettings::class)->name)->toBe('Agency Monitor');
});

it('shows real system health to operators without sending private probe details to the browser', function (): void {
    config(['heptapod.health.browser_urls' => ['lighthouse' => 'http://service.example.test/health'],
        'broadcasting.default' => 'log']);
    Http::preventStrayRequests();
    Http::fake(['http://service.example.test/health' => Http::response('private-body-secret', 503)]);
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $member = User::factory()->create();
    $member->assignRole('Member');
    $this->actingAs($member)->getJson(route('settings.system.data'))->assertForbidden();
    $response = $this->actingAs($owner)->getJson(route('settings.system.data'))->assertOk()
        ->assertJsonPath('health.browserServices.lighthouse.status', 'down');
    expect($response->getContent())->not->toContain('private-body-secret')->not->toContain('service.example.test');
});
