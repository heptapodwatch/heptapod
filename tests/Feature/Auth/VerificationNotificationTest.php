<?php

use Carbon\CarbonImmutable;
use Heptapod\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05T12:00:00Z'));
    $this->skipUnlessFortifyHas(Features::emailVerification());
});

test('sends verification notification', function (): void {
    Notification::fake();

    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->post(route('verification.send'))
        ->assertRedirect(route('home'));

    Notification::assertSentTo($user, VerifyEmail::class);
});

test('does not send verification notification if email is verified', function (): void {
    Notification::fake();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('verification.send'))
        ->assertRedirect(route('sites.index', absolute: false));

    Notification::assertNothingSent();
});
