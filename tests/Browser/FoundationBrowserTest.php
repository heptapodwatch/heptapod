<?php

declare(strict_types=1);

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Support\Facades\Vite;

it('navigates the shell and opens search from the keyboard', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $this->seed(DemoSeeder::class);
    $this->actingAs(User::query()->where('email', DatabaseSeeder::DEMO_EMAIL)->sole());

    $page = visit('/dashboard')->assertSee('Monitor')->assertSee('Portfolio');
    $page->keys('[aria-label="Open command palette"]', 'Control+k')->assertPresent('[role=dialog]');
    $page->fill('[role=dialog] [role=combobox]', 'Demo Client 01')
        ->assertSeeIn('[role=dialog]', 'Demo Client 01')
        ->assertDontSee('shop.acme.example')
        ->keys('[role=dialog] [role=combobox]', 'Escape')
        ->assertNotPresent('[role=dialog]');
    expect($page->script('document.activeElement.getAttribute("aria-label")'))->toBe('Open command palette');
    $page->click('a[href="/clients"]')->assertPathIs('/clients');
    $site = Site::query()->where('friendly_name', 'Backup')->sole();
    $page->click('[aria-label="Open command palette"]')
        ->fill('[role=dialog] [role=combobox]', 'Backup')
        ->assertSee('Backup')
        ->click('[role=dialog] [cmdk-group]:has([cmdk-group-heading]:has-text("Sites")) [cmdk-item]:has-text("Backup")')
        ->assertPathIs('/sites/'.$site->id)->assertNoJavaScriptErrors();
});

it('renders the local foundation gallery with the status and run states', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $page = visit('/dev/components')->assertSee('Check error')->assertSee('Reconnecting')
        ->assertSee('Queued')->assertSee('Late')->assertSee('No sites yet');
    $page->click('[data-foundation-gallery] header button:text-is("DE")')->assertSee('Farben · Status');
    $page->click('[data-foundation-gallery] header button[aria-label="Dunkel"]');
    expect($page->script('document.documentElement.classList.contains("dark")'))->toBeTrue();
    $page->click('[data-foundation-gallery] header button[aria-label="Hell"]');
    expect($page->script('getComputedStyle(document.querySelector("[data-foundation-gallery] [aria-label=\\"Prüffehler\\"]")).backgroundColor'))->toBe('rgb(138, 148, 160)');
    $page->assertNoJavaScriptErrors();
});

it('keeps Upcoming navigation without a badge while sidebar counts defer', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $site = Site::factory()->create();
    MaintenancePeriod::factory()->create(['site_id' => $site->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour()]);
    $this->actingAs($owner);

    // Round-four cleanup brief, shared shell: only open incidents and late runs have navigation badges.
    $page = visit('/sites')->assertSeeIn('nav a[href="/upcoming"]', 'Upcoming')
        ->assertMissing('nav a[href="/upcoming"] span.font-mono');
    $page->script('(() => {
        window.__upcomingBadgeShown = false;
        new MutationObserver(() => {
            if (document.querySelector("nav a[href=\\"/upcoming\\"] span.font-mono")) window.__upcomingBadgeShown = true;
        }).observe(document.body, { subtree: true, childList: true, characterData: true });
    })()');
    $page->click('nav a[href="/activity"]')->assertPathIs('/activity')
        ->assertSeeIn('nav a[href="/upcoming"]', 'Upcoming')->assertMissing('nav a[href="/upcoming"] span.font-mono');
    expect($page->script('window.__upcomingBadgeShown'))->toBeFalse();
    $page->assertNoJavaScriptErrors();
});

it('opens the mobile sidebar sheet and navigates to a real page', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $this->seed(DemoSeeder::class);
    $this->actingAs(User::query()->where('email', DatabaseSeeder::DEMO_EMAIL)->sole());

    $page = visit('/dashboard')->resize(390, 844)
        ->click('header [data-sidebar="trigger"]')
        ->assertPresent('[data-mobile="true"][data-state="open"]');
    $page->click('[data-mobile="true"] a[href="/clients"]');
    expect($page->script('new Promise(resolve => { const deadline = Date.now() + 8000; const check = () => { if (location.pathname === "/clients" && document.querySelector("main h1")?.textContent?.includes("Clients") && !document.querySelector("[data-mobile=true][data-state=open]")) resolve(true); else if (Date.now() >= deadline) resolve(false); else setTimeout(check, 50); }; check(); })'))->toBeTrue();
    $page->assertPathIs('/clients')
        ->assertNotPresent('[data-mobile="true"][data-state="open"]')
        ->assertNoJavaScriptErrors();
});
