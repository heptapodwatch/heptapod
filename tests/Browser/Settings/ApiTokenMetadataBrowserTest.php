<?php

declare(strict_types=1);

use Heptapod\Enums\ClientScope;
use Heptapod\Models\Client;
use Heptapod\Models\User;
use Illuminate\Support\Facades\Vite;

it('shows expired unavailable and never-used metadata honestly in German', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    config(['broadcasting.default' => 'null']);
    $user = User::factory()->create(['locale' => 'de', 'timezone' => 'Europe/Berlin', 'client_scope' => ClientScope::Restricted]);
    $user->assignRole('Member');
    $lost = Client::factory()->create(['name' => 'Lost private client']);
    $user->createToken('Expired constrained token', ['monitors:read'])->accessToken->forceFill(['client_id' => $lost->id, 'expires_at' => now()->subMinute()])->save();
    $user->createToken('No expiry token', ['checks:read']);
    $this->actingAs($user);
    $page = visit('/settings/api-tokens')->resize(1440, 900)->assertSee('API-Tokens')->assertSee('Abgelaufen')
        ->assertSee('Kunde nicht verfügbar — kein Zugriff')->assertDontSee('Lost private client')
        ->assertSee('Noch nie verwendet')->assertSee('Kein Ablaufdatum')->assertSee('Europe/Berlin')
        ->assertNoJavaScriptErrors();
    $page->script('document.documentElement.classList.add("dark"); document.documentElement.style.colorScheme = "dark";');
    $page->screenshot(filename: 'api-tokens-fixture-de-1440-dark');
    $page->resize(390, 844)->assertSee('Kunde nicht verfügbar — kein Zugriff')->assertSee('Abgelaufen');
    $page->script('document.documentElement.classList.remove("dark"); document.documentElement.style.colorScheme = "light";');
    $page->screenshot(filename: 'api-tokens-fixture-de-390-light')->assertNoJavaScriptErrors();
});
