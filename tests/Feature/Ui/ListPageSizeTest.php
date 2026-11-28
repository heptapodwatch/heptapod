<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Http\ListPageSize;
use Heptapod\Models\Client;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-03T12:00:00Z'));
    $this->withoutVite();
});

/** The page size each list reports to its footer. */
function listPageSize(TestResponse $response, string $list): int
{
    $props = $response->inertiaProps();

    return match ($list) {
        'runs' => $props['per_page'],
        'maintenance' => $props['windows_pagination']['per_page'],
        'client_sites' => $props['sites_pagination']['per_page'],
        default => $props[$list.'_pagination']['per_page'],
    };
}

it('pages every major list by the chosen size, rejects other sizes and remembers the choice per list', function (string $list, string $path): void {
    $owner = User::factory()->owner()->create();
    $client = Client::factory()->create();
    Site::factory()->for($client)->create();
    $path = str_replace('{client}', (string) $client->id, $path);
    $separator = str_contains($path, '?') ? '&' : '?';

    expect(listPageSize($this->actingAs($owner)->get($path)->assertOk(), $list))->toBe(50);

    $this->get($path.$separator.'per_page=30')->assertSessionHasErrors('per_page');
    $this->get($path.$separator.'per_page=1000')->assertSessionHasErrors('per_page');

    $chosen = $this->get($path.$separator.'per_page=20')->assertOk()->assertCookie(ListPageSize::Cookie, json_encode([$list => 20]), false);
    expect(listPageSize($chosen, $list))->toBe(20);

    // The cookie answers the next visit without per_page, for this list only.
    $this->withUnencryptedCookie(ListPageSize::Cookie, json_encode([$list => 20]));
    expect(listPageSize($this->get($path)->assertOk(), $list))->toBe(20);
    $other = $list === 'sites' ? '/incidents' : '/sites';
    expect(listPageSize($this->get($other)->assertOk(), $other === '/sites' ? 'sites' : 'incidents'))->toBe(50);

    // A tampered cookie falls back to the default instead of failing the page.
    $this->withUnencryptedCookie(ListPageSize::Cookie, json_encode([$list => 7]));
    expect(listPageSize($this->get($path)->assertOk(), $list))->toBe(50);
})->with([
    'sites' => ['sites', '/sites'],
    'incidents' => ['incidents', '/incidents'],
    'clients' => ['clients', '/clients'],
    'client sites' => ['client_sites', '/clients/{client}'],
    'maintenance' => ['maintenance', '/maintenance'],
    'runs' => ['runs', '/runs'],
]);

it('limits each page to the chosen size and pages through the rest', function (): void {
    $owner = User::factory()->owner()->create();
    $client = Client::factory()->create();
    Site::factory()->count(12)->for($client)->create();

    $this->actingAs($owner)->get('/sites?per_page=10')->assertOk()
        ->assertInertia(fn ($page) => $page->where('sites_pagination.per_page', 10)->where('sites_pagination.last_page', 2)
            ->loadDeferredProps(['list'], fn ($list) => $list->has('sites', 10)));
    $this->get('/sites?per_page=10&page=2')->assertInertia(fn ($page) => $page
        ->loadDeferredProps(['list'], fn ($list) => $list->has('sites', 2)));
});

it('shows the last page for a page beyond the end, in page loads, deferred props and JSON alike', function (): void {
    config(['heptapod.health.browser_urls' => [], 'broadcasting.default' => 'log']);
    $owner = User::factory()->owner()->create();
    $client = Client::factory()->create();
    Site::factory()->count(12)->for($client)->create();
    $this->actingAs($owner);

    // Sites counts its total in the first render and reads its rows in a deferred partial request.
    $this->get('/sites?per_page=10&page=5')->assertOk()->assertInertia(fn ($page) => $page
        ->where('sites_pagination.current_page', 2)->where('sites_pagination.last_page', 2)
        ->loadDeferredProps(['list'], fn ($list) => $list->has('sites', 2)));
    // A client's sites page through their query; Previous points to the page before the one shown.
    $this->get('/clients/'.$client->id.'?per_page=10&page=3')->assertOk()->assertInertia(fn ($page) => $page
        ->has('sites', 2)->where('sites_pagination.current_page', 2)->where('sites_pagination.next_page_url', null)
        ->where('sites_pagination.previous_page_url', fn (string $url): bool => str_contains($url, 'page=1')));
    // An empty list has only its first page.
    $this->get('/incidents?page=3')->assertOk()->assertInertia(fn ($page) => $page
        ->where('incidents_pagination.current_page', 1)->where('incidents_pagination.last_page', 1));
    // An in-memory list behind a JSON refresh endpoint answers with its last page too, never a redirect.
    $this->getJson('/settings/system/data?system_issues_page=9')->assertOk()
        ->assertJsonPath('system_issues_pagination.current_page', 1);
});
