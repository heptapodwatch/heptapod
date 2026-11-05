<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Models\User;
use Heptapod\Settings\InstanceSettings;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-26T10:00:00Z'));
});

it('shows and saves team alert sensitivity on its own settings page', function (string $role): void {
    $user = User::factory()->create()->assignRole($role);
    $settings = app(InstanceSettings::class);
    $settings->alert_after_minutes = 10;
    $settings->alert_recovery_checks = 3;
    $settings->save();

    $version = $this->actingAs($user)->get('/settings/profile')->assertOk()->inertiaPage()['version'];
    $this->get('/settings/alerts', ['X-Inertia' => 'true', 'X-Inertia-Version' => $version])->assertOk()
        ->assertJsonPath('component', 'settings/alerts')
        ->assertJsonPath('props.saved.alert_after_minutes', 10)
        ->assertJsonPath('props.saved.alert_recovery_checks', 3);
    $this->from('/settings/alerts')->put('/settings/alerts', [
        'alert_after_minutes' => 3, 'alert_recovery_checks' => 2,
    ])->assertRedirect('/settings/alerts')->assertSessionHasNoErrors();
    expect(app(InstanceSettings::class)->alert_after_minutes)->toBe(3)
        ->and(app(InstanceSettings::class)->alert_recovery_checks)->toBe(2);
})->with(['Owner', 'Admin']);

it('rejects invalid alert sensitivity without changing the saved timing', function (): void {
    $user = User::factory()->owner()->create();
    $settings = app(InstanceSettings::class);
    $settings->alert_after_minutes = 10;
    $settings->alert_recovery_checks = 3;
    $settings->save();
    $this->actingAs($user)->putJson('/settings/alerts', [
        'alert_after_minutes' => 1, 'alert_recovery_checks' => 0,
    ])->assertUnprocessable()->assertJsonValidationErrors(['alert_after_minutes', 'alert_recovery_checks']);
    expect(app(InstanceSettings::class)->alert_after_minutes)->toBe(10)
        ->and(app(InstanceSettings::class)->alert_recovery_checks)->toBe(3);
});

it('keeps team alert settings unavailable to a Member', function (): void {
    $user = User::factory()->member()->create();
    $this->actingAs($user)->get('/settings/alerts')->assertForbidden();
    $this->put('/settings/alerts', ['alert_after_minutes' => 3, 'alert_recovery_checks' => 2])->assertForbidden();
});
