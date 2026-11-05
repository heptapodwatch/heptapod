<?php

use Carbon\CarbonImmutable;
use Heptapod\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Laravel\Fortify\Features;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05T12:00:00Z'));
    $this->skipUnlessFortifyHas(Features::emailVerification());
});

test('email verification screen can be rendered', function (): void {
    $user = User::factory()->unverified()->create();

    $response = $this->actingAs($user)->get(route('verification.notice'));

    $response->assertOk();
});

test('unverified users are redirected to the email verification prompt', function (): void {
    $user = User::factory()->unverified()->create();

    $response = $this->actingAs($user)->get('/settings/appearance');

    $response->assertRedirect(route('verification.notice'));
});

test('the legacy appearance page redirects to profile and the device theme still renders', function (string $appearance, string $class): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/settings/appearance')->assertStatus(301)->assertHeader('Location', route('profile.edit'));
    $response = $this->withUnencryptedCookie('appearance', $appearance)->get(route('profile.edit'))->assertOk();
    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    expect($document->documentElement->getAttribute('class'))->toBe($class);
})->with(['dark' => ['dark', 'dark'], 'light' => ['light', ''], 'system' => ['system', '']]);

test('email can be verified', function (): void {
    $user = User::factory()->unverified()->create();

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );

    $response = $this->actingAs($user)->get($verificationUrl);

    Event::assertDispatched(Verified::class);

    $this->assertTrue($user->fresh()->hasVerifiedEmail());
    $response->assertRedirect(route('sites.index', absolute: false).'?verified=1');
});

test('email is not verified with invalid hash', function (): void {
    $user = User::factory()->unverified()->create();

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1('wrong-email')],
    );

    $this->actingAs($user)->get($verificationUrl);

    Event::assertNotDispatched(Verified::class);
    $this->assertFalse($user->fresh()->hasVerifiedEmail());
});

test('email is not verified with invalid user id', function (): void {
    $user = User::factory()->unverified()->create();

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => 123, 'hash' => sha1($user->email)],
    );

    $this->actingAs($user)->get($verificationUrl);

    Event::assertNotDispatched(Verified::class);
    $this->assertFalse($user->fresh()->hasVerifiedEmail());
});

test('verified user is redirected to sites from verification prompt', function (): void {
    $user = User::factory()->create();

    Event::fake();

    $response = $this->actingAs($user)->get(route('verification.notice'));

    Event::assertNotDispatched(Verified::class);
    $response->assertRedirect(route('sites.index', absolute: false));
});

test('already verified user visiting verification link is redirected without firing event again', function (): void {
    $user = User::factory()->create();

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );

    $this->actingAs($user)->get($verificationUrl)
        ->assertRedirect(route('sites.index', absolute: false).'?verified=1');

    Event::assertNotDispatched(Verified::class);
    $this->assertTrue($user->fresh()->hasVerifiedEmail());
});
