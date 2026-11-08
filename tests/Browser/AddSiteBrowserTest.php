<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Models\Client;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Support\Facades\Vite;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-26T12:00:00Z'));
});

/** Enters the address, waits for the probe to reveal Client and chooses the client: every section is then shown. */
function addSiteSections(string $url, Client $client): mixed
{
    return visit('/sites/create')->fill('#site-url', $url)
        ->click('#site-client')->click('role=option[name="'.$client->name.'"s]');
}

it('shows the effective expiry default only in the check settings sheet', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $client = Client::factory()->create();
    $this->actingAs($user);

    addSiteSections('https://defaults.example', $client)->resize(390, 844)
        ->assertSee('Defaults')
        ->assertDontSee('Default: 14')
        ->click('button[aria-label="Settings for Certificate"]')
        ->assertSee('Default: 14')
        ->assertNoJavaScriptErrors();
});

it('creates an HTTP site from recommended selections without HTTPS-only checks', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $client = Client::factory()->create();
    $this->actingAs($user);

    $page = addSiteSections('http://plain.example', $client)
        ->click('button[type="submit"]')->assertPathIsNot('/sites/create');
    $site = Site::query()->where('url', 'http://plain.example')->firstOrFail();
    $page->assertPathIs('/sites/'.$site->id)->assertNoJavaScriptErrors();
    $enabled = $site->checks()->where('enabled', true)->get();
    expect($enabled)->not->toBeEmpty();
    foreach ($enabled as $check) {
        expect(app(CheckRegistry::class)->get($check->type)->definition()->requiresHttps)->toBeFalse();
    }
});

it('persists schema-shaped headers, payload and both assertion lists from the add-site form', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $client = Client::factory()->create();
    $this->actingAs($user);

    $page = addSiteSections('https://schema.example', $client)
        ->click('button[aria-label="Settings for Uptime"]');
    foreach (['Headers', 'Request fields', 'Expected response headers', 'Response body rules'] as $label) {
        $page->click('fieldset:has(legend:text-is("'.$label.'")) button:has-text("Add pair")');
    }
    $page->fill('input[id*="uptime.http_client_headers-name-0"]', 'Authorization')
        ->fill('input[id*="uptime.http_client_headers-value-0"]', 'Bearer test-secret')
        ->fill('input[id*="uptime.payload-name-0"]', 'source')
        ->fill('input[id*="uptime.payload-value-0"]', 'web')
        ->fill('input[id*="uptime.expected_response_headers-name-0"]', 'X-Trace')
        ->fill('input[id*="uptime.expected_response_headers-value-0"]', 'yes')
        ->fill('input[id*="uptime.response_body_assertions-path-0"]', 'result.ok')
        ->fill('input[id*="uptime.response_body_assertions-value-0"]', 'true')
        ->click('role=radio[name="contains"s]')
        ->click('[role=combobox][aria-label="Operator 1"]')->click('role=option[name="equals"s]')
        ->click('button:text-is("Apply")')
        ->click('button[type="submit"]')->assertPathIsNot('/sites/create');
    $site = Site::query()->where('url', 'https://schema.example')->firstOrFail();
    $page->assertPathIs('/sites/'.$site->id)->assertNoJavaScriptErrors();
    $config = $site->checks()->where('type', CheckKey::Uptime)->firstOrFail()->executionConfig();
    expect($config['http_client_headers'])->toBe([['name' => 'Authorization', 'value' => 'Bearer test-secret']])
        ->and($config['payload'])->toBe([['name' => 'source', 'value' => 'web']])
        ->and($config['expected_response_headers'])->toBe([['name' => 'X-Trace', 'value' => 'yes', 'condition' => 'contains']])
        ->and($config['response_body_assertions'])->toBe([['path' => 'result.ok', 'value' => 'true', 'operator' => 'equals']]);
});

it('reopens the check settings sheet on its validation errors after submission', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $client = Client::factory()->create();
    $this->actingAs($user);

    $page = addSiteSections('https://new.example', $client)
        ->click('button[aria-label="Settings for Uptime"]')
        ->fill('input[name="uptime.timeout"]', '0')
        ->click('button:text-is("Apply")')
        ->click('button[type="submit"]')
        ->assertSee('The values.timeout field must be at least 1.')
        ->assertVisible('[role="dialog"] input[name="uptime.timeout"]');
    $page->assertNoJavaScriptErrors();
});
