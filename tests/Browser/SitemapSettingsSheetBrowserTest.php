<?php

declare(strict_types=1);

use Database\Seeders\DatabaseSeeder;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Models\Check;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Support\Facades\Vite;

it('keeps the full sitemap additional-delay label and Save inside the settled sheet and viewport', function (string $locale, int $width, int $height): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    config(['broadcasting.default' => 'null']);
    $owner = User::factory()->create(['email' => DatabaseSeeder::DEMO_EMAIL, 'locale' => $locale]);
    $owner->assignRole('Owner');
    $site = Site::factory()->create(['url' => 'http://fixtures.test', 'friendly_name' => 'Sitemap label fixture']);
    $schema = app(CheckRegistry::class)->get(CheckKey::Sitemap)->definition()->configSchema;
    $check = Check::factory()->for($site)->create(['type' => CheckKey::Sitemap, 'config' => $schema->validateConfig(['speed' => 'default'])]);
    $this->actingAs($owner);
    $label = $locale === 'de' ? 'Zusätzliche Verzögerung zwischen Anfragen' : 'Additional delay between requests';
    $edit = $locale === 'de' ? 'Bearbeiten' : 'Edit';

    $page = visit('/sites/'.$site->id.'/checks/'.$check->id)->resize($width, $height)
        ->click('button:text-is("'.$edit.'")')->assertSee($label);
    // The sheet slides in; inspect only after its root animation ends and bounds stabilize over two render frames.
    expect($page->script('new Promise(resolve => { const deadline = performance.now() + 5000; let previous = null; const check = () => { const sheet = document.querySelector("[role=dialog]"); if (sheet) { const rect = sheet.getBoundingClientRect(); const bounds = JSON.stringify([rect.left, rect.right, rect.top, rect.bottom]); const ended = sheet.getAnimations().every(animation => animation.playState !== "running"); if (ended && bounds === previous) { resolve(true); return; } previous = ended ? bounds : null; } if (performance.now() >= deadline) resolve(false); else requestAnimationFrame(check); }; requestAnimationFrame(check); })'))->toBeTrue();
    $bounds = $page->script('(() => { const sheet = document.querySelector("[role=dialog]"); const save = sheet.querySelector("button[type=submit]"); const label = [...sheet.querySelectorAll("label")].find(element => element.textContent.includes("delay") || element.textContent.includes("Verzögerung")); const text = document.createRange(); text.selectNodeContents(label); const rect = text.getBoundingClientRect(); const button = save.getBoundingClientRect(); const panel = sheet.getBoundingClientRect(); return { label: label.textContent, textInside: rect.left >= panel.left && rect.right <= panel.right && rect.right <= innerWidth, saveInside: button.left >= panel.left && button.right <= panel.right && button.bottom <= panel.bottom && button.left >= 0 && button.right <= innerWidth && button.top >= 0 && button.bottom <= innerHeight, overflow: document.documentElement.scrollWidth > innerWidth }; })()');

    expect($bounds['label'])->toBe($label)
        ->and($bounds['textInside'])->toBeTrue()
        ->and($bounds['saveInside'])->toBeTrue()
        ->and($bounds['overflow'])->toBeFalse();
    $page->assertNoJavaScriptErrors();
})->with([
    'en-desktop' => ['en', 1440, 1000],
    'en-tablet' => ['en', 1024, 900],
    'en-mobile' => ['en', 390, 844],
    'de-desktop' => ['de', 1440, 1000],
    'de-tablet' => ['de', 1024, 900],
    'de-mobile' => ['de', 390, 844],
]);
