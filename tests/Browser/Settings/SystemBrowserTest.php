<?php

declare(strict_types=1);

use Heptapod\Models\DispatcherHeartbeat;
use Heptapod\Models\User;
use Heptapod\SystemHealth\PublishWorkerMemory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Vite;

beforeEach(function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    config(['broadcasting.default' => 'null', 'heptapod.health.browser_urls' => ['lighthouse' => null, 'gotenberg' => null]]);
});

it('lets Admins navigate to read-only system health and refresh real observations without advertising General', function (): void {
    $admin = User::factory()->create(['locale' => 'en']);
    $admin->assignRole('Admin');
    $this->actingAs($admin);
    $heartbeat = DispatcherHeartbeat::factory()->create(['last_seen_at' => now()->subSeconds(300)]);
    $page = visit('/settings/profile')->assertDontSee('General')->click('System health')->assertPathIs('/settings/system')
        ->assertSee('Stale heartbeat at sample time')->assertSee('No running Horizon observed');
    expect($page->script('document.querySelectorAll("main input, main select, main textarea, main form").length'))->toBe(0);
    $page->script('window.__systemPendingLocked = false; new MutationObserver(() => { const page = document.querySelector("[aria-busy=true]"); if (page && Array.from(page.querySelectorAll("button")).some(button => button.disabled)) window.__systemPendingLocked = true; }).observe(document.querySelector("main"), { attributes: true, subtree: true });');
    $heartbeat->update(['last_seen_at' => now()]);
    $page->click('Refresh')->assertSee('Recent heartbeat at sample time')->assertEnabled('Refresh')->assertNoJavaScriptErrors();
    expect($page->script('window.__systemPendingLocked'))->toBeTrue();
    expect(DispatcherHeartbeat::count())->toBe(1);
    expect($heartbeat->fresh()->last_seen_at->diffInSeconds(now()))->toBeLessThan(10);
    $page->refresh()->assertSee('Recent heartbeat at sample time')->assertNoJavaScriptErrors();
});

it('does not advertise system health to Members', function (): void {
    $member = User::factory()->create();
    $member->assignRole('Member');
    $this->actingAs($member);
    visit('/settings/profile')->assertDontSee('System health')->assertDontSee('General')->assertNoJavaScriptErrors();
});

it('shows unknown telemetry and stale snapshots without inventing health in the responsive locale and theme matrix', function (string $locale, int $width, string $theme): void {
    $owner = User::factory()->create(['locale' => $locale]);
    $owner->assignRole('Owner');
    $this->actingAs($owner);
    Cache::store('redis')->forget(config('heptapod.health.scheduler_cache_key'));
    Cache::store('redis')->forget(PublishWorkerMemory::Key);
    $suffix = $locale.'-'.$width.'-'.$theme;
    $page = visit('/settings/system')->resize($width, $width === 390 ? 844 : 1000)
        ->assertSee($locale === 'de' ? 'Kein Lebenszeichen aufgezeichnet' : 'No heartbeat recorded')
        ->assertSee($locale === 'de' ? 'Unbekannt — keine aktuellen Beobachtungen der Hintergrundprozesse' : 'Unknown — no recent worker observations')
        ->assertSee($locale === 'de' ? 'Nicht eingerichtet' : 'Not configured')
        ->assertDontSee('Flowing')->assertDontSee('Copy command');
    $page->script('document.documentElement.classList.toggle("dark", '.($theme === 'dark' ? 'true' : 'false').'); document.documentElement.style.colorScheme = "'.$theme.'";');
    expect($page->script('document.documentElement.scrollWidth <= innerWidth'))->toBeTrue();
    expect($page->script('document.querySelectorAll("main input, main select, main textarea, main form").length'))->toBe(0);
    $page->screenshot(filename: 'system-unknown-'.$suffix);
    $page->script('window.__systemRealNow = Date.now; const sampled = Date.now(); Date.now = () => sampled + 70000;');
    $page->assertSee($locale === 'de' ? 'Momentaufnahme älter als eine Minute.' : 'Snapshot older than one minute.')
        ->screenshot(filename: 'system-stale-'.$suffix)->assertNoJavaScriptErrors();
    $page->script('Date.now = window.__systemRealNow;');
    $page->click($locale === 'de' ? 'Aktualisieren' : 'Refresh')
        ->assertDontSee($locale === 'de' ? 'Momentaufnahme älter als eine Minute.' : 'Snapshot older than one minute.')
        ->assertNoJavaScriptErrors();
})->with(['en', 'de'])->with([1440, 1024, 390])->with(['light', 'dark']);

it('marks a failed refresh unknown, preserves the prior snapshot and recovers through the real endpoint', function (string $locale): void {
    $owner = User::factory()->create(['locale' => $locale]);
    $owner->assignRole('Owner');
    $this->actingAs($owner);
    $heartbeat = DispatcherHeartbeat::factory()->create(['last_seen_at' => now()->subSeconds(300)]);
    $original = $heartbeat->last_seen_at->toIso8601String();
    $page = visit('/settings/system')->assertScript('typeof history.state?.page?.props?.upcomingCount === "number"');
    $owner->syncRoles('Member');
    $refresh = $locale === 'de' ? 'Aktualisieren' : 'Refresh';
    $error = $locale === 'de' ? 'Systemzustand konnte nicht aktualisiert werden.' : 'Could not refresh system health.';
    $page->click($refresh)->assertSee($error)->assertSee($locale === 'de' ? 'Die vorherige Momentaufnahme wird angezeigt; aktualisieren Sie erneut.' : 'The previous snapshot is shown; try Refresh again.');
    expect($heartbeat->fresh()->last_seen_at->toIso8601String())->toBe($original);
    foreach ([1440, 1024, 390] as $width) {
        $page->resize($width, $width === 390 ? 844 : 1000);
        foreach (['light', 'dark'] as $theme) {
            $page->script('document.documentElement.classList.toggle("dark", '.($theme === 'dark' ? 'true' : 'false').'); document.documentElement.style.colorScheme = "'.$theme.'";');
            expect($page->script('document.documentElement.scrollWidth <= innerWidth'))->toBeTrue();
            $page->screenshot(filename: 'system-error-'.$locale.'-'.$width.'-'.$theme)->assertNoJavaScriptErrors();
        }
    }
    $owner->syncRoles('Owner');
    $heartbeat->update(['last_seen_at' => now()]);
    $page->click($refresh)->assertDontSee($error)->assertSee($locale === 'de' ? 'Aktuelles Lebenszeichen zum Messzeitpunkt' : 'Recent heartbeat at sample time')->assertNoJavaScriptErrors();
})->with(['en', 'de']);
