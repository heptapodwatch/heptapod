<?php

use Heptapod\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

it('does not expose a public registration page', function (): void {
    $this->get('/register')->assertNotFound();
    expect(Route::has('register'))->toBeFalse();
});

it('does not accept public registration submissions', function (): void {
    $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    expect(Route::has('register.store'))->toBeFalse();
    expect(User::query()->count())->toBe(0);
    $this->assertGuest();
});
