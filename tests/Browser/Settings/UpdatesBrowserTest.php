<?php

declare(strict_types=1);

use Heptapod\Actions\MarkBackupRunning;
use Heptapod\Actions\RequestBackup;
use Heptapod\Models\User;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Vite;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\BackupWasSuccessful;

beforeEach(function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    // RunBackupNow names its connection explicitly; isolate that connection on the test database.
    config(['broadcasting.default' => 'null', 'heptapod.health.browser_urls' => [],
        'queue.connections.redis' => ['driver' => 'database', 'connection' => 'pgsql', 'table' => 'jobs',
            'queue' => 'reports', 'retry_after' => 1260, 'after_commit' => true]]);
});

it('requests a real queued backup, locks busy controls, refreshes terminal outcomes and reports rate limiting', function (string $locale): void {
    $owner = User::factory()->create(['locale' => $locale]);
    $owner->assignRole('Owner');
    $this->actingAs($owner);
    $button = $locale === 'de' ? 'Jetzt sichern' : 'Back up now';
    $refresh = $locale === 'de' ? 'Aktualisieren' : 'Refresh';
    $page = visit('/settings/updates')->assertEnabled($button);
    $page->script('window.__backupBusy = false; new MutationObserver(() => { const root = document.querySelector("[aria-busy=true]"); if (root && Array.from(root.querySelectorAll("button")).filter(button => !button.closest("summary")).every(button => button.disabled)) window.__backupBusy = true; }).observe(document.querySelector("main"), { attributes: true, subtree: true });');
    $page->click($button)->assertSee($locale === 'de' ? 'Sicherungsanfrage' : 'Backup request')
        ->assertSee($locale === 'de' ? 'In Warteschlange' : 'Queued')->assertDisabled($button);
    $id = DB::table('backup_runs')->sole()->id;
    expect(DB::table('backup_runs')->value('state'))->toBe('queued');
    expect(DB::table('jobs')->where('queue', 'reports')->count())->toBe(1);
    expect($page->script('window.__backupBusy'))->toBeTrue();
    app(MarkBackupRunning::class)($id);
    $page->click($refresh)->assertSee($locale === 'de' ? 'Läuft' : 'Running')->assertDisabled($button);
    Context::scope(static fn (): mixed => event(new BackupWasSuccessful('backups', 'Heptapod')), ['backup-run-id' => $id]);
    $page->click($refresh)->assertSee($locale === 'de' ? 'Abgeschlossen' : 'Complete')->assertEnabled($button);
    $page->click($button)->assertSee($locale === 'de' ? 'Zu viele Sicherungsanfragen' : 'Backup request rate-limited');
    expect(DB::table('backup_runs')->count())->toBe(1);
    event(new BackupHasFailed(new RuntimeException('private backup credential', 403), 'backups', 'Heptapod'));
    $page->click($refresh)->assertSee($locale === 'de' ? 'Letzte aufgezeichnete Sicherung fehlgeschlagen.' : 'Last recorded backup failed.')
        ->assertSee('HTTP 403')->assertDontSee('private backup credential');
    foreach ([1440, 1024, 390] as $width) {
        $page->resize($width, $width === 390 ? 844 : 1000);
        foreach (['light', 'dark'] as $theme) {
            $page->script('document.documentElement.classList.toggle("dark", '.($theme === 'dark' ? 'true' : 'false').'); document.documentElement.style.colorScheme = "'.$theme.'";');
            expect($page->script('document.documentElement.scrollWidth <= innerWidth'))->toBeTrue();
            $page->screenshot(filename: 'updates-failed-'.$locale.'-'.$width.'-'.$theme)->assertNoJavaScriptErrors();
        }
    }
})->with(['en', 'de']);

it('shows unknown observations without invented archives or recoverability in the locale and theme matrix', function (string $locale): void {
    config(['heptapod.version' => 'unknown', 'heptapod.updates.enabled_by_server' => false]);
    $owner = User::factory()->create(['locale' => $locale]);
    $owner->assignRole('Owner');
    $this->actingAs($owner);
    $page = visit('/settings/updates')->assertSee($locale === 'de' ? 'Keine Sicherungsläufe aufgezeichnet.' : 'No backup runs recorded.')
        ->assertSee($locale === 'de' ? 'Unbekannt' : 'Unknown')
        ->assertDisabled($locale === 'de' ? 'Release-Prüfung deaktivieren' : 'Disable release checks')
        ->assertDontSee('214 MB')->assertDontSee('/srv/heptapod');
    expect($page->script('document.querySelectorAll("main input, main select, main textarea").length'))->toBe(0);
    foreach ([1440, 1024, 390] as $width) {
        $page->resize($width, $width === 390 ? 844 : 1000);
        foreach (['light', 'dark'] as $theme) {
            $page->script('document.documentElement.classList.toggle("dark", '.($theme === 'dark' ? 'true' : 'false').'); document.documentElement.style.colorScheme = "'.$theme.'";');
            expect($page->script('document.documentElement.scrollWidth <= innerWidth'))->toBeTrue();
            $page->screenshot(filename: 'updates-unknown-'.$locale.'-'.$width.'-'.$theme)->assertNoJavaScriptErrors();
        }
    }
    $owner->syncRoles('Member');
    $page->click($locale === 'de' ? 'Aktualisieren' : 'Refresh')
        ->assertSee($locale === 'de' ? 'Updates und Sicherungen konnten nicht aktualisiert werden.' : 'Could not refresh updates and backups.');
    foreach ([1440, 1024, 390] as $width) {
        $page->resize($width, $width === 390 ? 844 : 1000);
        foreach (['light', 'dark'] as $theme) {
            $page->script('document.documentElement.classList.toggle("dark", '.($theme === 'dark' ? 'true' : 'false').'); document.documentElement.style.colorScheme = "'.$theme.'";');
            expect($page->script('document.documentElement.scrollWidth <= innerWidth'))->toBeTrue();
            $page->screenshot(filename: 'updates-error-'.$locale.'-'.$width.'-'.$theme)->assertNoJavaScriptErrors();
        }
    }
})->with(['en', 'de']);

it('preserves release observations on request errors and recovers without widening Admin permissions', function (): void {
    config(['heptapod.updates.enabled_by_server' => true]);
    $owner = User::factory()->create(['locale' => 'en']);
    $owner->assignRole('Owner');
    $this->actingAs($owner);
    $page = visit('/settings/profile')->click('Updates & backups')->assertPathIs('/settings/updates');
    $page->click('Disable release checks')->assertSee('Release checks disabled by Owner');
    expect(app(InstanceSettings::class)->refresh()->updates_enabled)->toBeFalse();
    $page->click('Enable release checks')->assertSee('No release check recorded');
    $owner->syncRoles('Admin');
    $page->click('Disable release checks')->assertSee('Could not change release checks.');
    expect(app(InstanceSettings::class)->refresh()->updates_enabled)->toBeTrue();
    $page->click('Back up now')->assertSee('Backup request outcome unknown.')->assertDisabled('Back up now');
    expect(DB::table('backup_runs')->count())->toBe(0);
    $owner->syncRoles('Member');
    $page->click('Refresh')->assertSee('Could not refresh updates and backups.')->assertSee('Previous observations are shown; refresh before requesting a backup.');
    foreach ([1440, 1024, 390] as $width) {
        $page->resize($width, $width === 390 ? 844 : 1000);
        foreach (['light', 'dark'] as $theme) {
            $page->script('document.documentElement.classList.toggle("dark", '.($theme === 'dark' ? 'true' : 'false').'); document.documentElement.style.colorScheme = "'.$theme.'";');
            expect($page->script('document.documentElement.scrollWidth <= innerWidth'))->toBeTrue();
            $page->screenshot(filename: 'updates-error-en-'.$width.'-'.$theme)->assertNoJavaScriptErrors();
        }
    }
    $owner->syncRoles('Owner');
    $page->click('Refresh')->assertDontSee('Could not refresh updates and backups.')->assertEnabled('Back up now')->assertNoJavaScriptErrors();
    $owner->syncRoles('Admin');
    $page->refresh()->assertSee('Only Owners can read backup history or request a backup.')->assertDontSee('Back up now')->assertDontSee('Enable release checks')->assertDontSee('Disable release checks')->assertNoJavaScriptErrors();
    $owner->syncRoles('Member');
    visit('/settings/profile')->assertDontSee('Updates & backups')->assertNoJavaScriptErrors();
});

it('separates cached release metadata from current availability', function (string $state, string $message): void {
    config(['heptapod.version' => 'v1.2.2', 'heptapod.updates.enabled_by_server' => true]);
    DB::table('update_checks')->insert(['id' => 1, 'status' => $state, 'http_status' => $state === 'unreachable' ? 404 : 200,
        'checked_at' => now(), 'last_success_at' => now()->subDay(), 'latest_version' => 'v1.2.3',
        'release_url' => config('heptapod.updates.release_page_url').'/tag/v1.2.3', 'published_at' => now()->subDays(2)]);
    $owner = User::factory()->create(['locale' => 'en']);
    $owner->assignRole('Owner');
    $this->actingAs($owner);
    $page = visit('/settings/updates')->assertSee($message)->assertSee('v1.2.3')->assertSee('Last successful release check')->assertNoJavaScriptErrors();
    if ($state !== 'reachable') {
        $page->assertDontSee('Update available');
    }
})->with([['reachable', 'Update available'], ['unreachable', 'Release source unavailable'], ['invalid_response', 'Release response invalid']]);

it('recovers from a concurrent backup request without creating a duplicate', function (): void {
    $owner = User::factory()->create(['locale' => 'en']);
    $owner->assignRole('Owner');
    $this->actingAs($owner);
    $page = visit('/settings/updates')->assertEnabled('Back up now');
    app(RequestBackup::class)($owner);
    $page->click('Back up now')->assertSee('A backup is already queued or running.')
        ->assertDisabled('Back up now')->click('Refresh')->assertSee('Queued')->assertDisabled('Back up now')->assertNoJavaScriptErrors();
    expect(DB::table('backup_runs')->count())->toBe(1);
    expect(DB::table('jobs')->where('queue', 'reports')->count())->toBe(1);
});

it('distinguishes an unreachable archive disk from an empty inventory', function (): void {
    config(['filesystems.disks.backups.root' => '/dev/null/updates-test']);
    $owner = User::factory()->create(['locale' => 'en']);
    $owner->assignRole('Owner');
    $this->actingAs($owner);
    visit('/settings/updates')->assertSee('Archive disk unreachable')->assertSee('Archive inventory unavailable.')
        ->assertDontSee('No archives found on the configured disk.')->assertNoJavaScriptErrors();
});
