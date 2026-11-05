<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckResult;
use Heptapod\Models\Check;
use Heptapod\Models\Client;
use Heptapod\Models\DispatcherHeartbeat;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;

use function Amp\delay;

require_once __DIR__.'/Support/NavigationTrace.php';

function waitForTodaySeen(User $user, ?CarbonImmutable $previous = null): void
{
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $seen = $user->refresh()->last_seen_at;
        if ($seen !== null && (! $previous instanceof CarbonImmutable || ! $seen->equalTo($previous))) {
            return;
        }
        delay(0.1);
    }
    expect($user->refresh()->last_seen_at)->not->toEqual($previous);
}

it('shows the missing alert-route warning only for monitored checks without coverage', function (string $scenario, bool $visible): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $this->artisan('heptapod:seed-demo', ['--scenario' => $scenario])->assertSuccessful();
    $this->actingAs(User::query()->where('email', DatabaseSeeder::DEMO_EMAIL)->sole());

    $page = visit('/');
    if ($visible) {
        $page->assertSee('No monitored check has an alert route.')
            ->assertSeeIn('[data-slot="sidebar-footer"]', 'No alert route');
    } else {
        $page->assertDontSee('No monitored check has an alert route.')
            ->assertDontSeeIn('[data-slot="sidebar-footer"]', 'No alert route');
    }
    if ($scenario === 'calm') {
        $page->assertSee('Nothing needs attention. Oldest result:');
    }
    if ($scenario === 'nothing-checked') {
        $page->assertSeeIn('[role="alert"]', 'Paused is not the same as fine.')
            ->assertDontSee('All sites, 90 days');
    }
    traceTodayNavigation($page, 'nav a[href="/activity"]', '/activity');
    $page->assertPathIs('/activity')->assertSee('State changes across checks');
    if ($visible) {
        $page->assertSeeIn('[data-slot="sidebar-footer"]', 'No alert route');
    } else {
        $page->assertDontSeeIn('[data-slot="sidebar-footer"]', 'No alert route');
    }
    $page->assertNoJavaScriptErrors();
})->with(['no-channel' => ['no-channel', true], 'calm' => ['calm', false], 'nothing-checked' => ['nothing-checked', false]]);

it('keeps Today focused on attention without portfolio KPI tiles', function (string $locale, string $expiryLabel, string $taskLabel): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $this->artisan('heptapod:seed-demo', ['--scenario' => 'attention'])->assertSuccessful();
    $owner = User::query()->where('email', DatabaseSeeder::DEMO_EMAIL)->sole();
    $owner->update(['locale' => $locale]);
    $this->actingAs($owner);

    $page = visit('/');
    $cardHeaders = $page->script('Array.from(document.querySelectorAll("main [data-slot=card-header]"), header => header.textContent)');
    expect(collect($cardHeaders)->contains(fn (string $header): bool => str_contains($header, $expiryLabel) || str_contains($header, $taskLabel)))->toBeFalse();
    $page->assertPresent('[aria-label="'.($locale === 'de' ? 'Handlungsbedarf' : 'Needs attention').'"]')
        ->assertSeeIn('button[aria-current="page"]', $locale === 'de' ? 'Offene Probleme' : 'Open issues')
        ->assertSeeIn('main a[href="/sites"]', $locale === 'de' ? 'Alle Websites' : 'All sites')
        ->assertPresent('a[href^="/incidents/"]')->assertNoJavaScriptErrors();
    traceTodayNavigation($page, 'main a[href="/sites"]', '/sites');
    $page->assertPathIs('/sites')->assertNoJavaScriptErrors();
})->with([
    'EN' => ['en', 'Expiring in 30 days', 'Scheduled tasks'],
    'DE' => ['de', 'Ablauf in 30 Tagen', 'Geplante Aufgaben'],
]);

function registerNavigationRecoveryTarget(): void
{
    // Exercise real non-Inertia HTTP navigation without depending on Pest's static-asset transport.
    Route::get('/__browser/navigation-target', static fn (): Response => response('Navigation recovery destination', 200, ['Content-Type' => 'text/plain']));
}

it('hard-navigates to the intended URL after a page-chunk failure', function (?string $importError, bool $visitFinished = false): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    registerNavigationRecoveryTarget();
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $this->actingAs($owner);

    $page = visit('/dashboard')->assertSee('Start monitoring your first site');
    $path = '/__browser/navigation-target';
    $failureScript = $importError === null
        ? 'new Event("vite:preloadError", { cancelable: true }); window.dispatchEvent(failure);'
        : 'new CustomEvent("inertia:networkError", { cancelable: true, detail: { error: new TypeError('.json_encode($importError).') } }); document.dispatchEvent(failure);';
    expect($page->script('(() => {
        window.__hardNavigationMarker = true;
        const visit = { url: new URL('.json_encode($path).', location.origin), method: "get", prefetch: false, only: [], except: [] };
        document.dispatchEvent(new CustomEvent("inertia:before", { detail: { visit } }));
        if ('.json_encode($visitFinished).') document.dispatchEvent(new CustomEvent("inertia:finish", { detail: { visit } }));
        const failure = '.$failureScript.'
        return failure.defaultPrevented;
    })()'))->toBeTrue();
    waitForBrowserPath($page, $path);
    expect($page->script('window.__hardNavigationMarker'))->toBeNull()
        ->and($page->script('Number(sessionStorage.getItem("heptapod:navigation-retry:" + location.href))'))->toBeGreaterThan(0);
    $page->assertSee('Navigation recovery destination')->assertNoJavaScriptErrors();
})->with([
    'Vite preload' => [null],
    'Vite preload after Inertia finishes' => [null, true],
    'Chrome dynamic import after Inertia finishes' => ['Failed to fetch dynamically imported module: /build/assets/activity.js', true],
    'Chrome dynamic import' => ['Failed to fetch dynamically imported module: /build/assets/site.js'],
    'Safari dynamic import' => ['Importing a module script failed.'],
    'Firefox dynamic import' => ['error loading dynamically imported module'],
]);

it('does not hard-navigate a chunk failure outside this origin', function (string $target): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $this->actingAs($owner);

    $page = visit('/dashboard')->assertSee('Start monitoring your first site');
    expect($page->script('(() => {
        const target = '.json_encode($target).';
        document.dispatchEvent(new CustomEvent("inertia:before", { detail: {
            visit: { url: new URL(target), method: "get", prefetch: false, only: [], except: [] },
        } }));
        const failure = new Event("vite:preloadError", { cancelable: true });
        window.dispatchEvent(failure);
        return failure.defaultPrevented;
    })()'))->toBeFalse();
    $page->assertPathIs('/dashboard')->assertNoJavaScriptErrors();
})->with([
    'different origin' => ['http://127.0.0.1:9/robots.txt'],
    'non-HTTP URL' => ['data:text/plain,blocked'],
]);

it('retries a failed top-level GET at the intended same-origin URL including its query', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    registerNavigationRecoveryTarget();
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $this->actingAs($owner);

    $page = visit('/dashboard')->assertSee('Start monitoring your first site');
    expect($page->script('(() => {
        const target = new URL("/__browser/navigation-target?source=retry", location.origin);
        const visit = { url: target, method: "get", prefetch: false, async: false, only: [], except: [], reset: [] };
        document.dispatchEvent(new CustomEvent("inertia:before", { detail: { visit } }));
        document.dispatchEvent(new CustomEvent("inertia:finish", { detail: { visit } }));
        const failure = new CustomEvent("inertia:networkError", { cancelable: true, detail: {
            error: Object.assign(new Error("Network error"), { url: target.href }),
        } });
        document.dispatchEvent(failure);
        return failure.defaultPrevented;
    })()'))->toBeTrue();
    waitForBrowserPath($page, '/__browser/navigation-target');
    expect($page->script('location.search'))->toBe('?source=retry');
    $page->assertSee('Navigation recovery destination')->assertNoJavaScriptErrors();
});

it('does not retry a failed non-navigation or cross-origin request', function (array $override, string $target): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $this->actingAs($owner);

    $page = visit('/dashboard')->assertSee('Start monitoring your first site');
    expect($page->script('(() => {
        const target = '.json_encode($target).';
        const visit = { url: new URL(target, location.origin), method: "get", prefetch: false, async: false, only: [], except: [], reset: [], ...'.json_encode($override).' };
        document.dispatchEvent(new CustomEvent("inertia:before", { detail: { visit } }));
        const failure = new CustomEvent("inertia:networkError", { cancelable: true, detail: {
            error: Object.assign(new Error("Network error"), { url: visit.url.href }),
        } });
        document.dispatchEvent(failure);
        return failure.defaultPrevented;
    })()'))->toBeFalse();
    $page->assertPathIs('/dashboard')->assertNoJavaScriptErrors();
})->with([
    'partial only' => [['only' => ['tiles']], '/robots.txt'],
    'partial except' => [['except' => ['tiles']], '/robots.txt'],
    'partial reset' => [['reset' => ['tiles']], '/robots.txt'],
    'background reload' => [['async' => true], '/robots.txt'],
    'prefetch' => [['prefetch' => true], '/robots.txt'],
    'write' => [['method' => 'post'], '/robots.txt'],
    'cross origin' => [[], 'http://127.0.0.1:9/robots.txt'],
]);

it('does not retry the same URL twice within sixty seconds', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $this->actingAs($owner);

    $page = visit('/dashboard')->assertSee('Start monitoring your first site');
    expect($page->script('(() => {
        const target = new URL("/robots.txt", location.origin);
        sessionStorage.setItem("heptapod:navigation-retry:" + target.href, String(Date.now()));
        document.dispatchEvent(new CustomEvent("inertia:before", { detail: {
            visit: { url: target, method: "get", prefetch: false, only: [], except: [] },
        } }));
        const failure = new CustomEvent("inertia:networkError", { cancelable: true, detail: { error: new Error("Network error") } });
        document.dispatchEvent(failure);
        return failure.defaultPrevented;
    })()'))->toBeFalse();
    $page->assertPathIs('/dashboard')->assertNoJavaScriptErrors();
});

it('replaces an interrupted chunk recovery target with the next visit', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    registerNavigationRecoveryTarget();
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $this->actingAs($owner);

    $page = visit('/dashboard')->assertSee('Start monitoring your first site');
    expect($page->script('(() => {
        const visit = path => ({ url: new URL(path, location.origin), method: "get", prefetch: false, only: [], except: [] });
        const oldVisit = visit("/up");
        document.dispatchEvent(new CustomEvent("inertia:before", { detail: { visit: oldVisit } }));
        document.dispatchEvent(new CustomEvent("inertia:before", { detail: { visit: visit("/__browser/navigation-target") } }));
        document.dispatchEvent(new CustomEvent("inertia:finish", { detail: { visit: oldVisit } }));
        const failure = new Event("vite:preloadError", { cancelable: true });
        window.dispatchEvent(failure);
        return failure.defaultPrevented;
    })()'))->toBeTrue();
    waitForBrowserPath($page, '/__browser/navigation-target');
    $page->assertSee('Navigation recovery destination')->assertNoJavaScriptErrors();
});

it('discards a successful visit before a later unrelated chunk error', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $this->actingAs($owner);

    $page = visit('/dashboard')->assertSee('Start monitoring your first site');
    expect($page->script('(() => {
        const visit = { url: new URL("/robots.txt", location.origin), method: "get", prefetch: false, only: [], except: [] };
        document.dispatchEvent(new CustomEvent("inertia:before", { detail: { visit } }));
        document.dispatchEvent(new CustomEvent("inertia:navigate", { detail: { page: { url: "/robots.txt" } } }));
        const failure = new Event("vite:preloadError", { cancelable: true });
        window.dispatchEvent(failure);
        return failure.defaultPrevented;
    })()'))->toBeFalse();
    $page->assertPathIs('/dashboard')->assertNoJavaScriptErrors();
});

it('does not retry an old destination after a redirect and later partial network error', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $this->actingAs($owner);

    $page = visit('/dashboard')->assertSee('Start monitoring your first site');
    expect($page->script('(() => {
        const visit = { id: "redirected", url: new URL("/robots.txt", location.origin), method: "get", prefetch: false, async: false, only: [], except: [] };
        document.dispatchEvent(new CustomEvent("inertia:before", { detail: { visit } }));
        document.dispatchEvent(new CustomEvent("inertia:navigate", { detail: { page: { url: "/clients" }, visitId: visit.id } }));
        document.dispatchEvent(new CustomEvent("inertia:success", { detail: { page: { url: "/clients" }, visitId: visit.id } }));
        const failure = new CustomEvent("inertia:networkError", { cancelable: true, detail: { error: new Error("partial reload failed") } });
        document.dispatchEvent(failure);
        return failure.defaultPrevented;
    })()'))->toBeFalse();
    $page->assertPathIs('/dashboard')->assertNoJavaScriptErrors();
});

it('does not retry an old destination after HTTP rejection and later write failure', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $this->actingAs($owner);

    $page = visit('/dashboard')->assertSee('Start monitoring your first site');
    expect($page->script('(() => {
        document.dispatchEvent(new CustomEvent("inertia:before", { detail: {
            visit: { id: "rejected", url: new URL("/robots.txt", location.origin), method: "get", prefetch: false, async: false, only: [], except: [] },
        } }));
        document.dispatchEvent(new CustomEvent("inertia:httpException", { cancelable: true, detail: { response: { status: 422 } } }));
        const failure = new CustomEvent("inertia:networkError", { cancelable: true, detail: { error: new Error("POST failed") } });
        document.dispatchEvent(failure);
        return failure.defaultPrevented;
    })()'))->toBeFalse();
    $page->assertPathIs('/dashboard')->assertNoJavaScriptErrors();
});

it('does not retry a cancelled destination after an unrelated preload failure', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $this->actingAs($owner);

    $page = visit('/dashboard')->assertSee('Start monitoring your first site');
    expect($page->script('(() => {
        const visit = { id: "cancelled", url: new URL("/robots.txt", location.origin), method: "get", prefetch: false, async: false, only: [], except: [], cancelled: true, interrupted: false };
        document.dispatchEvent(new CustomEvent("inertia:before", { detail: { visit } }));
        document.dispatchEvent(new CustomEvent("inertia:finish", { detail: { visit } }));
        const failure = new Event("vite:preloadError", { cancelable: true });
        window.dispatchEvent(failure);
        return failure.defaultPrevented;
    })()'))->toBeFalse();
    $page->assertPathIs('/dashboard')->assertNoJavaScriptErrors();
});

it('discards an old destination when a new non-async write starts', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $this->actingAs($owner);

    $page = visit('/dashboard')->assertSee('Start monitoring your first site');
    expect($page->script('(() => {
        const visit = path => ({ url: new URL(path, location.origin), method: "get", prefetch: false, async: false, only: [], except: [] });
        document.dispatchEvent(new CustomEvent("inertia:before", { detail: { visit: visit("/robots.txt") } }));
        document.dispatchEvent(new CustomEvent("inertia:before", { detail: { visit: { ...visit("/dashboard"), method: "post" } } }));
        const failure = new Event("vite:preloadError", { cancelable: true });
        window.dispatchEvent(failure);
        return failure.defaultPrevented;
    })()'))->toBeFalse();
    $page->assertPathIs('/dashboard')->assertNoJavaScriptErrors();
});

it('expires an unfinished destination before an unrelated preload failure', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $this->actingAs($owner);

    $page = visit('/dashboard')->assertSee('Start monitoring your first site');
    expect($page->script('(() => {
        const now = Date.now;
        try {
            document.dispatchEvent(new CustomEvent("inertia:before", { detail: {
                visit: { url: new URL("/robots.txt", location.origin), method: "get", prefetch: false, async: false, only: [], except: [] },
            } }));
            Date.now = () => now() + 31_000;
            const failure = new Event("vite:preloadError", { cancelable: true });
            window.dispatchEvent(failure);
            return failure.defaultPrevented;
        } finally {
            Date.now = now;
        }
    })()'))->toBeFalse();
    $page->assertPathIs('/dashboard')->assertNoJavaScriptErrors();
});

it('records leaving Today after an Inertia logout and login rotate the CSRF token', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');

    $page = visit('/login')->fill('#email', $owner->email)->fill('#password', 'password')->click('[data-test="login-button"]');
    $page->assertSee('Start monitoring your first site');
    $page->script('(() => {
        window.__originalDocument = true;
        window.__initialCsrf = document.querySelector("meta[name=csrf-token]").content;
        document.addEventListener("inertia:navigate", event => { window.__currentCsrf = event.detail.page.props.csrfToken; });
        const originalFetch = window.fetch;
        window.fetch = (url, options) => {
            if (String(url).includes("/today/leave")) window.__leaveToken = options.headers["X-CSRF-TOKEN"];
            return originalFetch(url, options);
        };
    })()');
    $page->click('[data-test="sidebar-menu-button"]')->click('[data-test="logout-button"]');
    waitForBrowserPath($page, '/login');
    $page->fill('#email', $owner->email)->fill('#password', 'password')->click('[data-test="login-button"]');
    $page->assertSee('Start monitoring your first site');
    expect($page->script('window.__originalDocument && document.querySelector("meta[name=csrf-token]").content === window.__initialCsrf'))->toBeTrue()
        ->and($page->script('typeof window.__currentCsrf === "string" && window.__currentCsrf !== window.__initialCsrf'))->toBeTrue();
    expect($owner->refresh()->last_seen_at)->toBeNull();
    $page->click('nav a[href="/activity"]');
    waitForBrowserPath($page, '/activity');
    expect($page->script('window.__leaveToken === window.__currentCsrf'))->toBeTrue();
    waitForTodaySeen($owner);
    $page->assertNoJavaScriptErrors();
});

it('offers only sidebar navigation links that resolve to available pages', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $this->actingAs($owner);

    $page = visit('/dashboard');
    $paths = $page->script('Array.from(document.querySelectorAll("[data-slot=sidebar-content] nav a, [data-slot=sidebar-footer] nav a"), link => new URL(link.href).pathname)');
    expect($paths)->not->toBeEmpty();
    foreach (array_unique($paths) as $path) {
        $this->get($path)->assertOk();
    }
    $page->assertPresent('nav a[href="/runs"]')->click('nav a[href="/activity"]')
        ->assertPathIs('/activity')->assertNoJavaScriptErrors();
});

it('keeps the topbar command palette without a sidebar search button', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $this->actingAs($owner);

    visit('/')->assertMissing('[aria-label="Search from sidebar"]')->click('[aria-label="Open command palette"]')
        ->assertPresent('[role=dialog]')->assertNoJavaScriptErrors();
});

it('bounds the Since you last looked preview when four checks change state', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $this->artisan('heptapod:seed-demo', ['--scenario' => 'calm'])->assertSuccessful();
    $check = Check::query()->where('type', CheckKey::Uptime)->firstOrFail();
    Event::factory()->for($check)->count(4)->create(['from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Warning]);
    $this->actingAs(User::query()->where('email', DatabaseSeeder::DEMO_EMAIL)->sole());

    $page = visit('/')->assertSee('Since you last looked');
    expect($page->script('document.querySelector("main ol:not([data-slot])")?.children.length'))->toBe(3);
    $page->assertSee('And 1 more')->assertNoJavaScriptErrors();
});

it('labels a missing daily history instead of showing a ninety-day uptime figure', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $this->artisan('heptapod:seed-demo', ['--scenario' => 'calm'])->assertSuccessful();
    $this->actingAs(User::query()->where('email', DatabaseSeeder::DEMO_EMAIL)->sole());

    $page = visit('/');
    expect($page->script('new Promise(resolve => { const deadline = Date.now() + 8000; const check = () => { if (document.body.textContent?.includes("No daily history yet.")) resolve(true); else if (Date.now() >= deadline) resolve(false); else setTimeout(check, 50); }; check(); })'))->toBeTrue();
    $page->assertDontSee('100.00%')->assertNoJavaScriptErrors();
});

it('shows when an incident began rather than the latest check time on Today', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $check = Check::factory()->for(Site::factory()->create())->create(['latest_run_result' => CheckResult::Failed, 'latest_run_ended_at' => now()]);
    $incident = Incident::factory()->for($check)->create(['first_failure_at' => now()->subHours(5)]);
    $this->actingAs($owner);

    $page = visit('/')->assertSee('Since');
    expect($page->script('document.querySelector("[aria-label=\\"Needs attention\\"] time").dateTime'))
        ->toBe($incident->first_failure_at->toIso8601String());
    $page->assertMissing('main [data-slot="alert"]')->assertDontSee('Open incident')->assertNoJavaScriptErrors();
    expect($page->script('document.querySelector("[aria-label=\\"Needs attention\\"] a[href^=\\"/incidents/\\"]").getAttribute("href")'))
        ->toBe('/incidents/'.$incident->id);
    $page->click('[aria-label="Needs attention"] a[href="/incidents/'.$incident->id.'"]')
        ->assertPathIs('/incidents/'.$incident->id)->assertNoJavaScriptErrors();
});

it('shows the calendar date for activity before today in the user timezone', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $this->travelTo(CarbonImmutable::parse('2026-10-04T12:00:00Z'));
    $owner = User::factory()->create(['timezone' => 'UTC']);
    $owner->assignRole('Owner');
    $check = Check::factory()->create();
    Event::factory()->for($check)->create(['occurred_at' => CarbonImmutable::parse('2026-10-03T14:03:22Z'), 'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Warning]);
    $this->actingAs($owner);

    $page = visit('/activity?period=all')->assertSee('Oct 3');
    expect($page->script('document.querySelector("main time")?.dateTime'))->toBe('2026-10-03T14:03:22+00:00');
    $page->assertNoJavaScriptErrors();
});

it('does not claim paused sites are fine alongside healthy sites', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    DispatcherHeartbeat::factory()->create();
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $ok = Site::factory()->create();
    Check::factory()->for($ok)->create(['latest_run_result' => CheckResult::Succeeded, 'latest_run_ended_at' => now()]);
    $paused = Site::factory()->create(['paused_at' => now()]);
    Check::factory()->for($paused)->create(['latest_run_result' => CheckResult::Succeeded, 'latest_run_ended_at' => now()]);
    $this->actingAs($owner);

    visit('/')->assertSee('1 of 2 sites are OK.')->assertDontSee('All 2 sites are fine.')->assertNoJavaScriptErrors();
});

it('clears stale presentation selection without hiding authorized clients or marking Today as left', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $this->seed(DemoSeeder::class);
    $user = User::query()->where('email', DatabaseSeeder::DEMO_EMAIL)->sole();
    $this->actingAs($user);

    $lastSeen = $user->last_seen_at;
    $selected = Client::query()->where('slug', 'demo-client-01')->sole();
    $otherSite = Site::query()->where('friendly_name', 'Site 05')->sole();
    Event::factory()->for($otherSite->checks()->firstOrFail())->create(['occurred_at' => now(),
        'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Warning]);
    Route::get('/__browser/stale-scope', static function () use ($selected): Redirector|RedirectResponse {
        request()->session()->put('selected_client_id', $selected->id);

        return redirect('/');
    })->middleware('web');
    $page = visit('/__browser/stale-scope')->assertMissing('[aria-label="Client scope"]')
        ->assertSeeIn('[aria-label="Needs attention"]', 'Backup 2')
        ->assertSeeIn('[aria-label="Needs attention"]', 'Site 05');
    expect($user->refresh()->last_seen_at)->toEqual($lastSeen);
    traceTodayNavigation($page, 'nav a[href="/activity"]', '/activity');
    $page->assertPathIs('/activity')->assertSee('Site 05')->assertNoJavaScriptErrors();
});
