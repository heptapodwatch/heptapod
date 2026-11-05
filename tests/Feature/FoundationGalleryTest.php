<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-28T12:00:00Z'));
});

test('foundation gallery is anonymous in local and testing, absent in production', function (): void {
    $this->get('/dev/components')->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->component('dev/components')
        ->where('auth.user', null));

    $login = Route::getRoutes()->getByName('login') ?? throw new LogicException('Fortify login route is required.');
    app()->instance('env', 'production');
    Route::setRoutes(new RouteCollection);
    Route::getRoutes()->add($login);
    Route::middleware('web')->group(base_path('routes/web.php'));
    Route::getRoutes()->refreshNameLookups();
    $this->get('/dev/components')->assertNotFound();
    $this->get('/design/components')->assertNotFound();
});

test('locale cookie is validated and shared', function (): void {
    $this->withUnencryptedCookie('locale', 'de')->get('/dev/components')->assertInertia(fn (Assert $page): Assert => $page->where('locale', 'de'));
    $this->withUnencryptedCookie('locale', 'unsupported')->get('/dev/components')->assertInertia(fn (Assert $page): Assert => $page->where('locale', 'en'));
    $this->get('/')->assertRedirect('/login');
});
