<?php

declare(strict_types=1);

use Heptapod\Models\User;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Support\Facades\Vite;

beforeEach(function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    config(['broadcasting.default' => 'null', 'heptapod.health.browser_urls' => []]);
});

it('discards instance drafts and saves the canonical name across reloads with configuration read-only', function (): void {
    $owner = User::factory()->create(['locale' => 'en']);
    $owner->assignRole('Owner');
    $this->actingAs($owner);
    $original = app(InstanceSettings::class)->name;

    $page = visit('/settings/profile')->click('General')->assertPathIs('/settings/instance')
        ->assertSee('Backup retention')->assertDisabled('button[type="submit"]');
    expect($page->script('document.querySelectorAll("main input, main select, main textarea").length'))->toBe(1);
    $page->fill('name', 'Discard draft')->assertSee('Unsaved changes')->click('Discard')->assertValue('name', $original);
    expect(app(InstanceSettings::class)->name)->toBe($original);
    $page->script('window.__instancePendingLocked = false; new MutationObserver(() => { const form = document.querySelector("form"); if (form.getAttribute("aria-busy") === "true" && document.querySelector("#instance-name").disabled && document.querySelector("button[type=submit]").disabled) window.__instancePendingLocked = true; }).observe(document.querySelector("form"), { attributes: true, subtree: true });');
    $page->fill('name', '  Saved Instance  ')->click('Save')->assertSee('Instance settings saved.')
        ->assertValue('name', 'Saved Instance')->assertDisabled('button[type="submit"]');
    expect(app(InstanceSettings::class)->name)->toBe('Saved Instance');
    expect($page->script('window.__instancePendingLocked'))->toBeTrue();
    $page->fill('name', 'Second draft')->click('Discard')->assertValue('name', 'Saved Instance')
        ->refresh()->assertValue('name', 'Saved Instance')->assertNoJavaScriptErrors();
});

it('retains invalid instance drafts, focuses the enabled field and clears errors on discard', function (string $locale, string $save, string $discard, string $error): void {
    $owner = User::factory()->create(['locale' => $locale]);
    $owner->assignRole('Owner');
    $this->actingAs($owner);
    $original = app(InstanceSettings::class)->name;

    $page = visit('/settings/instance')->fill('name', str_repeat('x', 121))->click($save)
        ->assertSee($error)->assertValue('name', str_repeat('x', 121))->assertEnabled('#instance-name');
    expect($page->script('new Promise(resolve => { const deadline = Date.now() + 2000; const check = () => { if (document.activeElement.id === "instance-name" && !document.activeElement.disabled) resolve(true); else if (Date.now() >= deadline) resolve(false); else setTimeout(check, 20); }; check(); })'))->toBeTrue();
    expect(app(InstanceSettings::class)->name)->toBe($original);
    $page->click($discard)->assertValue('name', $original)->assertDontSee($error)->assertDisabled('button[type="submit"]')
        ->assertNoJavaScriptErrors();
})->with([
    'English' => ['en', 'Save', 'Discard', 'The name field must not be greater than 120 characters.'],
    'German' => ['de', 'Speichern', 'Verwerfen', 'Name darf maximal 120 Zeichen haben.'],
]);

it('does not advertise instance navigation to a non-owner', function (): void {
    $member = User::factory()->create();
    $member->assignRole('Member');
    $this->actingAs($member);

    visit('/settings/profile')->assertDontSee('General')->assertNoJavaScriptErrors();
});

it('rejects an empty instance name from the browser without changing identity', function (): void {
    $owner = User::factory()->create(['locale' => 'en']);
    $owner->assignRole('Owner');
    $this->actingAs($owner);
    $original = app(InstanceSettings::class)->name;

    visit('/settings/instance')->fill('name', '')->click('Save')
        ->assertSee('The name field is required.')->assertValue('name', '')
        ->click('Discard')->assertValue('name', $original)->assertNoJavaScriptErrors();
    expect(app(InstanceSettings::class)->name)->toBe($original);
});

it('keeps read-only configuration and instance drafts usable in the responsive locale and theme matrix', function (string $locale, int $width, string $theme): void {
    config(['mail.mailers.smtp.host' => 'smtp.example.test', 'mail.mailers.smtp.username' => 'private-user',
        'mail.mailers.smtp.password' => 'private-password', 'instance.heartbeat_url' => 'https://example.test/private-heartbeat']);
    $owner = User::factory()->create(['locale' => $locale]);
    $owner->assignRole('Owner');
    $this->actingAs($owner);
    $original = app(InstanceSettings::class)->name;
    $suffix = $locale.'-'.$width.'-'.$theme;

    $page = visit('/settings/instance')->resize($width, $width === 390 ? 844 : 1000)
        ->assertSee($locale === 'de' ? 'Aufbewahrung der Sicherungen' : 'Backup retention')
        ->assertSee('smtp.example.test')->assertDontSee('private-user')->assertDontSee('private-password')->assertDontSee('private-heartbeat');
    $page->script('document.documentElement.classList.toggle("dark", '.($theme === 'dark' ? 'true' : 'false').'); document.documentElement.style.colorScheme = "'.$theme.'";');
    expect($page->script('document.documentElement.scrollWidth <= innerWidth'))->toBeTrue();
    expect($page->script('document.querySelectorAll("form input, form select, form textarea").length'))->toBe(1);
    $page->screenshot(filename: 'instance-populated-'.$suffix);
    $page->fill('name', 'Matrix draft')->assertSee($locale === 'de' ? 'Ungespeicherte Änderungen' : 'Unsaved changes')
        ->assertEnabled('button[type="submit"]')->screenshot(filename: 'instance-draft-'.$suffix)
        ->click($locale === 'de' ? 'Verwerfen' : 'Discard')->assertValue('name', $original)->assertDisabled('button[type="submit"]')
        ->assertNoJavaScriptErrors();
    $page->fill('name', str_repeat('x', 121))->click($locale === 'de' ? 'Speichern' : 'Save')
        ->assertAttribute('#instance-name', 'aria-invalid', 'true')
        ->assertSee($locale === 'de' ? 'Name darf maximal 120 Zeichen haben.' : 'The name field must not be greater than 120 characters.');
    expect($page->script('document.documentElement.scrollWidth <= innerWidth'))->toBeTrue();
    $page->screenshot(filename: 'instance-error-'.$suffix)->assertNoJavaScriptErrors();
})->with(['en', 'de'])->with([1440, 1024, 390])->with(['light', 'dark']);
