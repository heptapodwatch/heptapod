<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckKey;
use Heptapod\Enums\AlertReminders;
use Heptapod\Models\Check;
use Heptapod\Models\User;
use Heptapod\Settings\InstanceSettings;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-26T10:00:00Z'));
});

/** @return array{alert_after_minutes: int, alert_recovery_checks: int, alert_confirms_other_checks: bool, alert_reminders: string} */
function customSensitivity(): array
{
    return ['alert_after_minutes' => 3, 'alert_recovery_checks' => 2, 'alert_confirms_other_checks' => true, 'alert_reminders' => 'standard'];
}

it('shows the Relaxed team default and saves a custom combination and a preset on its own settings page', function (string $role): void {
    $user = User::factory()->create()->assignRole($role);
    $version = $this->actingAs($user)->get('/settings/profile')->assertOk()->inertiaPage()['version'];
    $page = fn () => $this->get('/settings/alerts', ['X-Inertia' => 'true', 'X-Inertia-Version' => $version])->assertOk()
        ->assertJsonPath('component', 'settings/alerts');

    $page()->assertJsonPath('props.saved', ['alert_after_minutes' => 30, 'alert_recovery_checks' => 3,
        'alert_confirms_other_checks' => true, 'alert_reminders' => 'daily', 'inherited' => false, 'preset' => 'relaxed']);
    $this->from('/settings/alerts')->put('/settings/alerts', customSensitivity())->assertRedirect('/settings/alerts')->assertSessionHasNoErrors();
    $page()->assertJsonPath('props.saved', [...customSensitivity(), 'inherited' => false, 'preset' => null]);
    // Values equal to a preset show that preset: the preset is derived, not stored.
    $this->put('/settings/alerts', ['alert_after_minutes' => 60, 'alert_recovery_checks' => 3, 'alert_confirms_other_checks' => true, 'alert_reminders' => 'daily'])
        ->assertSessionHasNoErrors();
    $page()->assertJsonPath('props.saved.preset', 'patient');
})->with(['Owner', 'Admin']);

it('rejects invalid alert sensitivity without changing the saved settings', function (): void {
    $user = User::factory()->owner()->create();
    $this->actingAs($user)->putJson('/settings/alerts', [
        'alert_after_minutes' => 1, 'alert_recovery_checks' => 0, 'alert_reminders' => 'hourly',
    ])->assertUnprocessable()->assertJsonValidationErrors(['alert_after_minutes', 'alert_recovery_checks', 'alert_confirms_other_checks', 'alert_reminders']);
    $settings = app(InstanceSettings::class);
    expect([$settings->alert_after_minutes, $settings->alert_recovery_checks, $settings->alert_confirms_other_checks, $settings->alert_reminders])
        ->toBe([30, 3, true, AlertReminders::Daily]);
});

it('keeps team alert settings unavailable to a Member', function (): void {
    $user = User::factory()->member()->create();
    $this->actingAs($user)->get('/settings/alerts')->assertForbidden();
    $this->put('/settings/alerts', customSensitivity())->assertForbidden();
});

it('saves a custom site override, reads it on the site page and the API, and returns to the team default', function (): void {
    $owner = User::factory()->owner()->create();
    $site = Check::factory()->create(['type' => CheckKey::Uptime])->site;
    $version = $this->actingAs($owner)->get('/settings/profile')->assertOk()->inertiaPage()['version'];
    $settingsPage = fn () => $this->get('/sites/'.$site->id.'/settings', ['X-Inertia' => 'true', 'X-Inertia-Version' => $version])->assertOk();
    $token = $owner->createToken('read', ['*'])->plainTextToken;
    // An API request forgets the web guard; the page requests act as the Owner again.
    $api = function () use ($token, $site, $owner) {
        $response = $this->withToken($token)->getJson('/api/monitors/'.$site->id)->assertOk();
        $this->withoutToken()->actingAs($owner, 'web');

        return $response;
    };

    $this->from('/sites/'.$site->id.'/settings')->patch('/sites/'.$site->id, customSensitivity())->assertSessionHasNoErrors();
    $settingsPage()->assertJsonPath('props.alert_sensitivity', [...customSensitivity(), 'inherited' => false, 'preset' => null])
        ->assertJsonPath('props.team_alert_sensitivity.preset', 'relaxed');
    $api()->assertJsonPath('alert_sensitivity', ['preset' => null, 'alert_after_minutes' => 3, 'recovery_checks' => 2,
        'confirms_other_checks' => true, 'reminders' => 'standard', 'inherited' => false]);

    // An override sends all four settings or none.
    $this->patch('/sites/'.$site->id, ['alert_after_minutes' => 5, 'alert_recovery_checks' => 3])
        ->assertSessionHasErrors(['alert_confirms_other_checks', 'alert_reminders']);
    $this->patch('/sites/'.$site->id, ['alert_after_minutes' => null, 'alert_recovery_checks' => null, 'alert_confirms_other_checks' => null, 'alert_reminders' => null])
        ->assertSessionHasNoErrors();
    $api()->assertJsonPath('alert_sensitivity', ['preset' => 'relaxed', 'alert_after_minutes' => 30, 'recovery_checks' => 3,
        'confirms_other_checks' => true, 'reminders' => 'daily', 'inherited' => true]);
});
