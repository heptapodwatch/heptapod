<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Models\User;
use Illuminate\Support\Facades\Vite;

it('offers the documented agent-read preset and issues only its read abilities', function (string $locale, string $createLabel, string $presetLabel): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-20T12:00:00Z'));
    Vite::useHotFile(storage_path('framework/testing.hot'));
    config(['broadcasting.default' => 'null']);
    $user = User::factory()->create(['locale' => $locale, 'timezone' => 'UTC']);
    $user->assignRole('Owner');
    $this->actingAs($user);

    $page = visit('/settings/api-tokens')->resize(1440, 1000)->click($createLabel);
    $page->click('#token-preset')->click('[role="option"]:has-text("agent-read")')->assertSee($presetLabel)
        ->assertSee('activity:read')->assertSee('upcoming:read')->assertDontSee('maintenance:read');
    $page->fill('token-name', 'Agent reader');
    $page->script('const style = document.createElement("style"); style.textContent = "#one-time-secret { opacity: 0 !important; }"; document.head.append(style);');
    $page->keys('token-name', 'Enter')->assertPresent('#one-time-secret')->assertNoJavaScriptErrors();

    expect($user->tokens()->sole()->abilities)->toBe([
        'identity:read', 'monitors:read', 'checks:read', 'incidents:read', 'activity:read', 'upcoming:read',
    ]);
})->with([
    'English agent preset' => ['en', 'Create token', 'Read for coding agents'],
    'German agent preset' => ['de', 'Token erstellen', 'Lesen für KI-Agenten'],
]);
