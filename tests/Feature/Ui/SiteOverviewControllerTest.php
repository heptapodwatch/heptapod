<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Addons\Dns\DnsPersistence;
use Heptapod\Addons\Uptime\UptimeMetrics;
use Heptapod\Checks\Execution\ClaimService;
use Heptapod\Checks\Execution\RunOrigin;
use Heptapod\Checks\Recording\RecordableRun;
use Heptapod\Checks\Recording\ResultRecorder;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckOutcome;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\DnsLookupResult;
use Heptapod\Contracts\DnsResolver;
use Heptapod\Contracts\LatestRunEvidence;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Contracts\RunReference;
use Heptapod\Contracts\RunResult;
use Heptapod\Enums\ClientScope;
use Heptapod\Enums\IncidentCause;
use Heptapod\Enums\NotificationCategory;
use Heptapod\Jobs\RunCheck;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\CheckRunRollupHourly;
use Heptapod\Models\Client;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\RecurringMaintenancePeriod;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Settings\InstanceSettings;
use Heptapod\Testing\ClaimedRun;
use Heptapod\Testing\FakeDnsResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../../Support/NotificationChannels.php';

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-01T12:00:00Z'));
    new Event()->isGuarded('payload');
});

it('serves scoped overview history and incident links without secrets', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-01T12:00:00Z'));
    $client = Client::factory()->create();
    $user = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $user->assignRole('Member');
    $user->clients()->attach($client);
    $secret = 'overview-real-secret';
    $site = Site::factory()->for($client)->create(['crawler_headers' => [['name' => 'Authorization', 'value' => $secret]]]);
    $check = Check::factory()->for($site)->create(['latest_run_result' => CheckResult::Errored]);
    $check->forceFill(['config_secrets' => ['token' => $secret]])->save();
    $incident = Incident::factory()->for($check)->create(['cause' => IncidentCause::Errored]);
    Event::factory()->for($check)->for($incident)->create(['type' => 'incident.opened', 'to_result' => CheckResult::Errored, 'payload' => ['secret' => $secret]]);
    CheckRun::factory()->for($check)->create(['started_at' => now()->subMinutes(2), 'ended_at' => now()->subMinute(), 'metrics' => ['total_time_in_seconds' => 0.2, 'dns_time_in_seconds' => 0.01]]);
    $hidden = Site::factory()->create();
    foreach ([$hidden, Site::factory()->for($client)->create()] as $other) {
        $otherCheck = Check::factory()->for($other)->create();
        Event::factory()->for($otherCheck)->create();
        CheckRun::factory()->for($otherCheck)->create(['started_at' => now()->subMinutes(2), 'ended_at' => now()->subMinute(), 'metrics' => ['total_time_in_seconds' => 100, 'dns_time_in_seconds' => 10]]);
    }

    $response = $this->actingAs($user)->get('/sites/'.$site->id)->assertInertia(fn (Assert $page): Assert => $page
        ->component('sites/show')->where('header.id', $site->id)->where('header.name', $site->displayName())
        ->where('site.identity.id', $site->id)->where('client.id', $client->id)->where('client.name', $client->name)->where('site.checks.0.open_incident_id', $incident->id)
        ->where('site.state', 'check-error')->where('can_manage', false)->has('history', 90)->has('events', 1)->has('incidents', 1)
        // 388acc030 removes check-only charts from the site overview.
        ->where('incidents.0.cause', 'errored')->missing('response_times')->missing('phase_series'));
    expect(json_encode($response->inertiaProps(), JSON_THROW_ON_ERROR))->not->toContain($secret);
    expect($response->inertiaProps()['events'][0]['site_name'])->toBe($site->displayName());
    expect($response->inertiaProps()['events'][0]['message'])->toBe('Check error');
    $this->get('/sites/'.$hidden->id)->assertNotFound();
});

it('shares visible maintenance details with site pages but hides restricted tag-window details', function (): void {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-10-04T00:30:00Z'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $site = Site::factory()->create();
    $check = Check::factory()->for($site)->create();
    $window = MaintenancePeriod::factory()->create([
        'site_id' => $site->id, 'name' => 'Deploy', 'reason' => 'Database changes',
        'starts_at' => now()->subMinute(), 'ends_at' => now()->addHour(),
    ]);

    $this->actingAs($owner);
    foreach (['/sites/'.$site->id, '/sites/'.$site->id.'/checks/'.$check->id, '/sites/'.$site->id.'/settings'] as $path) {
        $header = $this->get($path)->assertOk()->inertiaProps()['maintenance'];
        expect($header['id'])->toBe($window->id)
            ->and($header['reason'])->toBe('Database changes')
            ->and($header['endsAt'])->toBe($window->ends_at->toIso8601String());
    }

    $window->delete();
    $site->attachTag('private-window');
    $tagId = $site->tags()->firstOrFail()->id;
    MaintenancePeriod::factory()->create([
        'site_id' => null, 'target_type' => 'tag', 'target_id' => $tagId,
        'reason' => 'Private client detail', 'starts_at' => now()->subMinute(), 'ends_at' => now()->addHour(),
    ]);
    $member = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $member->assignRole('Member');
    $member->clients()->attach($site->client);
    $header = $this->actingAs($member)->get('/sites/'.$site->id)->assertOk()->inertiaProps()['maintenance'];
    expect($header)->toMatchArray(['id' => null, 'state' => 'active', 'reason' => null, 'endsAt' => null]);
    $this->get('/sites/'.$site->id.'/checks/'.$check->id)->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->where('maintenance.id', null)->where('maintenance.reason', null));
});

it('shows duration-weighted uptime across 7, 30 and 90 day windows', function (): void {
    $now = CarbonImmutable::parse('2026-10-05T00:30:00Z');
    $this->travelTo($now);
    $user = User::factory()->create();
    $user->assignRole('Member');
    $site = Site::factory()->create(['created_at' => $now->subDays(10)]);
    $check = Check::factory()->for($site)->create(['type' => CheckKey::Uptime, 'created_at' => $now->subDays(10), 'first_result_at' => $now->subDays(10)]);
    Incident::factory()->for($check)->create(['opened_at' => $now->subDays(9), 'first_failure_at' => $now->subDays(9), 'resolved_at' => $now->subDays(8)]);

    $page = $this->actingAs($user)->get('/sites/'.$site->id)->assertOk()->inertiaProps();
    expect($page['uptime_ranges']['hours24'])->toEqual(100)
        ->and($page['uptime_ranges']['days7'])->toEqual(100)
        ->and($page['uptime_ranges']['days30'])->toEqual(90)
        ->and($page['uptime_ranges']['days90'])->toEqual(90);
});

it('shows the scoped run-all preview and each fast-only submission result', function (): void {
    $this->withoutVite();
    Queue::fake();
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $site = Site::factory()->create();
    $fast = Check::factory()->for($site)->create(['type' => CheckKey::Uptime]);
    $heavy = Check::factory()->for($site)->create(['type' => CheckKey::Lighthouse]);

    $before = $this->actingAs($owner)->get('/sites/'.$site->id)->assertOk()->inertiaProps();
    expect(collect($before['run_all_checks'])->pluck('check_id')->all())->toEqualCanonicalizing([$fast->id, $heavy->id]);
    expect(collect($before['run_all_checks'])->firstWhere('check_id', $heavy->id)['queue'])->toBe('checks-browser');
    foreach (['/sites/'.$site->id.'/checks/'.$fast->id, '/sites/'.$site->id.'/settings'] as $path) {
        $plans = $this->get($path)->assertOk()->inertiaProps()['run_all_checks'];
        expect(collect($plans)->pluck('check_id')->all())->toEqualCanonicalizing([$fast->id, $heavy->id]);
    }
    expect($before)->toHaveKey('run_all_result');
    expect($before['run_all_result'])->toBeNull();
    $this->post('/sites/'.$site->id.'/run-all', ['confirmed' => true, 'fast_only' => true])->assertRedirect('/sites/'.$site->id);
    $after = $this->get('/sites/'.$site->id)->assertOk()->inertiaProps();
    expect($after['run_all_result']['queued'])->toBe(1);
    expect(collect($after['run_all_result']['results'])->firstWhere('check_id', $fast->id)['status'])->toBe('queued');
    expect(collect($after['run_all_result']['results'])->firstWhere('check_id', $heavy->id))
        ->toMatchArray(['status' => 'skipped', 'reason' => 'fast_only']);
});

it('serves per-check metrics, masked settings and stored hourly p95 without exposing other clients', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-01T12:00:00Z'));
    $client = Client::factory()->create();
    $user = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $user->assignRole('Member');
    $user->clients()->attach($client);
    $site = Site::factory()->for($client)->create();
    $secret = 'check-tab-real-secret';
    $check = Check::factory()->for($site)->create(['type' => CheckKey::ApplicationHealth]);
    $check->forceFill(['config_secrets' => ['secret' => $secret]])->save();
    CheckRun::factory()->for($check)->create(['started_at' => now()->subMinutes(2), 'ended_at' => now()->subMinute(), 'metrics' => ['duration' => 2], 'evidence' => ['reason' => null, 'http_status' => null, 'redirect_host' => null, 'components' => [], 'secret' => $secret], 'error' => $secret]);
    CheckRunRollupHourly::factory()->for($check)->create(['bucket_start' => now()->subHour()->startOfHour(), 'metric_sums' => ['total_time_in_seconds' => 0.4], 'metric_counts' => ['total_time_in_seconds' => 2], 'metric_p95' => ['total_time_in_seconds' => 0.3]]);
    $hidden = Check::factory()->create();
    CheckRun::factory()->for($hidden)->create(['started_at' => now()->subMinutes(2), 'ended_at' => now()->subMinute(), 'metrics' => ['duration' => 9, 'total_time_in_seconds' => 100]]);

    $response = $this->actingAs($user)->get('/sites/'.$site->id.'/checks/'.$check->id)->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->component('sites/show')->where('header.id', $site->id)->where('check.id', $check->id)->where('client.id', $client->id)->has('runs', 1)->where('metrics.0.key', 'duration')
        ->where('check_settings.settings.secret', '********')->where('detail', null)->has('response_times', 4)
        ->where('response_p95_hourly.points.167.value', 300));
    expect(json_encode($response->inertiaProps(), JSON_THROW_ON_ERROR))->not->toContain($secret);
    expect($check->refresh()->config_secrets['secret'])->toBe($secret);
    $this->get('/sites/'.$hidden->site_id.'/checks/'.$hidden->id)->assertNotFound();
    $this->get('/sites/'.$site->id.'/checks/'.$hidden->id)->assertNotFound();
});

it('holds site, check, and incident detail query budgets as related history grows', function (string $page, int $count): void {
    $this->withoutVite();
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $site = Site::factory()->create();
    $check = Check::factory()->for($site)->create();
    $incident = Incident::factory()->for($check)->create();
    $eventCount = 0;
    $addHistory = static function (int $rows) use ($check, $incident, &$eventCount): void {
        Incident::factory()->count($rows)->for($check)->create(['resolved_at' => now()]);
        // Cause changes are shown in the site feed and as separate incident timeline entries, so each one is presented.
        // Distinct times inside the episode keep each insert's activity-group projection to its own timestamp.
        Event::factory()->count($rows)->for($check)->for($incident)->sequence(static function () use (&$eventCount): array {
            return ['occurred_at' => now()->subMilliseconds(++$eventCount)];
        })->create(['type' => 'incident.cause_changed', 'cause' => IncidentCause::Failed, 'from_result' => CheckResult::Failed, 'to_result' => CheckResult::Failed]);
        CheckRun::factory()->count($rows)->for($check)->create();
    };
    $this->actingAs($user);
    // Upper budgets: page shell (auth, shell health, sidebar), the site/check/incident record and its bounded
    // history, presentation and freshness reads. e04c03b5d presentations, f80f6d32a freshness, 78bd4a4b episode
    // pages, 36aca5a1a avatars and 25458fc44/0a385128 shell health are batched reads, not per-history-row queries.
    // Feed-visible events add their batched site, client, check and incident loads.
    [$path, $budget, $rendered] = match ($page) {
        'site' => ['/sites/'.$site->id, 55, static fn (array $props): int => count($props['events'])],
        'check' => ['/sites/'.$site->id.'/checks/'.$check->id, 59, static fn (array $props): int => count($props['runs'])],
        'incident' => ['/incidents/'.$incident->id, 70, static fn (array $props): int => collect($props['timeline'])->where('event.type', 'incident.cause_changed')->count()],
    };
    $measure = function () use ($path, $rendered): array {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $response = $this->get($path)->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$queries, $rendered($response->inertiaProps())];
    };
    // A first request records the session's activity and caches roles and settings; the budgets cover a warm page.
    $this->get($path)->assertOk();
    $addHistory(2);
    [$few, $fewRows] = $measure();
    $addHistory($count);
    [$many, $manyRows] = $measure();

    // The site feed and check runs show the newest 20; the incident timeline shows every cause change of the episode.
    expect($fewRows)->toBe(2)->and($manyRows)->toBe($page === 'incident' ? 2 + $count : 20);
    expect($many)->toBeLessThanOrEqual($few, $path.' grew from '.$few.' to '.$many.' queries as rendered rows grew from '.$fewRows.' to '.$manyRows)
        ->and($few)->toBeLessThanOrEqual($budget, $path.' used '.$few.' queries, over its budget of '.$budget);
})->with(['site', 'check', 'incident'])->with([30, 300]);

it('requires authentication for overview and check tabs', function (): void {
    $check = Check::factory()->create();
    $this->get('/sites/'.$check->site_id)->assertRedirect('/login');
    $this->get('/sites/'.$check->site_id.'/checks/'.$check->id)->assertRedirect('/login');
});

it('uses installed detail providers for accepted addon snapshots', function (CheckKey $key, array $snapshot): void {
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $check = Check::factory()->create(['type' => $key]);
    $at = CarbonImmutable::now();
    $context = new CheckContext($check->site_id, $check->id, 987, (string) Str::uuid(), $check->site->url, $at, $at->addMinute(), [], [], null);
    $store = app(CheckRegistry::class)->get($key)->persistence();
    $store->stage($context, 'snapshot', [$snapshot]);
    $store->finalize(new RunReference(987, $at), $context->claimToken);
    $accepted = CheckRun::factory()->for($check)->create(['id' => 987, 'ended_at' => $at, 'evidence' => $snapshot,
        'metrics' => $key === CheckKey::Domain ? ['expires_at' => null, 'not_supported_reason' => null] : []]);
    $check->update(['latest_run_id' => $accepted->id, 'latest_run_ended_at' => $accepted->ended_at]);

    $this->actingAs($user)->get('/sites/'.$check->site_id.'/checks/'.$check->id)->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->where('detail.check_type', $key->value)->has('check_settings.config_schema.fields'));
})->with([
    'certificate' => [CheckKey::CertificateHealth, ['certificate_details' => null, 'certificate_checks' => [['type' => 'trust', 'passed' => false, 'label' => 'certificate_health.trust']], 'certificate_chain_issuers' => []]],
    'dns' => [CheckKey::Dns, ['dns_records' => [], 'raw_dns_records' => [], 'normalized_records' => [], 'issues' => [], 'authoritative_nameservers' => [], 'expected' => [], 'baseline_generation' => 0]],
    'domain' => [CheckKey::Domain, ['domain' => 'visible.example', 'registrar' => 'Registrar', 'expires_at' => null, 'registered_at' => null, 'last_changed_at' => null, 'last_updated_in_rdap_db_at' => null, 'created_at' => '2026-10-01T12:00:00Z', 'nameservers' => [], 'domain_statuses' => [], 'rdap_domain_response' => null, 'date_precision' => 'exact', 'source' => 'rdap', 'server' => 'https://rdap.example', 'not_supported_reason' => null]],
]);

it('returns only requested props when polling a site overview', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $site = Site::factory()->create();

    $initial = $this->actingAs($user)->get('/sites/'.$site->id)->assertOk();
    $response = $this->get('/sites/'.$site->id, ['X-Inertia' => 'true', 'X-Inertia-Version' => $initial->inertiaPage()['version'], 'X-Inertia-Partial-Component' => 'sites/show', 'X-Inertia-Partial-Data' => 'site'])->assertOk();
    $response->assertJsonPath('props.site.id', $site->id)->assertJsonMissingPath('props.history')->assertJsonMissingPath('props.events')->assertJsonMissingPath('props.response_times');
});

/** @return array<string, mixed> the deferred props a site page loads after its first render */
function siteDeferredProps(string $path, string $props): array
{
    $initial = test()->get($path)->assertOk();
    expect($initial->inertiaProps())->not->toHaveKeys(explode(',', $props));

    return test()->get($path, ['X-Inertia' => 'true', 'X-Inertia-Version' => $initial->inertiaPage()['version'],
        'X-Inertia-Partial-Component' => 'sites/show', 'X-Inertia-Partial-Data' => $props])->assertOk()->json('props');
}

it('records every uptime outage of the last 30 days with Uptime\'s downtime, not only the 20 newest incidents', function (): void {
    $now = CarbonImmutable::parse('2026-10-01T12:00:00Z');
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $site = Site::factory()->create(['created_at' => $now->subDays(40)]);
    $uptime = Check::factory()->for($site)->create(['type' => CheckKey::Uptime, 'created_at' => $now->subDays(40), 'first_result_at' => $now->subDays(40)]);
    $certificate = Check::factory()->for($site)->create(['type' => CheckKey::CertificateHealth]);
    foreach (range(1, 24) as $day) {
        $start = $now->subDays($day);
        Incident::factory()->for($uptime)->create(['opened_at' => $start, 'first_failure_at' => $start, 'resolved_at' => $start->addMinutes(10)]);
    }
    $open = $now->subMinutes(5);
    Incident::factory()->for($uptime)->create(['opened_at' => $open, 'first_failure_at' => $open]);
    // Started before the window and still down inside it: counts, with only its last day as downtime.
    Incident::factory()->for($uptime)->create(['opened_at' => $now->subDays(31), 'first_failure_at' => $now->subDays(31), 'resolved_at' => $now->subDays(29)]);
    // Maintenance covers the first 4 of the 10 minutes on day 5, as it does for the uptime percentage.
    MaintenancePeriod::factory()->create(['site_id' => $site->id, 'starts_at' => $now->subDays(5), 'ends_at' => $now->subDays(5)->addMinutes(4)]);
    // Not outages of the window: before it, a check error, and another check's failure.
    Incident::factory()->for($uptime)->create(['opened_at' => $now->subDays(33), 'first_failure_at' => $now->subDays(33), 'resolved_at' => $now->subDays(32)]);
    Incident::factory()->for($uptime)->create(['cause' => IncidentCause::Errored, 'opened_at' => $now->subDays(2), 'first_failure_at' => $now->subDays(2), 'resolved_at' => $now->subDays(2)->addHour()]);
    Incident::factory()->for($certificate)->create(['opened_at' => $now->subDays(3), 'first_failure_at' => $now->subDays(3), 'resolved_at' => $now->subDays(2)]);
    // The Performance tab's number: the newest measured hourly average.
    CheckRunRollupHourly::factory()->for($uptime)->create(['bucket_start' => $now->subHours(3), 'metric_sums' => ['total_time_in_seconds' => 0.2], 'metric_counts' => ['total_time_in_seconds' => 1]]);
    CheckRunRollupHourly::factory()->for($uptime)->create(['bucket_start' => $now->subHour(), 'metric_sums' => ['total_time_in_seconds' => 0.8], 'metric_counts' => ['total_time_in_seconds' => 2]]);

    $this->actingAs($owner);
    expect(siteDeferredProps('/sites/'.$site->id, 'record')['record'])->toEqual([
        'outage_count' => 26, 'downtime_seconds' => 24 * 600 - 240 + 300 + 86400,
        'last_outage_started_at' => $open->toIso8601String(), 'response_ms' => 400,
    ]);
});

it('lists the next maintenance window and expiries of this site only', function (): void {
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $site = Site::factory()->create();
    $sibling = Site::factory()->for($site->client)->create();
    foreach ([[$site, '2027-04-19T00:00:00Z'], [$sibling, '2026-10-11T00:00:00Z']] as [$owned, $expiry]) {
        $domain = Check::factory()->for($owned)->create(['type' => CheckKey::Domain]);
        DB::table('domain_snapshots')->insert(['site_id' => $owned->id, 'check_id' => $domain->id, 'run_id' => $domain->id, 'claim_token' => (string) Str::uuid(), 'run_ended_at' => now(),
            'snapshot' => json_encode(['domain' => 'site.example', 'expires_at' => $expiry, 'registrar' => 'Registrar'], JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }
    MaintenancePeriod::factory()->create(['site_id' => $sibling->id, 'starts_at' => now()->addHour(), 'ends_at' => now()->addHours(2)]);
    // A daily series yields 365 occurrences; the domain expiry 200 days out must still be listed.
    RecurringMaintenancePeriod::factory()->create(['site_id' => $site->id, 'timezone' => 'UTC', 'start_time' => '23:00', 'end_time' => '23:30']);

    $this->actingAs($owner);
    $upcoming = siteDeferredProps('/sites/'.$site->id, 'upcoming')['upcoming'];
    expect(array_map(static fn (array $item): array => [$item['type'], $item['date']], $upcoming))->toBe([
        ['maintenance', '2026-10-01T23:00:00+00:00'], ['domain', '2027-04-19T00:00:00+00:00'],
    ]);
    // Pausing stops the checks, not the expiry: the site's own overview keeps its known dates.
    $site->forceFill(['paused_at' => now()])->save();
    expect(array_column(siteDeferredProps('/sites/'.$site->id, 'upcoming')['upcoming'], 'type'))->toBe(['maintenance', 'domain']);
});

it('counts the channels an outage of the site or a failure of a check would reach now', function (): void {
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $site = Site::factory()->create();
    $uptime = Check::factory()->for($site)->create(['type' => CheckKey::Uptime]);
    $certificate = Check::factory()->for($site)->create(['type' => CheckKey::CertificateHealth]);
    notificationRoute(NotificationDestination::factory()->create(), [NotificationCategory::Outage]);
    notificationMuteSite(notificationRoute(NotificationDestination::factory()->create(), [NotificationCategory::Outage]), $site->id);
    NotificationDestination::factory()->create();

    $this->actingAs($owner);
    expect(siteDeferredProps('/sites/'.$site->id, 'alert_channels')['alert_channels'])->toBe(1)
        ->and(siteDeferredProps('/sites/'.$site->id.'/checks/'.$certificate->id, 'alert_channels')['alert_channels'])->toBe(0)
        ->and(siteDeferredProps('/sites/'.$site->id.'/checks/'.$uptime->id, 'alert_channels')['alert_channels'])->toBe(1);
    $uptime->update(['enabled' => false]);
    expect(siteDeferredProps('/sites/'.$site->id, 'alert_channels')['alert_channels'])->toBeNull();
});

it('serves the Lighthouse tab\'s last successful scores for the launchpad card only with an enabled Lighthouse check', function (): void {
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $site = Site::factory()->create();
    $lighthouse = Check::factory()->for($site)->create(['type' => CheckKey::Lighthouse]);
    $scores = ['performance_score' => 91, 'accessibility_score' => 48, 'best_practices_score' => 77, 'seo_score' => 100, 'progressive_web_app_score' => null,
        'first_contentful_paint_in_ms' => null, 'speed_index_in_ms' => null, 'largest_contentful_paint_in_ms' => null, 'time_to_interactive_in_ms' => null,
        'total_blocking_time_in_ms' => null, 'cumulative_layout_shift' => null];
    $run = CheckRun::factory()->for($lighthouse)->create(['started_at' => now()->subHours(2), 'ended_at' => now()->subHour(), 'metrics' => $scores]);

    $this->actingAs($owner);
    $card = siteDeferredProps('/sites/'.$site->id, 'lighthouse')['lighthouse'];
    expect($card['check_type'])->toBe('lighthouse')
        ->and($card['last_successful_at'])->toBe($run->ended_at->toIso8601String())
        ->and(array_intersect_key($card['last_successful_metrics'], $scores))->toEqual($scores);
    $lighthouse->update(['enabled' => false]);
    expect(siteDeferredProps('/sites/'.$site->id, 'lighthouse')['lighthouse'])->toBeNull();
});

it('keeps counting a channel once it has been alerted about the open outage', function (): void {
    Mail::fake();
    config(['mail.default' => 'smtp']);
    $settings = app(InstanceSettings::class);
    $settings->alert_after_minutes = 10;
    $settings->save();
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $uptime = Check::factory()->create(['type' => CheckKey::Uptime, 'failure_threshold' => 1]);
    notificationRoute(NotificationDestination::factory()->create(), [NotificationCategory::Outage]);
    $at = CarbonImmutable::now('UTC');
    $context = ClaimedRun::start($uptime->fresh(), $at);
    app(ResultRecorder::class)->record(new RecordableRun($uptime->id, $context->claimToken, $at, $at,
        new CheckOutcome(RunResult::Failed, 'uptime.status', [], new UptimeMetrics(null, null, null, null, null, 0.01,
            array_fill_keys(['namelookup_time', 'connect_time', 'appconnect_time', 'starttransfer_time', 'pretransfer_time', 'redirect_time', 'total_time', 'header_size', 'size_download', 'speed_download'], null), '2026-10-01'))));
    dispatchNotifications();
    $this->travel(10)->minutes();
    dispatchNotifications();
    expect(Delivery::query()->where('state', DeliveryState::Sent)->count())->toBe(1);

    $this->actingAs($owner);
    expect(siteDeferredProps('/sites/'.$uptime->site_id, 'alert_channels')['alert_channels'])->toBe(1);
});

it('preserves nested public header names while masking their secrets in UI and API settings', function (CheckKey $key, string $field): void {
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $secret = 'nested-header-secret';
    $settings = [$field => [['name' => 'Authorization', 'value' => $secret], ['name' => 'X-Key', 'value' => 'second-header-secret']]];
    if ($key === CheckKey::ApplicationHealth) {
        $settings += ['result_url' => 'https://health.example/results', 'secret' => 'health-secret'];
    }
    $schema = app(CheckRegistry::class)->get($key)->definition()->configSchema;
    $check = Check::factory()->create(['type' => $key, 'config' => $schema->validateConfig($settings)]);

    $response = $this->actingAs($user)->get('/sites/'.$check->site_id.'/checks/'.$check->id)->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->where('check_settings.settings.'.$field.'.0.name', 'Authorization')->where('check_settings.settings.'.$field.'.0.value', '********')
        ->where('check_settings.settings.'.$field.'.1.name', 'X-Key')->where('check_settings.settings.'.$field.'.1.value', '********'));
    expect(json_encode($response->inertiaProps(), JSON_THROW_ON_ERROR))->not->toContain($secret);
    $this->withToken($user->createToken('nested-settings', ['*'])->plainTextToken)->getJson('/api/monitors/'.$check->site_id)
        ->assertOk()->assertJsonPath($key->value.'_check_settings.'.$field.'.0.name', 'Authorization')
        ->assertJsonPath($key->value.'_check_settings.'.$field.'.0.value', '********');
    expect($check->refresh()->executionConfig()[$field][0]['value'])->toBe($secret);
})->with([
    'uptime' => [CheckKey::Uptime, 'http_client_headers'],
    'application health' => [CheckKey::ApplicationHealth, 'headers'],
]);

it('redacts secrets reflected in accepted addon detail snapshots without changing stored evidence', function (CheckKey $key, array $snapshot, string $path): void {
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $siteSecret = 'reflected-site-secret';
    $checkSecret = 'reflected-check-secret';
    $site = Site::factory()->create(['crawler_headers' => [['name' => 'Authorization', 'value' => $siteSecret]]]);
    $check = Check::factory()->for($site)->create(['type' => $key]);
    $check->forceFill(['config_secrets' => ['token' => $checkSecret]])->save();
    $at = CarbonImmutable::now();
    $context = new CheckContext($site->id, $check->id, 987, (string) Str::uuid(), $site->url, $at, $at->addMinute(), [], [], null);
    $type = app(CheckRegistry::class)->get($key);
    $store = $type->persistence();
    $store->stage($context, 'snapshot', [$snapshot]);
    $store->finalize(new RunReference(987, $at), $context->claimToken);
    $accepted = CheckRun::factory()->for($check)->create(['id' => 987, 'ended_at' => $at, 'evidence' => $snapshot,
        'metrics' => $key === CheckKey::Domain ? ['expires_at' => null, 'not_supported_reason' => null] : []]);
    $check->update(['latest_run_id' => $accepted->id, 'latest_run_ended_at' => $accepted->ended_at]);

    $response = $this->actingAs($user)->get('/sites/'.$site->id.'/checks/'.$check->id)->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->where('detail.'.$path, '[redacted] [redacted]')->where('detail.check_type', $key->value));
    $json = json_encode($response->inertiaProps(), JSON_THROW_ON_ERROR);
    expect($json)->not->toContain($siteSecret);
    expect($json)->not->toContain($checkSecret);
    expect(json_encode($type->detail($check->id, new LatestRunEvidence($accepted->reference(), $accepted->result, $accepted->evidence))->toArray(), JSON_THROW_ON_ERROR))->toContain($siteSecret);
})->with([
    'dns' => [CheckKey::Dns, ['dns_records' => [['host' => 'visible.example', 'ttl' => 60, 'class' => 'IN', 'type' => 'TXT', 'txt' => 'reflected-site-secret reflected-check-secret']], 'raw_dns_records' => ['TXT' => ['reflected-site-secret reflected-check-secret']], 'normalized_records' => [], 'issues' => [], 'authoritative_nameservers' => [], 'expected' => [], 'baseline_generation' => 0], 'dns_records.0.txt'],
    'certificate' => [CheckKey::CertificateHealth, ['certificate_details' => null, 'certificate_checks' => [['type' => 'trust', 'passed' => false, 'label' => 'certificate_health.trust']], 'certificate_chain_issuers' => ['reflected-site-secret reflected-check-secret']], 'certificate_chain_issuers.0'],
    'domain' => [CheckKey::Domain, ['domain' => 'visible.example', 'registrar' => null, 'expires_at' => null, 'registered_at' => null, 'last_changed_at' => null, 'last_updated_in_rdap_db_at' => null, 'created_at' => '2026-10-01T00:00:00Z', 'nameservers' => [], 'domain_statuses' => [], 'rdap_domain_response' => ['notices' => [['description' => ['reflected-site-secret reflected-check-secret']]]], 'date_precision' => 'exact', 'source' => 'rdap', 'server' => 'rdap.example', 'not_supported_reason' => null], 'rdap_domain_response.notices.0.description.0'],
]);

it('keeps retained checks readable when their addon is uninstalled', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $key = CheckKey::CertificateHealth;
    $check = Check::factory()->create(['type' => $key]);
    $check->forceFill(['config_secrets' => ['token' => 'uninstalled-check-secret']])->save();
    $run = CheckRun::factory()->for($check)->create();
    $installed = app(CheckRegistry::class);
    $registry = new CheckRegistry(app());
    foreach ($installed->keys() as $installedKey) {
        if ($installedKey !== $key) {
            $registry->register($installed->get($installedKey)::class);
        }
    }
    app()->instance(CheckRegistry::class, $registry);

    $response = $this->actingAs($user)->get('/sites/'.$check->site_id.'/checks/'.$check->id)->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->where('check.key', $key->value)->where('detail', null)->where('check_settings.config_schema.fields', [])
        ->has('runs', 1)->where('runs.0.id', $run->id)->where('check_settings.settings.token', '********'));
    expect(json_encode($response->inertiaProps(), JSON_THROW_ON_ERROR))->not->toContain('uninstalled-check-secret');
});

it('projects the current accepted DNS warning or failure instead of its retained comparison baseline', function (bool $resolves, string $result, array $issues): void {
    Queue::fake();
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $site = Site::factory()->create(['url' => 'https://detail.example']);
    $schema = app(CheckRegistry::class)->get(CheckKey::Dns)->definition()->configSchema;
    $check = Check::factory()->for($site)->create(['type' => CheckKey::Dns, 'config' => $schema->validateConfig([])]);
    $fake = new FakeDnsResolver;
    app()->instance(DnsResolver::class, $fake);
    $address = ['host' => 'detail.example', 'ttl' => 60, 'class' => 'IN', 'type' => 'A', 'ip' => '192.0.2.10'];
    foreach (['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'CAA'] as $recordType) {
        $records = match ($recordType) {
            'A' => [$address],
            'NS' => [['host' => 'detail.example', 'ttl' => 60, 'class' => 'IN', 'type' => 'NS', 'target' => 'ns.detail.example']],
            default => [],
        };
        $fake->respondWith('detail.example', $recordType, new DnsLookupResult($records, [], null));
        $fake->respondWith('detail.example', $recordType, new DnsLookupResult($records, [], 'ns.detail.example'), 'ns.detail.example');
    }
    $run = function () use ($check): CheckRun {
        expect(app(ClaimService::class)->runNow($check->id, RunOrigin::System))->not->toBeNull();
        $type = app(CheckRegistry::class)->get(CheckKey::Dns)->definition();
        $job = new RunCheck($check->id, $check->refresh()->claim_token, $type->timeout, $type->queue);
        app()->call($job->handle(...));

        return CheckRun::forRun(new RunReference($check->refresh()->latest_run_id, $check->latest_run_ended_at))->firstOrFail();
    };
    expect($run()->result)->toBe(RunResult::Succeeded);
    $baseline = app(DnsPersistence::class)->baseline($check->id);
    $this->actingAs($user)->get('/sites/'.$site->id.'/checks/'.$check->id)->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->where('detail.dns_records.0.ip', '192.0.2.10')->where('detail.issues', []));

    $fake->respondWith('detail.example', 'NS', new DnsLookupResult([], [], null));
    $fake->respondWith('example', 'NS', new DnsLookupResult([], [], null));
    $fake->respondWith('detail.example', 'A', new DnsLookupResult($resolves ? [$address] : [], [], null));
    $accepted = $run();
    expect($accepted->result->value)->toBe($result);
    CheckRun::factory()->for($check)->create(['id' => $accepted->id, 'ended_at' => $accepted->ended_at->addDay(),
        'evidence' => ['dns_records' => [$address], 'raw_dns_records' => [], 'normalized_records' => [], 'issues' => [], 'authoritative_nameservers' => []]]);
    expect(app(DnsPersistence::class)->baseline($check->id))->toBe($baseline);
    expect(Event::query()->where('type', 'dns.records_changed')->count())->toBe(0);

    DB::enableQueryLog();
    DB::flushQueryLog();
    $response = $this->get('/sites/'.$site->id.'/checks/'.$check->id)->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->where('check.result', $result)->where('detail.check_type', 'dns')
        ->where('detail.issues', $issues)->has('detail.dns_records', $resolves ? 1 : 0));
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    // e04c03b5d reads the physical accepted run for its presentation as well as addon detail.
    expect(array_filter($queries, static fn (array $query): bool => str_contains($query['query'], '"id" = ? and "ended_at" = ? and "check_id" = ?')))->toHaveCount(2);
    // d5dd89f9f separates the recorder's internal flags from addon observation evidence.
    $publicEvidence = $accepted->evidence;
    unset($publicEvidence['verified_nothing']);
    expect($response->inertiaProps()['detail'])->toEqual($publicEvidence);
    CheckRun::forRun($accepted->reference())->delete();
    $this->get('/sites/'.$site->id.'/checks/'.$check->id)->assertOk()->assertInertia(fn (Assert $page): Assert => $page->where('detail', null));
    expect(app(DnsPersistence::class)->baseline($check->id))->toBe($baseline);
})->with([
    'healthy to nameservers unreachable' => [true, 'warning', [
        ['type' => 'dns.issue.nameservers_unreachable', 'host' => 'detail.example', 'record_type' => null],
    ]],
    'healthy to empty resolution' => [false, 'failed', [
        ['type' => 'dns.issue.nameservers_unreachable', 'host' => 'detail.example', 'record_type' => null],
        ['type' => 'dns.issue.resolves_to_nothing', 'host' => 'detail.example', 'record_type' => null],
    ]],
]);
