<?php

use Carbon\CarbonImmutable;
use Heptapod\Models\User;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05T12:00:00Z'));
});

test('login screen presents the configured instance and current host', function (): void {
    $instance = app(InstanceSettings::class);
    $instance->name = 'Agency & Partners Monitoring';
    $instance->save();

    $this->get('http://review-login.test:15910/login')->assertInertia(fn (Assert $page): Assert => $page
        ->component('auth/login')
        ->where('instance', 'Agency & Partners Monitoring')
        ->where('host', 'review-login.test:15910')
    );
});

test('users can authenticate using the login screen', function (): void {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('sites.index', absolute: false));
});

test('a signed-in session without a stored password hash signs in again', function (): void {
    $user = User::factory()->owner()->create();
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $this->get(route('sites.index'))->assertOk();

    // A session from before sign-ins were bound carries no hash; binding it now would let it outlive a password reset.
    session()->forget('password_hash_web');

    $this->get(route('sites.index'))->assertRedirect(route('login'));
    $this->assertGuest();
});

test('a remembered sign-in still works once its session is gone', function (): void {
    $user = User::factory()->owner()->create();
    $recaller = Auth::guard('web')->getRecallerName();
    $remembered = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password', 'remember' => 'on'])
        ->getCookie($recaller)?->getValue();
    expect($remembered)->toBeString();

    Auth::forgetGuards();
    session()->flush();

    $this->withCookie($recaller, $remembered)->get(route('sites.index'))->assertOk();
    $this->assertAuthenticatedAs($user);
});

test('users with two factor enabled are redirected to two factor challenge', function (): void {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->withTwoFactor()->create();

    $response = $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('two-factor.login'));
    $response->assertSessionHas('login.id', $user->id);
    $this->assertGuest();
});

test('users can not authenticate with invalid password', function (): void {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('users can logout', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $response->assertRedirect(route('home'));

    $this->assertGuest();
});

test('users are rate limited', function (): void {
    $user = User::factory()->create();

    RateLimiter::increment(md5('login'.implode('|', [$user->email, '127.0.0.1'])), amount: 5);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertTooManyRequests();
});
