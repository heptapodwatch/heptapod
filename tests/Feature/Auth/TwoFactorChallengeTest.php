<?php

use Heptapod\Actions\DeactivateMember;
use Heptapod\Actions\Fortify\ResetUserPassword;
use Heptapod\Jobs\TerminateLiveConnections;
use Heptapod\Models\User;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function (): void {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
});

test('two factor challenge redirects to login when not authenticated', function (): void {
    $response = $this->get(route('two-factor.login'));

    $response->assertRedirect(route('login'));
});

test('a pending two factor challenge cannot be completed after the account was removed or its password reset', function (string $revocation): void {
    Queue::fake([TerminateLiveConnections::class]);
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $member = User::factory()->withTwoFactor()->create();
    $member->assignRole('Member');

    $this->post(route('login'), ['email' => $member->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));
    $this->assertGuest();
    match ($revocation) {
        'removal' => app(DeactivateMember::class)($owner, $member),
        'password reset' => app(ResetUserPassword::class)->reset($member, ['token' => Password::createToken($member),
            'password' => 'a-new-password-1', 'password_confirmation' => 'a-new-password-1']),
    };
    $this->postJson(route('two-factor.login'), ['recovery_code' => 'recovery-code-1'])
        ->assertUnprocessable();
    $this->assertGuest();
})->with(['removal', 'password reset']);

test('an active user completes the two factor challenge with either TOTP or a recovery code', function (string $factor): void {
    $user = User::factory()->withTwoFactor()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
    ]);
    $user->assignRole('Member');
    $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));
    $this->assertGuest();
    $this->postJson(route('two-factor.login'), $factor === 'totp'
        ? ['code' => app(Google2FA::class)->getCurrentOtp(decrypt($user->two_factor_secret))]
        : ['recovery_code' => 'recovery-code-1'])->assertNoContent()->assertSessionMissing('login.id');
    $this->assertAuthenticatedAs($user);
})->with(['totp', 'recovery']);

test('two factor challenge can be rendered', function (): void {
    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->withTwoFactor()->create();

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->get(route('two-factor.login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): AssertableInertia => $page
            ->component('auth/two-factor-challenge'),
        );
});
