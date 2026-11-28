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
        'runs', 'maintenance' => $props['per_page'],
        default => $props['pagination']['per_page'],
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
        ->assertInertia(fn ($page) => $page->where('pagination.per_page', 10)->where('pagination.last_page', 2)
            ->loadDeferredProps(['list'], fn ($list) => $list->has('sites', 10)));
    $this->get('/sites?per_page=10&page=2')->assertInertia(fn ($page) => $page
        ->loadDeferredProps(['list'], fn ($list) => $list->has('sites', 2)));
});
