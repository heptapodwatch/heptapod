<?php

use Heptapod\Jobs\TerminateLiveConnections;
use Heptapod\Models\User;
use Heptapod\Notifications\PasswordReset;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Fortify\Features;

beforeEach(function (): void {
    $this->skipUnlessFortifyHas(Features::resetPasswords());
});

test('reset password link screen can be rendered', function (): void {
    $response = $this->get(route('password.request'));

    $response->assertOk();
});

test('reset password link can be requested', function (): void {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, PasswordReset::class);
});

test('reset password screen can be rendered', function (): void {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, PasswordReset::class, function ($notification): true {
        $response = $this->get(route('password.reset', $notification->token));

        $response->assertOk();

        return true;
    });
});

test('password can be reset with valid token', function (): void {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, PasswordReset::class, function ($notification) use ($user): true {
        $response = $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login'));

        return true;
    });
});

test('a password reset ends every other session, API token and live connection of the account', function (): void {
    Notification::fake();
    Queue::fake([TerminateLiveConnections::class]);
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $apiToken = $user->createToken('before-reset', ['monitors:read'])->plainTextToken;
    // Signed in, but no request since: the sign-in itself binds the session.
    $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])->assertRedirect();
    $otherBrowser = session()->all();

    // The reset happens in a second, signed-out browser.
    Auth::forgetGuards();
    session()->flush();
    $this->post(route('password.email'), ['email' => $user->email]);
    Notification::assertSentTo($user, PasswordReset::class, function (PasswordReset $notification) use ($user): true {
        $this->post(route('password.update'), ['token' => $notification->token, 'email' => $user->email,
            'password' => 'a-new-password', 'password_confirmation' => 'a-new-password'])->assertSessionHasNoErrors()->assertRedirect(route('login'));

        return true;
    });

    Auth::forgetGuards();
    session()->flush();
    session()->put($otherBrowser);
    $this->get(route('sites.index'))->assertRedirect(route('login'));
    $this->assertGuest();
    Auth::forgetGuards();
    $this->withToken($apiToken)->getJson('/api/monitors')->assertUnauthorized();
    expect(Queue::pushed(TerminateLiveConnections::class)->pluck('userId')->all())->toBe([$user->id]);
});

test('password cannot be reset with invalid token', function (): void {
    $user = User::factory()->create();

    $response = $this->post(route('password.update'), [
        'token' => 'invalid-token',
        'email' => $user->email,
        'password' => 'newpassword123',
        'password_confirmation' => 'newpassword123',
    ]);

    $response->assertSessionHasErrors('email');
});
