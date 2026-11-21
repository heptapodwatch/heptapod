<?php

use Heptapod\Jobs\TerminateLiveConnections;
use Heptapod\Models\User;
use Illuminate\Support\Facades\Queue;
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
            'current_password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    $this->assertSame('Test User', $user->name);
    $this->assertSame('test@example.com', $user->email);
    $this->assertNull($user->email_verified_at);
});

test('changing the email address requires the current password', function (?string $currentPassword): void {
    $user = User::factory()->create();
    $original = $user->email;

    $this->actingAs($user)->from(route('profile.edit'))
        ->patch(route('profile.update'), array_filter(['name' => $user->name, 'email' => 'taken-over@example.test',
            'current_password' => $currentPassword]))
        ->assertSessionHasErrors('current_password')
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->email)->toBe($original)->and($user->email_verified_at)->not->toBeNull();
})->with([
    'missing' => [null],
    'wrong' => ['not-the-password'],
]);

test('a saved email address is stored the way sign-in resolves it and stays unique regardless of case', function (): void {
    User::factory()->create(['email' => 'taken@example.test']);
    $user = User::factory()->create();

    $this->actingAs($user)->from(route('profile.edit'))
        ->patch(route('profile.update'), ['name' => $user->name, 'email' => 'Taken@Example.test', 'current_password' => 'password'])
        ->assertSessionHasErrors('email');
    $this->patch(route('profile.update'), ['name' => $user->name, 'email' => '  New.Address@Example.TEST ', 'current_password' => 'password'])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->email)->toBe('new.address@example.test');
    $this->post(route('logout'));
    $this->post(route('login'), ['email' => 'New.Address@Example.test', 'password' => 'password']);
    $this->assertAuthenticatedAs($user);
});

test('an address saved in mixed case by an older version stays as it is on an unrelated profile edit', function (): void {
    $user = User::factory()->create(['email' => 'Legacy.Address@Example.test', 'locale' => 'en']);

    $this->actingAs($user)->patch(route('profile.update'), ['name' => 'Renamed', 'email' => $user->email, 'locale' => 'de'])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->only(['name', 'email', 'locale']))->toBe(['name' => 'Renamed', 'email' => 'Legacy.Address@Example.test', 'locale' => 'de'])
        ->and($user->email_verified_at)->not->toBeNull();
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

test('invalid profile values leave the entire profile unchanged', function (string $field, mixed $value): void {
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
    'email that is not text' => ['email', ['one@example.test', 'two@example.test']],
]);

test('guests cannot save profile preferences', function (): void {
    $this->patch(route('profile.update'), [
        'name' => 'Guest', 'email' => 'guest@example.test', 'locale' => 'de', 'timezone' => 'Europe/Berlin',
    ])->assertRedirect(route('login'));
});

test('user can delete their account', function (): void {
    Queue::fake([TerminateLiveConnections::class]);
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
    expect(Queue::pushed(TerminateLiveConnections::class)->pluck('userId')->all())->toBe([$user->id]);
});

test('correct password must be provided to delete account', function (): void {
    Queue::fake([TerminateLiveConnections::class]);
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
    Queue::assertNotPushed(TerminateLiveConnections::class);
});
