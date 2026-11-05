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

it('starts recommended check settings collapsed and shows the effective expiry default', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $user = User::factory()->create();
    $user->assignRole('Owner');
    Client::factory()->create();
    $this->actingAs($user);

    visit('/sites/create')->resize(390, 844)
        ->fill('#site-url', 'https://defaults.example')
        ->click('button[type="submit"]')->click('button[type="submit"]')
        ->assertSee('Using recommended defaults')
        ->assertDontSee('Default: 14')
        ->click('button:has-text("Settings") >> nth=0')
        ->assertSee('Default: 14')
        ->assertNoJavaScriptErrors();
});

it('creates an HTTP site from recommended selections without HTTPS-only checks', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $user = User::factory()->create();
    $user->assignRole('Owner');
    Client::factory()->create();
    $this->actingAs($user);

    $page = visit('/sites/create')->fill('#site-url', 'http://plain.example')
        ->click('button[type="submit"]')->click('button[type="submit"]')
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
    Client::factory()->create();
    $this->actingAs($user);

    $page = visit('/sites/create')->fill('#site-url', 'https://schema.example')
        ->click('button[type="submit"]')->click('button[type="submit"]')
        ->click('label[for="check-uptime"] >> xpath=../../.. >> button:text-is("Settings")');
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
        ->click('[role=combobox][aria-label="Condition 1"]')->click('role=option[name="contains"s]')
        ->click('[role=combobox][aria-label="Operator 1"]')->click('role=option[name="equals"s]')
        ->click('button[type="submit"]')->assertPathIsNot('/sites/create');
    $site = Site::query()->where('url', 'https://schema.example')->firstOrFail();
    $page->assertPathIs('/sites/'.$site->id)->assertNoJavaScriptErrors();
    $config = $site->checks()->where('type', CheckKey::Uptime)->firstOrFail()->executionConfig();
    expect($config['http_client_headers'])->toBe([['name' => 'Authorization', 'value' => 'Bearer test-secret']])
        ->and($config['payload'])->toBe([['name' => 'source', 'value' => 'web']])
        ->and($config['expected_response_headers'])->toBe([['name' => 'X-Trace', 'value' => 'yes', 'condition' => 'contains']])
        ->and($config['response_body_assertions'])->toBe([['path' => 'result.ok', 'value' => 'true', 'operator' => 'equals']]);
});

it('opens add-site settings on validation errors after submission', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $user = User::factory()->create();
    $user->assignRole('Owner');
    Client::factory()->create();
    $this->actingAs($user);

    $page = visit('/sites/create')->fill('#site-url', 'https://new.example')
        ->click('button[type="submit"]')->click('button[type="submit"]')
        ->click('label[for="check-uptime"] >> xpath=../../.. >> button:text-is("Settings")')
        ->fill('input[name="uptime.timeout"]', '0')
        ->click('label[for="check-uptime"] >> xpath=../../.. >> button:text-is("Settings")')
        ->click('button[type="submit"]')
        ->assertSee('The values.timeout field must be at least 1.');
    expect($page->script('document.querySelector("input[name=\\"uptime.timeout\\"]").closest("[data-slot=collapsible]").getAttribute("data-state")'))->toBe('open');
    $page->assertNoJavaScriptErrors();
});
