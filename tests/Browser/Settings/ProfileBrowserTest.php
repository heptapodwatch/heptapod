<?php

declare(strict_types=1);

use Heptapod\Models\User;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Vite;

it('discards a draft and saves profile preferences across reloads', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    config(['broadcasting.default' => 'null']);
    $user = User::factory()->create(['name' => 'Profile User', 'locale' => 'en', 'timezone' => 'UTC']);
    $this->actingAs($user);

    $page = visit('/settings/profile')->assertSee('Language and time')->assertDontSee('Theme');
    $page->fill('name', 'Discard Me')->select('locale', 'de')->select('timezone', 'Europe/Berlin')
        ->assertEnabled('[data-test="update-profile-button"]')
        ->click('Discard')->assertValue('name', 'Profile User')
        ->assertValue('locale', 'en')->assertValue('timezone', 'UTC')
        ->assertSee('No unsaved changes')->assertDisabled('[data-test="update-profile-button"]');
    expect($user->refresh()->name)->toBe('Profile User');

    $page->fill('name', '  Saved User  ')->select('locale', 'de')->select('timezone', 'Europe/Berlin')
        ->click('Save')->assertSee('Profil aktualisiert.')
        ->assertDisabled('[data-test="update-profile-button"]')->assertValue('name', 'Saved User');
    $page->fill('name', 'Another draft')->click('Verwerfen')->assertValue('name', 'Saved User');
    $page->refresh()->assertValue('name', 'Saved User')
        ->assertValue('locale', 'de')->assertValue('timezone', 'Europe/Berlin')
        ->assertSee('Sprache und Zeit')->assertNoJavaScriptErrors();
});

it('focuses the enabled invalid email after keyboard submission', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    config(['broadcasting.default' => 'null']);
    $user = User::factory()->create();
    $this->actingAs($user);
    $page = visit('/settings/profile')->assertSee('Language and time');
    $originalEmail = $user->email;

    $page->fill('email', 'broken')->keys('email', 'Enter')
        ->assertValue('email', 'broken')->assertSee('The email field must be a valid email address.')
        ->assertEnabled('#email');
    expect($page->script('new Promise(resolve => { const deadline = Date.now() + 2000; const check = () => { const input = document.getElementById("email"); if (document.activeElement === input && !input.matches(":disabled")) resolve(true); else if (Date.now() >= deadline) resolve(false); else setTimeout(check, 20); }; check(); })'))->toBeTrue();
    expect($user->refresh()->email)->toBe($originalEmail);
    $page->fill('name', 'Still editing');
    expect($page->script('document.activeElement.id'))->toBe('name');
    $page->assertNoJavaScriptErrors();
});

it('switches the device theme from the user menu without patching the account', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    config(['broadcasting.default' => 'null']);
    $user = User::factory()->create(['name' => 'Theme User']);
    $this->actingAs($user);
    $patches = 0;
    Event::listen(RequestHandled::class, function (RequestHandled $event) use (&$patches): void {
        if ($event->request->isMethod('PATCH')) {
            $patches++;
        }
    });
    $page = visit('/sites');
    $page->click('[data-test="sidebar-menu-button"]')->assertDontSeeIn('[role="menu"]', 'Settings')
        ->click('[role="menuitemradio"]:has-text("Dark")');
    expect($page->script('document.documentElement.classList.contains("dark")'))->toBeTrue();
    expect($page->script('localStorage.getItem("appearance")'))->toBe('dark');
    $page->refresh();
    expect($page->script('document.documentElement.classList.contains("dark")'))->toBeTrue();
    expect($patches)->toBe(0);
    expect($user->refresh()->name)->toBe('Theme User');
    $page->assertNoJavaScriptErrors();
});

it('renders saved account language and timezone despite an older device language cookie', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    config(['broadcasting.default' => 'null']);
    $user = User::factory()->create(['locale' => 'en', 'timezone' => 'UTC']);
    $this->actingAs($user);
    $older = visit('/settings/profile')->assertSee('Language and time');
    $other = visit('/settings/profile');
    $other->select('locale', 'de')->select('timezone', 'America/New_York')->click('Save')
        ->assertSee('Sprache und Zeit')->assertDisabled('[data-test="update-profile-button"]');
    $older->script('document.cookie = "locale=en;path=/;SameSite=Lax"');

    $older->refresh()->assertSee('Sprache und Zeit')->assertValue('locale', 'de')
        ->assertValue('timezone', 'America/New_York')->assertNoJavaScriptErrors();
    expect($older->script('document.documentElement.lang'))->toBe('de');
    expect($user->refresh()->locale)->toBe('de');
    expect($user->timezone)->toBe('America/New_York');
});

it('keeps security reachable for members without advertising unavailable settings', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    config(['broadcasting.default' => 'null']);
    $user = User::factory()->create();
    $user->assignRole('Member');
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

    visit('/settings/profile')->assertDontSee('Members')->assertSee('API tokens')
        ->click('main a[href="/settings/security"]')->assertPathIs('/settings/security')
        ->assertSee('Update password')->assertSee('Passkeys')->assertSee('Two-factor authentication')
        ->click('aside a[data-sidebar="menu-button"][href="/settings/profile"]')
        ->assertPathIs('/settings/profile')->assertNoJavaScriptErrors();
});
