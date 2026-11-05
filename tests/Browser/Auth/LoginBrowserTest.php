<?php

declare(strict_types=1);

use Heptapod\Models\User;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Support\Facades\Vite;
use PragmaRX\Google2FA\Google2FA;

test('the login form shows validation and signs in to the intended page with remember me', function (string $locale, int $width): void {
    // The in-process test server does not proxy Compose's /vite development path.
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $instance = app(InstanceSettings::class);
    $instance->name = 'Agency & Partners Monitoring';
    $instance->save();
    $user = User::factory()->create();
    $user->assignRole('Owner');

    $page = visit('/login', ['viewport' => ['width' => $width, 'height' => 900]]);
    $page->script('document.cookie = "locale='.$locale.'; path=/"');
    $page->navigate('/sites')->assertPathIs('/login')->assertSee('Agency & Partners Monitoring')
        ->assertSee($locale === 'de' ? 'Mit Passkey anmelden' : 'Sign in with a passkey')
        ->assertMissing('a[href$="/register"]')
        ->fill('#email', $user->email)->fill('#password', 'wrong-password')
        ->click('[data-test="login-button"]')
        ->assertSee($locale === 'de' ? 'Diese Kombination aus Zugangsdaten wurde nicht in unserer Datenbank gefunden.' : 'These credentials do not match our records.')
        ->assertAttribute('#email', 'aria-invalid', 'true')
        ->assertAttribute('#email', 'aria-describedby', 'email-error')
        ->assertValue('#email', $user->email)->assertEnabled('[data-test="login-button"]')
        ->screenshot(filename: 'login-'.$locale.'-validation');
    $this->assertGuest();

    $page->fill('#password', 'password')->click($locale === 'de' ? '[aria-label="Passwort anzeigen"]' : '[aria-label="Show password"]')
        ->assertAttribute('#password', 'type', 'text')
        ->click($locale === 'de' ? '[aria-label="Passwort verbergen"]' : '[aria-label="Hide password"]')
        ->assertAttribute('#password', 'type', 'password')
        ->check('#remember')
        ->keys('#password', 'Enter')
        ->assertPathIs('/sites')->assertSee($locale === 'de' ? 'Websites' : 'Sites')->assertNoJavaScriptErrors();

    $this->assertAuthenticatedAs($user);
    expect($user->refresh()->remember_token)->not->toBeNull();
})->with(['EN desktop' => ['en', 1440], 'DE mobile' => ['de', 390]]);

test('the login page remains usable before Reverb credentials are configured', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => null]);

    visit('/login')->assertSee('Sign in')->click('Forgot your password?')
        ->assertPathIs('/forgot-password')->assertSee('Send reset link')->assertNoJavaScriptErrors();
});

test('users complete the two factor login challenge through the OTP input', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $user = User::factory()->withTwoFactor()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
    ]);
    $user->assignRole('Owner');

    $page = visit('/login')
        ->fill('Email address', $user->email)
        ->fill('Password', 'password')
        ->click('[data-test="login-button"]')
        ->assertPathIs('/two-factor-challenge')
        ->assertSee('Authentication code')
        ->fill('code', '123')
        ->click('Continue')
        ->assertSee('The provided two factor authentication code was invalid.')
        ->assertPathIs('/two-factor-challenge');

    $code = app(Google2FA::class)->getCurrentOtp(decrypt($user->two_factor_secret));

    $page->keys('code', ['ControlOrMeta+A', 'Backspace'])
        ->typeSlowly('code', $code, 10)
        ->assertValue('code', $code)
        ->click('Continue')
        ->assertPathIs('/dashboard')
        ->assertSee('Add a URL to begin collecting results.')
        ->assertNoJavaScriptErrors();

    $this->assertAuthenticatedAs($user);
});
