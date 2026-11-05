<?php

use Carbon\CarbonImmutable;
use Heptapod\Models\Check;
use Heptapod\Models\Incident;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Queries\SiteDetailReader;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-03T12:00:00Z'));
});

it('reads a scoped site header without hydrating list histories, tags or check strips', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $site = Site::factory()->create(['friendly_name' => 'Header site']);
    Check::factory()->for($site)->create();
    $this->actingAs($user);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $header = app(SiteDetailReader::class)->header($user, $site->id);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($header->name)->toBe('Header site')
        ->and($header->id)->toBe($site->id)
        ->and(count($queries))->toBeLessThanOrEqual(2)
        ->and(strtolower(implode(' ', array_column($queries, 'query'))))->not->toContain('check_runs', 'taggables', 'rollup');
});

it('shares a site identity between list rows and detail headers without exposing credentials', function (): void {
    $this->withoutVite();
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $site = Site::factory()->create(['crawler_headers' => [['name' => 'Authorization', 'value' => 'header-private-token']]]);
    $check = Check::factory()->for($site)->create();
    $incident = Incident::factory()->for($check)->create();
    $this->actingAs($user);
    $row = $this->get('/sites', [
        'X-Inertia-Partial-Component' => 'sites/index',
        'X-Inertia-Partial-Data' => 'sites',
    ])->assertOk()->inertiaProps()['sites'][0];
    foreach (['/sites/'.$site->id, '/sites/'.$site->id.'/checks/'.$check->id, '/incidents/'.$incident->id] as $url) {
        $props = $this->get($url)->assertOk()->inertiaProps();
        expect($props['header'])->toBe($row['identity'])
            ->and($props['site']['identity'])->toBe($row['identity'])
            ->and(json_encode($props, JSON_THROW_ON_ERROR))->not->toContain('header-private-token');
    }
});
