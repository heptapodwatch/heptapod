<?php

declare(strict_types=1);

use Heptapod\Models\Client;
use Heptapod\Models\User;
use Illuminate\Support\Facades\Vite;

it('issues exactly the edited starting point with explicit client access and the 30-day default expiry', function (string $locale, string $createLabel, string $deployLabel, string $oneClient, string $stored, int $width): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    config(['broadcasting.default' => 'null']);
    $user = User::factory()->create(['locale' => $locale, 'timezone' => 'UTC']);
    $user->assignRole('Owner');
    $client = Client::factory()->create(['name' => 'Deploy client']);
    $this->actingAs($user);

    // The browser computes the default expiry from its own clock, which the server cannot freeze.
    $before = now()->addDays(30)->subMinute();
    $page = visit('/settings/api-tokens')->resize($width, 1000)->click($createLabel);
    $page->fill('token-name', 'CI deploy')->click($deployLabel)
        // Drop the maintenance lookup, add incident reads: the grant is the checklist.
        ->click('[role="checkbox"][value="maintenance:read"]')->click('[role="checkbox"][value="incidents:read"]');
    $page->keys('token-name', 'Enter')->assertPresent('#token-reach[aria-invalid="true"]');
    expect($user->tokens()->count())->toBe(0);
    $page->click($oneClient)->click('#token-client')->click('[role="option"]:has-text("Deploy client")');
    $page->script('const style = document.createElement("style"); style.textContent = "#one-time-secret { opacity: 0 !important; }"; document.head.append(style);');
    try {
        $page->keys('token-name', 'Enter')->assertPresent('#one-time-secret')->assertNoJavaScriptErrors();
        $page->click($stored);
        expect($page->script('document.querySelector("#one-time-secret") === null'))->toBeTrue();
    } finally {
        $page->script('document.querySelector("#one-time-secret")?.remove();');
    }

    $token = $user->tokens()->sole();
    expect($token->abilities)->toBe(['monitors:read', 'checks:read', 'incidents:read', 'maintenance:write', 'checks:run'])
        ->and($token->getAttribute('client_id'))->toBe($client->id)
        ->and($token->expires_at?->between($before, now()->addDays(30)->addMinute()))->toBeTrue();
})->with([
    'English desktop' => ['en', 'Create token', 'Deploy automation', 'One client', 'I have stored it', 1440],
    'German mobile' => ['de', 'Token erstellen', 'Deploy-Automatisierung', 'Ein Kunde', 'Ich habe es gespeichert', 390],
]);
