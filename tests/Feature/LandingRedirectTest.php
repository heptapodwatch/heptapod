<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Models\User;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05T12:00:00Z'));
});

it('permanently redirects legacy landing URLs to sites', function (string $path): void {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)->get($path)->assertStatus(301)->assertRedirect('/sites');
})->with(['/', '/dashboard']);

it('keeps sites authentication and monitoring-role checks after the landing redirect', function (): void {
    $this->get('/sites')->assertRedirect('/login');
    $this->actingAs(User::factory()->create())->get('/sites')->assertForbidden();
});
