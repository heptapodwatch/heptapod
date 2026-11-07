<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\Http\FetchRequest;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Models\Check;
use Heptapod\Models\Client;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Testing\FakeOutboundHttp;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-26T12:00:00Z'));
    config(['heptapod.browser.enabled' => false]);
});

it('rejects unavailable checks at site creation without writing a site or check', function (array $attributes, string $field): void {
    $owner = User::factory()->owner()->create();
    $client = Client::factory()->create();
    $fake = new FakeOutboundHttp;
    $fake->respondUsing(static fn (FetchRequest $request): FetchResult => new FetchResult(200, effectiveUrl: $request->url));
    app()->instance(OutboundHttp::class, $fake);
    $sites = Site::query()->count();
    $checks = Check::query()->count();

    $response = $this->actingAs($owner)->postJson('/sites', [
        'url' => 'https://example.test/', 'client_id' => $client->id, ...$attributes,
    ])->assertUnprocessable()->assertJsonValidationErrors($field);
    expect($response->json('errors')[$field])->toBe([__('lighthouse.browser_unavailable')]);

    expect(Site::query()->count())->toBe($sites);
    expect(Check::query()->count())->toBe($checks);
})->with([
    'explicit selection' => [['checks' => ['uptime', 'lighthouse']], 'checks.1'],
    'disabled settings-only creation' => [['checks' => ['uptime'], 'lighthouse_check_settings' => []], 'checks.lighthouse.type'],
]);

it('refuses re-enabling an unavailable historical check without changing it', function (): void {
    $owner = User::factory()->owner()->create();
    $fake = new FakeOutboundHttp;
    $fake->respondUsing(static fn (FetchRequest $request): FetchResult => new FetchResult(200, effectiveUrl: $request->url));
    app()->instance(OutboundHttp::class, $fake);
    $check = Check::factory()->create(['type' => CheckKey::Lighthouse, 'enabled' => false]);
    $this->actingAs($owner)->postJson('/sites/'.$check->site_id.'/checks/'.$check->id.'/enable')
        ->assertUnprocessable()->assertJsonValidationErrors('type')
        ->assertJsonPath('errors.type.0', __('lighthouse.browser_unavailable'));
    expect($check->refresh()->enabled)->toBeFalse();
});

it('excludes unavailable checks from the wizard, probe recommendations and default creation', function (): void {
    $owner = User::factory()->owner()->create();
    $client = Client::factory()->create();
    $fake = new FakeOutboundHttp;
    $fake->respondUsing(static fn (FetchRequest $request): FetchResult => new FetchResult(
        200, body: '<html><body>Shop</body></html>', headers: ['Content-Type' => ['text/html']], effectiveUrl: $request->url,
    ));
    app()->instance(OutboundHttp::class, $fake);

    $this->actingAs($owner)->get('/sites/create')->assertOk()->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->where('checks', function (Collection $checks): bool {
            $lighthouse = $checks->firstWhere('key', 'lighthouse');

            return ($lighthouse['unavailable_reason'] ?? null) === 'lighthouse.browser_unavailable' && $lighthouse['recommended'] === false;
        }));
    $recommendations = $this->postJson('/sites/probe', ['url' => 'https://example.test/'])->assertOk()->json('recommended_checks');
    expect($recommendations)->toContain('uptime')->not->toContain('lighthouse');
    $this->post('/sites', ['url' => 'https://example.test/', 'client_id' => $client->id])->assertRedirect()->assertSessionHasNoErrors();

    $site = Site::query()->where('url', 'https://example.test')->firstOrFail();
    expect($site->checks()->where('type', 'uptime')->exists())->toBeTrue();
    expect($site->checks()->where('type', 'lighthouse')->exists())->toBeFalse();
    expect($fake->requests)->toHaveCount(4);
});

it('recommends browser checks with their actual schedule when browser services are enabled', function (): void {
    config(['heptapod.browser.enabled' => true]);
    $owner = User::factory()->owner()->create();
    $fake = new FakeOutboundHttp;
    $fake->respondUsing(static fn (FetchRequest $request): FetchResult => new FetchResult(
        200, body: '<html><body>Shop</body></html>', headers: ['Content-Type' => ['text/html']], effectiveUrl: $request->url,
    ));
    app()->instance(OutboundHttp::class, $fake);

    $this->actingAs($owner)->get('/sites/create')->assertOk()->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->where('checks', function (Collection $checks): bool {
            $lighthouse = $checks->firstWhere('key', 'lighthouse');
            $uptime = $checks->firstWhere('key', 'uptime');

            return $lighthouse['unavailable_reason'] === null
                && $lighthouse['interval_seconds'] === 86400 && $uptime['interval_seconds'] === 60;
        }));
    $recommendations = $this->postJson('/sites/probe', ['url' => 'https://example.test/'])->assertOk()->json('recommended_checks');
    expect($recommendations)->toContain('lighthouse');
});
