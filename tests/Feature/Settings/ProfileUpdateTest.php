<?php

use Heptapod\Contracts\LiveConnectionTerminator;
use Heptapod\Models\User;
use Heptapod\Testing\FakeLiveConnectionTerminator;
use Inertia\Testing\AssertableInertia;

test('profile page is displayed', function (): void {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk();
});

test('profile information can be updated', function (): void {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    $this->assertSame('Test User', $user->name);
    $this->assertSame('test@example.com', $user->email);
    $this->assertNull($user->email_verified_at);
});

test('email verification status is unchanged when the email address is unchanged', function (): void {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $this->assertNotNull($user->refresh()->email_verified_at);
});

test('profile preferences persist and replace the browser locale on save', function (): void {
    $user = User::factory()->create(['locale' => 'en', 'timezone' => 'UTC']);

    $this->actingAs($user)->withUnencryptedCookie('locale', 'en')
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'locale' => 'de',
            'timezone' => 'Europe/Berlin',
        ])
        ->assertSessionHasNoErrors()
        ->assertPlainCookie('locale', 'de')
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->locale)->toBe('de');
    expect($user->timezone)->toBe('Europe/Berlin');
    $this->withUnencryptedCookie('locale', 'en')->get(route('profile.edit'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('locale', 'de')->where('timezone', 'Europe/Berlin')
            ->where('auth.user.locale', 'de')->where('auth.user.timezone', 'Europe/Berlin'));
});

test('invalid profile values leave the entire profile unchanged', function (string $field, string $value): void {
    $user = User::factory()->create(['locale' => 'en', 'timezone' => 'UTC']);
    $original = $user->only(['name', 'email', 'locale', 'timezone']);

    $this->actingAs($user)->from(route('profile.edit'))
        ->patch(route('profile.update'), array_replace([
            'name' => 'Changed Name', 'email' => $user->email,
            'locale' => 'de', 'timezone' => 'Europe/Berlin',
        ], [$field => $value]))
        ->assertSessionHasErrors($field)
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->only(['name', 'email', 'locale', 'timezone']))->toBe($original);
})->with([
    'unsupported language' => ['locale', 'fr'],
    'unknown timezone' => ['timezone', 'Not/AZone'],
    'empty name' => ['name', ''],
    'invalid email' => ['email', 'invalid'],
]);

test('guests cannot save profile preferences', function (): void {
    $this->patch(route('profile.update'), [
        'name' => 'Guest', 'email' => 'guest@example.test', 'locale' => 'de', 'timezone' => 'Europe/Berlin',
    ])->assertRedirect(route('login'));
});

test('user can delete their account', function (): void {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete(route('profile.destroy'), [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertGuest();
    $this->assertNull($user->fresh());
    $terminator = app(LiveConnectionTerminator::class);
    expect($terminator)->toBeInstanceOf(FakeLiveConnectionTerminator::class);
    expect($terminator->terminated)->toBe([$user->id]);
});

test('correct password must be provided to delete account', function (): void {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrors('password')
        ->assertRedirect(route('profile.edit'));

    $this->assertNotNull($user->fresh());
    $terminator = app(LiveConnectionTerminator::class);
    expect($terminator)->toBeInstanceOf(FakeLiveConnectionTerminator::class);
    expect($terminator->terminated)->toBeEmpty();
});
