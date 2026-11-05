<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Models\Check;
use Heptapod\Models\Incident;
use Heptapod\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-29T12:00:00Z'));
    $this->withoutVite();
});

it('lists only open incidents when the URL names no status, and resolved or all on request', function (): void {
    $owner = User::factory()->owner()->create();
    $check = Check::factory()->create();
    $open = Incident::factory()->for($check)->create(['first_failure_at' => now()->subHour(), 'opened_at' => now()->subHour()]);
    $resolved = Incident::factory()->for($check)->create(['first_failure_at' => now()->subHours(3), 'opened_at' => now()->subHours(3),
        'resolved_at' => now()->subHours(2)]);
    $this->actingAs($owner);

    $this->get('/incidents')->assertInertia(fn (Assert $page): Assert => $page
        ->where('filters.status', 'open')->has('incidents', 1)->where('incidents.0.id', $open->id));
    // A bookmarked explicit Open URL shows the same list as the default.
    $this->get('/incidents?filter[status]=open')->assertInertia(fn (Assert $page): Assert => $page
        ->where('filters.status', 'open')->has('incidents', 1)->where('incidents.0.id', $open->id));
    $this->get('/incidents?filter[status]=resolved')->assertInertia(fn (Assert $page): Assert => $page
        ->where('filters.status', 'resolved')->has('incidents', 1)->where('incidents.0.id', $resolved->id));
    $this->get('/incidents?filter[status]=all')->assertInertia(fn (Assert $page): Assert => $page
        ->where('filters.status', 'all')->has('incidents', 2));

    // The export follows the same default as the list.
    $exported = static fn (string $query): array => array_slice(array_map(str_getcsv(...), explode("\n", trim(test()->get('/incidents/export'.$query)->streamedContent()))), 1);
    expect(array_column($exported(''), 0))->toBe([(string) $open->id])
        ->and(array_column($exported('?filter[status]=all'), 0))->toBe([(string) $open->id, (string) $resolved->id]);
});
