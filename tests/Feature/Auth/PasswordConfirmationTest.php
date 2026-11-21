<?php

use Carbon\CarbonImmutable;
use Heptapod\Models\User;
use Inertia\Testing\AssertableInertia;
use Inertia\Testing\AssertableInertia as Assert;

test('confirm password screen can be rendered', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('password.confirm'));

    $response->assertOk();

    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->component('auth/confirm-password'),
    );
});

test('password confirmation requires authentication', function (): void {
    $response = $this->get(route('password.confirm'));

    $response->assertRedirect(route('login'));
});

test('password confirmation is rate-limited per user and IP', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-31T12:00:00Z'));
    $user = User::factory()->create();
    $this->actingAs($user);

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $this->postJson(route('password.confirm.store'), ['password' => 'wrong-password'])
            ->assertUnprocessable();
    }

    $this->postJson(route('password.confirm.store'), ['password' => 'password'])
        ->assertTooManyRequests()
        ->assertHeader('Retry-After', '60')
        ->assertJsonPath('code', 'rate_limited')
        ->assertJsonPath('message', 'Too many requests. Please try again in 1 min.');

    $this->from(route('password.confirm'))
        ->withHeader('X-Inertia', 'true')
        ->post(route('password.confirm.store'), ['password' => 'password'])
        ->assertRedirect(route('password.confirm'))
        ->assertSessionHasErrors('_refusal');

    expect(session()->has('auth.password_confirmed_at'))->toBeFalse();
});
