<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Models\User;
use Illuminate\Support\Facades\Vite;

it('keeps the unsaved token draft when returning to the tab refreshes shell health', function (string $locale, string $createLabel, string $presetLabel, int $width): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-20T12:00:00Z'));
    Vite::useHotFile(storage_path('framework/testing.hot'));
    config(['broadcasting.default' => 'null']);
    $user = User::factory()->create(['locale' => $locale, 'timezone' => 'UTC']);
    $user->assignRole('Owner');
    $this->actingAs($user);
    $page = visit('/settings/api-tokens')->resize($width, 1000)->click($createLabel);
    $page->fill('token-name', 'Unsaved agent draft')->click('#token-preset')
        ->click('[role="option"]:has-text("agent-read")')->fill('token-expiry', '2030-12-21T13:30');

    // Replay the browser's tab-return event through the real live-reload hook and HTTP response.
    $refreshed = $page->script('new Promise(resolve => {
        const finished = event => {
            if (!event.detail.visit.async || !event.detail.visit.only.includes("overallHealth")) return;
            document.removeEventListener("inertia:finish", finished);
            resolve(true);
        };
        document.addEventListener("inertia:finish", finished);
        document.dispatchEvent(new Event("visibilitychange"));
    })');

    expect($refreshed)->toBeTrue();
    $page->assertValue('token-name', 'Unsaved agent draft')->assertSee($presetLabel)
        ->assertValue('token-expiry', '2030-12-21T13:30')->assertNoJavaScriptErrors();
    expect($user->tokens()->count())->toBe(0);
})->with([
    'English desktop' => ['en', 'Create token', 'Read for coding agents', 1440],
    'English mobile' => ['en', 'Create token', 'Read for coding agents', 390],
    'German desktop' => ['de', 'Token erstellen', 'Lesen für KI-Agenten', 1440],
    'German mobile' => ['de', 'Token erstellen', 'Lesen für KI-Agenten', 390],
]);
