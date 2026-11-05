<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Enums\ClientScope;
use Heptapod\Models\Check;
use Heptapod\Models\Client;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-04T10:00:00Z'));
    $this->withoutVite();
});

/** @return array<string, mixed> */
function siteSettingsProps(Site $site): array
{
    return test()->get('/sites/'.$site->id.'/settings')->assertOk()->inertiaProps();
}

it('lists the settings sheet contract of every check in site settings with the check tab masking', function (): void {
    $owner = User::factory()->owner()->create();
    $site = Site::factory()->create();
    $secret = 'site-settings-real-secret';
    $health = Check::factory()->for($site)->create(['type' => CheckKey::ApplicationHealth, 'interval_seconds' => 300]);
    $health->forceFill(['config_secrets' => ['secret' => $secret]])->save();
    $sitemapSchema = app(CheckRegistry::class)->get(CheckKey::Sitemap)->definition()->configSchema;
    $sitemap = Check::factory()->for($site)->create(['type' => CheckKey::Sitemap, 'enabled' => false, 'interval_seconds' => 3600,
        'config' => $sitemapSchema->validateConfig(['urls_per_run' => '100'])]);

    $this->actingAs($owner);
    $props = siteSettingsProps($site);
    $tab = $this->get('/sites/'.$site->id.'/checks/'.$health->id)->assertOk()->inertiaProps();

    expect(json_encode($props, JSON_THROW_ON_ERROR))->not->toContain($secret)
        ->and(collect($props['check_settings'])->pluck('check_id')->sort()->values()->all())->toBe([$health->id, $sitemap->id]);
    $byKey = collect($props['check_settings'])->keyBy('check_key');
    expect($byKey['application_health'])->toBe($tab['check_settings'])
        ->and($byKey['application_health']['settings']['secret'])->toBe('********')
        ->and($byKey['application_health'])->toMatchArray(['site_id' => $site->id, 'enabled' => true, 'interval_seconds' => 300, 'can_update' => true])
        ->and($byKey['sitemap'])->toMatchArray(['enabled' => false, 'interval_seconds' => 3600, 'label_key' => 'checks.sitemap.label'])
        ->and($byKey['sitemap']['settings']['urls_per_run'])->toBe('100')
        ->and($byKey['sitemap']['config_schema']['fields'])->toHaveKeys(['path', 'speed', 'urls_per_run']);
});

it('gives a member no configure data: site settings refuse them and the check tab marks the check read-only', function (): void {
    $client = Client::factory()->create();
    $member = User::factory()->member()->create(['client_scope' => ClientScope::Restricted]);
    $member->clients()->attach($client);
    $site = Site::factory()->for($client)->create();
    $check = Check::factory()->for($site)->create();

    $this->actingAs($member)->get('/sites/'.$site->id.'/settings')->assertForbidden();
    $tab = $this->get('/sites/'.$site->id.'/checks/'.$check->id)->assertOk()->inertiaProps();
    expect($tab['check_settings']['can_update'])->toBeFalse()
        ->and($tab['can_run'])->toBeFalse();
});

it('loads the check settings of all checks of a site in a fixed number of queries', function (): void {
    $owner = User::factory()->owner()->create();
    $single = Site::factory()->create();
    Check::factory()->for($single)->create(['type' => CheckKey::Uptime]);
    $many = Site::factory()->create();
    foreach ([CheckKey::Uptime, CheckKey::Performance, CheckKey::CertificateHealth, CheckKey::Domain, CheckKey::Dns,
        CheckKey::Sitemap, CheckKey::BrokenLinks, CheckKey::MixedContent, CheckKey::ApplicationHealth] as $key) {
        Check::factory()->for($many)->create(['type' => $key]);
    }
    $this->actingAs($owner);
    siteSettingsProps($single);

    $count = function (Site $site): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $props = siteSettingsProps($site);
        DB::disableQueryLog();
        expect($props['check_settings'])->toHaveCount($site->checks()->count());

        return count(DB::getQueryLog());
    };

    expect($count($many))->toBe($count($single));
});

it('shows a check saved through its settings endpoint in the site settings contract on the next load', function (): void {
    $owner = User::factory()->owner()->create();
    $site = Site::factory()->create();
    $check = Check::factory()->for($site)->create(['type' => CheckKey::Performance, 'interval_seconds' => 60]);
    $this->actingAs($owner);
    $before = collect(siteSettingsProps($site)['check_settings'])->firstWhere('check_id', $check->id);
    expect($before['interval_seconds'])->toBe(60)
        ->and($before['settings'])->not->toHaveKey('threshold_in_ms');

    $this->from('/sites/'.$site->id.'/settings')->patch('/sites/'.$site->id.'/checks/'.$check->id, [
        'frequency_value' => 5, 'frequency_unit' => 'minute', 'threshold_in_ms' => 3500,
    ])->assertRedirect('/sites/'.$site->id.'/settings')->assertSessionHasNoErrors();

    $version = $this->get('/sites/'.$site->id.'/settings')->inertiaPage()['version'];
    $partial = $this->get('/sites/'.$site->id.'/settings', [
        'X-Inertia' => 'true', 'X-Inertia-Version' => $version,
        'X-Inertia-Partial-Component' => 'sites/settings', 'X-Inertia-Partial-Data' => 'check_settings',
    ])->assertOk()->json('props');
    expect(array_keys($partial))->not->toContain('site');
    $after = collect($partial['check_settings'])->firstWhere('check_id', $check->id);
    expect($after['interval_seconds'])->toBe(300)
        ->and($after['settings']['threshold_in_ms'])->toBe(3500);
});
