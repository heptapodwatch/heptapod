<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Actions\ClaimNotificationDelivery;
use Heptapod\Actions\CompleteNotificationDelivery;
use Heptapod\Actions\EnsureAccountChannel;
use Heptapod\Actions\PlanNotificationEvent;
use Heptapod\Contracts\AlertPreviewRenderer;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\Notifications\ChannelRegistry;
use Heptapod\Contracts\Notifications\DeliveryResult;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Enums\ClientScope;
use Heptapod\Enums\IncidentCause;
use Heptapod\Http\ListPageSize;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Client;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\Support\QueryPlanWork;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-29T12:00:00Z'));
    new Event()->isGuarded('payload');
});

it('scopes incident list filters and detail timelines without exposing stored secrets', function (): void {
    $this->withoutVite();
    $this->travelTo(now()->startOfMinute());
    $client = Client::factory()->create();
    $user = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $user->assignRole('Member');
    $user->clients()->attach($client);
    $secret = 'incident-page-real-secret';
    $site = Site::factory()->for($client)->create(['crawler_headers' => [['name' => 'Authorization', 'value' => $secret]]]);
    $check = Check::factory()->for($site)->create(['type' => CheckKey::Dns, 'latest_run_result' => CheckResult::Errored]);
    $incident = Incident::factory()->for($check)->create(['cause' => IncidentCause::Errored, 'first_failure_at' => now()->subMinutes(3), 'evidence' => ['error' => $secret]]);
    $resolved = Incident::factory()->for($check)->create(['first_failure_at' => now()->subDay(), 'resolved_at' => now()->subHour()]);
    $event = Event::factory()->for($check)->for($incident)->create(['type' => 'incident.opened', 'to_result' => CheckResult::Errored, 'payload' => ['secret' => $secret]]);
    Event::factory()->for($check)->for($resolved)->create();
    $run = CheckRun::factory()->for($check)->create(['started_at' => null, 'ended_at' => now()->subMinute()]);
    CheckRun::factory()->for($check)->create(['started_at' => now()->subHours(2), 'ended_at' => now()->subHour()]);
    $hidden = Incident::factory()->create();
    $this->actingAs($user);

    $list = $this->get('/incidents?filter[status]=open&filter[cause]=errored&filter[check]=dns&filter[client_id]='.$client->id.'&filter[started_after]='.now()->subHour()->toDateString())->assertInertia(fn (Assert $page): Assert => $page
        ->component('incidents/index')->has('incidents', 1)->where('incidents.0.id', $incident->id)->where('incidents.0.cause', 'errored')->where('pagination.total', 1)->has('clients', 1));
    $detail = $this->get('/incidents/'.$incident->id)->assertInertia(fn (Assert $page): Assert => $page
        ->component('incidents/show')->where('incident.id', $incident->id)->where('site.checks.0.open_incident_id', $incident->id)
        ->where('evidence.facts.error', '[redacted]')->where('alert_preview_available', false)
        ->where('timeline.0.event.id', $event->id)->has('runs', 1)->where('runs.0.id', $run->id)->where('runs.0.started_at', null));
    foreach ([$list, $detail] as $response) {
        expect(json_encode($response->inertiaProps(), JSON_THROW_ON_ERROR))->not->toContain($secret);
    }
    $this->get('/incidents/'.$hidden->id)->assertNotFound();
    $this->get('/incidents?filter[status]=resolved')->assertInertia(fn (Assert $page): Assert => $page->where('incidents.0.id', $resolved->id)->has('incidents', 1));
    $this->get('/incidents?filter[client_id]='.$hidden->site->client_id)->assertInertia(fn (Assert $page): Assert => $page->has('incidents', 0));
});

it('shows each recipient only the observed SMTP acceptance or rejection for an incident', function (): void {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-10-05T00:30:00Z'));
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $incident = Incident::factory()->create();
    $event = Event::factory()->create(['incident_id' => $incident->id, 'site_id' => $incident->site_id,
        'check_id' => $incident->check_id, 'type' => 'incident.opened', 'cause' => 'failed', 'to_result' => CheckResult::Failed]);
    foreach (['accepted@example.test', 'rejected@example.test'] as $address) {
        $destination = NotificationDestination::factory()->create(['config' => app(ChannelRegistry::class)
            ->get('mail')->configSchema()->validateConfig(['email' => $address])]);
        DB::table('notification_preferences')->where('destination_id', $destination->id)->where('category', 'outage')->update(['mode' => 'immediate']);
    }
    app(PlanNotificationEvent::class)($event->id);
    $deliveries = Delivery::query()->get()->sortBy('id')->values();
    foreach ($deliveries as $index => $delivery) {
        $claim = app(ClaimNotificationDelivery::class)($delivery->id);
        app(CompleteNotificationDelivery::class)($delivery->id, $claim->claim_token,
            new DeliveryResult($index === 0 ? DeliveryState::Sent : DeliveryState::Failed, $index === 0 ? null : 'notifications.transport_failed',
                diagnosis: $index === 0 ? null : 'SMTP 550 RCPT'));
    }

    $group = $this->actingAs($user)->get('/incidents/'.$incident->id)->inertiaProps()['timeline'][1]['notification_group'];
    expect($group['state'])->toBe('mixed')->and($group['accepted_count'])->toBe(1)->and($group['failed_count'])->toBe(1);
    $this->get('/incidents/'.$incident->id.'?notification_group='.$group['id'])->assertInertia(fn (Assert $page): Assert => $page
        ->where('expanded_notifications.deliveries.0.address', 'rejected@example.test')->where('expanded_notifications.deliveries.0.state', 'rejected')
        ->where('expanded_notifications.deliveries.0.diagnosis', 'SMTP 550 RCPT')->where('expanded_notifications.deliveries.0.event_type', 'incident.opened')
        ->where('expanded_notifications.deliveries.1.address', 'accepted@example.test')->where('expanded_notifications.deliveries.1.state', 'accepted'));
});

it('shares a deferred incident notification deadline as an instant for the viewer to format', function (): void {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-10-05T00:30:00Z'));
    $owner = User::factory()->owner()->create(['timezone' => 'Europe/Berlin']);
    $incident = Incident::factory()->create();
    $event = Event::factory()->for($incident)->create(['site_id' => $incident->site_id,
        'check_id' => $incident->check_id, 'type' => 'check.result_changed']);
    $destination = NotificationDestination::factory()->create();
    Delivery::query()->create(['event_id' => $event->id, 'destination_id' => $destination->id,
        'state' => DeliveryState::Pending, 'last_reason' => 'deferred_by_snooze',
        'next_attempt_at' => now()->addMinutes(15), 'suppression_evaluated_at' => now()]);

    $this->actingAs($owner)->get('/incidents/'.$incident->id)->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->where('other_deliveries.0.diagnosis', 'notifications.deferred_by_snooze')
        ->where('other_deliveries.0.diagnosis_params.time', '2026-10-05T00:45:00+00:00'));
});

it('pages the other incident mail deliveries newest first and remembers the page size', function (): void {
    $this->withoutVite();
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $incident = Incident::factory()->create();
    $destination = NotificationDestination::factory()->create();
    for ($index = 0; $index < 52; $index++) {
        $event = Event::factory()->for($incident)->create(['site_id' => $incident->site_id,
            'check_id' => $incident->check_id, 'type' => 'check.result_changed']);
        Delivery::query()->create(['event_id' => $event->id, 'destination_id' => $destination->id,
            'next_attempt_at' => now(), 'suppression_evaluated_at' => now()]);
    }
    $ids = Delivery::query()->orderBy('id')->pluck('id');
    $this->actingAs($owner)->get('/incidents/'.$incident->id)->assertInertia(fn (Assert $page): Assert => $page
        ->has('other_deliveries', 50)->where('other_deliveries.0.id', $ids->last())
        ->where('other_deliveries.0.event_type', 'check.result_changed')
        ->where('other_deliveries.49.id', $ids[2])
        ->where('other_deliveries_pagination.total', 52)->where('other_deliveries_pagination.last_page', 2));
    $this->get('/incidents/'.$incident->id.'?incident_notifications_page=2')->assertInertia(fn (Assert $page): Assert => $page
        ->has('other_deliveries', 2)->where('other_deliveries.0.id', $ids[1])->where('other_deliveries.1.id', $ids[0]));

    $this->get('/incidents/'.$incident->id.'?incident_notifications_per_page=20')
        ->assertCookie(ListPageSize::Cookie, json_encode(['incident_notifications' => 20]), false)
        ->assertInertia(fn (Assert $page): Assert => $page->has('other_deliveries', 20)->where('other_deliveries_pagination.last_page', 3));
    $this->get('/incidents/'.$incident->id.'?incident_notifications_per_page=30')->assertSessionHasErrors('incident_notifications_per_page');
});

it('masks other recipients for Members and exposes suppressed deliveries honestly', function (): void {
    $this->withoutVite();
    $member = User::factory()->create();
    $member->assignRole('Member');
    $incident = Incident::factory()->create();
    $event = Event::factory()->create(['incident_id' => $incident->id, 'site_id' => $incident->site_id,
        'check_id' => $incident->check_id, 'type' => 'check.result_changed', 'to_result' => CheckResult::Warning]);
    $personal = app(EnsureAccountChannel::class)($member);
    $team = NotificationDestination::factory()->create(['config' => app(ChannelRegistry::class)
        ->get('mail')->configSchema()->validateConfig(['email' => 'team-secret@example.test'])]);
    foreach ([$personal, $team] as $destination) {
        Delivery::query()->create(['event_id' => $event->id, 'destination_id' => $destination->id,
            'next_attempt_at' => now(), 'suppressed' => $destination->id === $team->id,
            'suppression_reason' => $destination->id === $team->id ? 'destination_disabled' : null,
            'suppression_evaluated_at' => now()]);
    }
    $this->actingAs($member)->get('/incidents/'.$incident->id)->assertInertia(fn (Assert $page): Assert => $page
        ->where('other_deliveries.0.address', 'Hidden recipient')->where('other_deliveries.0.state', 'suppressed')
        ->where('other_deliveries.1.address', $member->email)->where('other_deliveries.1.state', 'pending'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $this->actingAs($owner)->get('/incidents/'.$incident->id)->assertInertia(fn (Assert $page): Assert => $page
        ->where('other_deliveries.0.address', 'team-secret@example.test'));
});

it('lists unresolved incidents first with real duration data', function (): void {
    $this->withoutVite();
    $this->travelTo(now()->startOfMinute());
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $check = Check::factory()->create();
    $resolved = Incident::factory()->for($check)->create([
        'first_failure_at' => now()->subMinutes(12), 'opened_at' => now()->subMinute(),
        'resolved_at' => now()->subMinutes(10),
    ]);
    $secondCheck = Check::factory()->for($check->site)->create(['type' => CheckKey::Dns]);
    $secondOpen = Incident::factory()->for($secondCheck)->create([
        'first_failure_at' => now()->subHours(3), 'opened_at' => now()->subHours(3),
    ]);
    $openCheck = Check::factory()->for($check->site)->create(['type' => CheckKey::Performance]);
    $open = Incident::factory()->for($openCheck)->create([
        'first_failure_at' => now()->subHours(4), 'opened_at' => now()->subHours(4),
    ]);

    $this->actingAs($owner)->get('/incidents?filter[status]=all')->assertInertia(fn (Assert $page): Assert => $page
        ->has('incidents', 3)
        ->where('incidents.0.id', $secondOpen->id)
        ->where('incidents.0.duration_seconds', 10800)
        ->where('incidents.1.id', $open->id)->where('incidents.1.duration_seconds', 14400)
        ->where('incidents.2.id', $resolved->id)->where('incidents.2.duration_seconds', 120));
});

it('paginates incidents within a fixed query budget', function (int $count): void {
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $check = Check::factory()->create();
    $this->withoutVite();
    Incident::factory()->count(2)->for($check)->create(['resolved_at' => now()]);
    $this->actingAs($user);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->get('/incidents?filter[status]=all')->assertInertia(fn (Assert $page): Assert => $page->has('incidents', 2)->where('pagination.total', 2));
    $fewQueries = count(DB::getQueryLog());
    DB::disableQueryLog();
    Incident::factory()->count($count - 2)->for($check)->create(['resolved_at' => now()]);
    DB::enableQueryLog();
    DB::flushQueryLog();

    $response = $this->get('/incidents?filter[status]=all');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    $response->assertInertia(fn (Assert $page): Assert => $page->has('incidents', 50)->where('pagination.total', $count));
    expect(count($queries))->toBeLessThanOrEqual($fewQueries, 'the incident list grew from '.$fewQueries.' to '.count($queries).' queries as incidents grew from 2 to '.$count)
        ->and(count(array_filter($queries, static fn (array $query): bool => str_contains($query['query'], 'percentile_cont(0.5)'))))->toBe(1);
    // Upper budget: page shell and shell health (25458fc44/0a385128), one incident page with batched
    // current/opening presentations (83aee8842), one duration-median read, the sidebar's inline late runs count and
    // Running now (visible sites, check runs, imports, backup).
    expect($fewQueries)->toBeLessThanOrEqual(28, 'the incident list used '.$fewQueries.' queries, over its budget of 28');
    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $response->inertiaPage()['version'],
        'X-Inertia-Partial-Component' => 'incidents/index', 'X-Inertia-Partial-Data' => 'incidents,pagination'])
        ->get('/incidents?filter[status]=all')->assertOk()->assertJsonPath('component', 'incidents/index');
    $partialQueries = DB::getQueryLog();
    DB::disableQueryLog();
    expect(array_filter($partialQueries, static fn (array $query): bool => str_contains($query['query'], 'percentile_cont(0.5)')))->toBe([]);
})->with([50, 500]);

it('rejects invalid incident filters and unauthenticated requests', function (): void {
    $this->get('/incidents')->assertRedirect('/login');
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $this->actingAs($user)->get('/incidents?filter[status]=unknown')->assertSessionHasErrors('filter.status');
    $this->get('/incidents?filter[started_after]=2026-10-02&filter[started_before]=2026-10-01')->assertSessionHasErrors('filter.started_before');
});

it('defaults to 30 days and can list older incidents on request', function (): void {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-10-05T00:30:00Z'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $incident = Incident::factory()->create(['first_failure_at' => now()->subDays(31),
        'opened_at' => now()->subDays(31), 'resolved_at' => now()->subDays(30)]);
    $open = Incident::factory()->create(['first_failure_at' => now()->subDays(40), 'opened_at' => now()->subDays(40)]);

    $this->actingAs($owner)->get('/incidents')->assertInertia(fn (Assert $page): Assert => $page
        ->has('incidents', 1)->where('incidents.0.id', $open->id)->where('filters.period.value', '30d'));
    $this->get('/incidents?filter[period]=30d')->assertInertia(fn (Assert $page): Assert => $page
        ->has('incidents', 1)->where('incidents.0.id', $open->id));
    $csv = $this->get('/incidents/export.csv')->assertOk()->streamedContent();
    $csvRows = array_map(str_getcsv(...), explode("\n", trim($csv)));
    expect($csvRows)->toHaveCount(2)->and($csvRows[1][0])->toBe((string) $open->id);
    $this->get('/incidents?filter[status]=all&filter[period]=all')->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->has('incidents', 2)->where('incidents.0.id', $open->id)->where('filters.period.value', 'all'));
});

it('summarizes the last 30 days including open time at 00:30 UTC', function (): void {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-10-05T00:30:00Z'));
    $member = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $member->assignRole('Member');
    $check = Check::factory()->create();
    $member->clients()->attach($check->site->client_id);
    Incident::factory()->for($check)->create(['first_failure_at' => now()->subMinutes(20), 'opened_at' => now()->subMinutes(20)]);
    Incident::factory()->for($check)->create(['first_failure_at' => now()->subMinutes(10), 'opened_at' => now()->subMinutes(10), 'resolved_at' => now()]);
    Incident::factory()->for($check)->create(['first_failure_at' => now()->subDays(31), 'opened_at' => now()->subDays(31), 'resolved_at' => now()->subDays(30)]);
    Incident::factory()->create(['first_failure_at' => now()->subMinute(), 'opened_at' => now()->subMinute()]);

    $this->actingAs($member)->get('/incidents')->assertInertia(fn (Assert $page): Assert => $page
        ->where('summary.count', 2)->where('summary.total_duration_seconds', 1800)
        ->where('summary.median_duration_seconds', 900));
});

it('shows the site maintenance window on an incident to every role', function (): void {
    $this->withoutVite();
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $incident = Incident::factory()->create();
    $window = MaintenancePeriod::factory()->create(['site_id' => $incident->site_id,
        'starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);
    $this->actingAs($owner)->get('/incidents/'.$incident->id)->assertInertia(fn (Assert $page): Assert => $page
        ->where('maintenance.id', $window->id)->missing('can_manage'));
    $member = User::factory()->create();
    $member->assignRole('Member');
    $this->actingAs($member)->get('/incidents/'.$incident->id)->assertInertia(fn (Assert $page): Assert => $page
        ->where('maintenance.id', $window->id)->missing('can_manage'));
});

it('shows actual recovery facts and only open incidents in the incident client', function (): void {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-10-05T00:30:00Z'));
    $client = Client::factory()->create();
    $member = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $member->assignRole('Member');
    $member->clients()->attach($client);
    $site = Site::factory()->for($client)->create();
    $sibling = Incident::factory()->for(Check::factory()->for(Site::factory()->for($client)))->create();
    Incident::factory()->create();
    $check = Check::factory()->for($site)->create();
    $incident = Incident::factory()->for($check)->create(['first_failure_at' => now()->subMinutes(25),
        'opened_at' => now()->subMinutes(24), 'resolved_at' => now()->subMinutes(20)]);
    Event::factory()->for($incident)->create(['type' => 'incident.resolved', 'site_id' => $site->id,
        'check_id' => $check->id, 'occurred_at' => $incident->resolved_at,
        'to_result' => CheckResult::Succeeded, 'payload' => ['summary' => 'checks.uptime.succeeded', 'summary_params' => []]]);
    CheckRun::factory()->for($check)->create(['started_at' => now()->subMinutes(18)->subSecond(), 'ended_at' => now()->subMinutes(18)]);
    CheckRun::factory()->for($check)->create(['started_at' => now()->subMinutes(17)->subSecond(), 'ended_at' => now()->subMinutes(17)]);

    $this->actingAs($member)->get('/incidents/'.$incident->id)->assertInertia(fn (Assert $page): Assert => $page
        ->where('evidence.recovery_summary', 'checks.uptime.succeeded')
        ->where('evidence.retained_good_results_since_recovery', 2)
        ->where('evidence.good_results_capped', false)
        ->where('related_incidents.0.id', $sibling->id)->has('related_incidents', 1));
});

it('uses bounded query plans for recovery, summary and retained good runs', function (): void {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-10-05T00:30:00Z'));
    $client = Client::factory()->create();
    $otherClient = Client::factory()->create();
    $member = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $member->assignRole('Member');
    $member->clients()->attach($client);
    $site = Site::factory()->for($client)->create();
    Site::factory()->for($client)->create();
    $check = Check::factory()->for($site)->create();
    $otherCheck = Check::factory()->create();
    $incident = Incident::factory()->for($check)->create(['first_failure_at' => now()->subHour(),
        'opened_at' => now()->subHour(), 'resolved_at' => now()->subMinutes(30)]);
    Event::factory()->for($incident)->create(['site_id' => $site->id, 'check_id' => $check->id, 'type' => 'incident.resolved',
        'occurred_at' => now()->subMinutes(30), 'payload' => ['summary' => 'checks.uptime.succeeded']]);
    CheckRun::factory()->for($check)->create(['started_at' => now()->subMinutes(29)->subSecond(), 'ended_at' => now()->subMinutes(29)]);

    // ab2baec2d retains site/client identity even after the monitored site is deleted.
    DB::insert("INSERT INTO events (site_id, check_id, type, occurred_at, payload, suppressed, suppression_evaluated_at, site_name, site_url, client_name, client_id)
        SELECT ?, ?, 'check.result_changed', ?::timestamptz - n * INTERVAL '1 second', '{}'::jsonb, false, ?::timestamptz,
            ?, ?, ?, ? FROM generate_series(1, 20000) n", [$otherCheck->site_id, $otherCheck->id, now()->toIso8601String(), now()->toIso8601String(),
        $otherCheck->site->displayName(), $otherCheck->site->url, $otherCheck->site->client->name, $otherCheck->site->client_id]);
    DB::insert("INSERT INTO incidents (check_id, site_id, cause, opened_at, first_failure_at, resolved_at, evidence, created_at, updated_at)
        SELECT ?, ?, 'failed', ?::timestamptz, ?::timestamptz, ?::timestamptz, '{}'::jsonb, ?::timestamptz, ?::timestamptz
        FROM generate_series(1, 5000)", [$otherCheck->id, $otherCheck->site_id, now()->toIso8601String(), now()->toIso8601String(),
        now()->toIso8601String(), now()->toIso8601String(), now()->toIso8601String()]);
    DB::insert("INSERT INTO sites (client_id, url, badge_id, created_at, updated_at)
        SELECT ?, 'https://unrelated-' || n || '.example', gen_random_uuid(), ?::timestamptz, ?::timestamptz
        FROM generate_series(1, 2000) n", [$otherClient->id, now()->toIso8601String(), now()->toIso8601String()]);
    DB::insert("INSERT INTO check_runs (check_id, check_type, result, started_at, ended_at, summary, summary_params, metrics, evidence)
        SELECT ?, 'uptime', 'succeeded', ?::timestamptz - (n + 1) * INTERVAL '1 second',
        ?::timestamptz - n * INTERVAL '1 second', 'checks.uptime.succeeded', '{}'::jsonb, '{}'::jsonb, '{}'::jsonb
        FROM generate_series(1, 5000) n", [$otherCheck->id, now()->toIso8601String(), now()->toIso8601String()]);
    // Planner estimates can survive rollback of earlier bulk maintenance fixtures.
    foreach (['events', 'incidents', 'sites', 'check_runs', 'maintenance_periods'] as $table) {
        DB::statement('ANALYZE '.$table);
    }

    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->actingAs($member)->get('/incidents')->assertInertia(fn (Assert $page): Assert => $page->where('summary.count', 1));
    $this->get('/incidents/'.$incident->id)->assertInertia(fn (Assert $page): Assert => $page
        ->where('evidence.retained_good_results_since_recovery', 1));
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    $plans = [
        'recovery' => static fn (array $query): bool => str_contains($query['query'], 'from "events"') && str_contains($query['query'], '"incident_id" = ?') && str_contains($query['query'], '"type" = ?') && str_contains($query['query'], 'order by "occurred_at" asc'),
        'summary' => static fn (array $query): bool => str_contains($query['query'], 'percentile_cont(0.5)'),
        'good_runs' => static fn (array $query): bool => str_contains($query['query'], 'from "check_runs"') && str_contains($query['query'], '"result" = ?') && str_contains($query['query'], 'limit 21'),
    ];
    foreach ($plans as $name => $select) {
        $query = collect($queries)->first($select);
        expect($query)->not->toBeNull($name.' query missing');
        expect(QueryPlanWork::scannedRows($query['query'], $query['bindings']))->toBeLessThan(200, $name.' scanned too many rows');
    }
});

it('exports only filtered and scoped incidents in every format and escapes spreadsheet formulas', function (): void {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-10-05T10:00:00Z'));
    $client = Client::factory()->create(['name' => '＝HYPERLINK("https://bad.example")']);
    $user = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $user->assignRole('Member');
    $user->clients()->attach($client);
    $site = Site::factory()->for($client)->create(['friendly_name' => "\t=SUM(1,1)"]);
    $check = Check::factory()->for($site)->create(['type' => CheckKey::Uptime]);
    $incident = Incident::factory()->for($check)->create();
    $foreign = Incident::factory()->create();

    $response = $this->actingAs($user)->get('/incidents/export.csv?filter[status]=open');

    $response->assertOk()->assertDownload('incidents-2026-10-05.csv');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    $content = $response->streamedContent();
    // UTF-8 with BOM and CRLF, so spreadsheets read umlauts and rows alike; formula signs (also full-width) stay text.
    expect($content)->toStartWith("\xEF\xBB\xBFID,State,")->toContain("'\t=SUM(1,1)", "'＝HYPERLINK(")->not->toContain('Hidden recipient');
    $rows = array_map(str_getcsv(...), explode("\r\n", trim(substr($content, 3))));
    expect($rows)->toHaveCount(2)->and($rows[1][0])->toBe((string) $incident->id);
    $markdown = $this->get('/incidents/export.md?filter[status]=open')->assertOk()->assertDownload('incidents-2026-10-05.md')->streamedContent();
    expect($markdown)->toStartWith("# Incidents\n\n| ID | State | Site | Client | Check | Cause | Summary | Started | Resolved | Duration (seconds) |\n| ---: | --- |")
        ->toContain('| '.$incident->id.' | Open | ')->not->toContain('| '.$foreign->id.' |');
    $text = $this->get('/incidents/export.txt?filter[status]=open')->assertOk()->streamedContent();
    expect($text)->toStartWith("Incidents\n\nID {$incident->id}\nState: Open\nSite: \t=SUM(1,1)\n");
    $user->update(['locale' => 'de']);
    $german = $this->get('/incidents/export.csv?filter[status]=open')->assertDownload('stoerungen-2026-10-05.csv');
    $translated = array_map(str_getcsv(...), explode("\r\n", trim(substr($german->streamedContent(), 3))));
    expect($translated[0])->toBe(['ID', 'Zustand', 'Website', 'Kunde', 'Prüfung', 'Ursache', 'Zusammenfassung', 'Beginn', 'Behoben', 'Dauer (Sekunden)'])
        ->and($translated[1][1])->toBe('Offen')
        ->and(array_slice($translated[1], 4, 2))->toBe(['Erreichbarkeit', 'Fehlgeschlagen']);
    $this->get('/incidents/export.csv?filter[status]=invalid')->assertSessionHasErrors('filter.status');
    $this->get('/incidents/export.csv?per_page=7')->assertSessionHasErrors('per_page');
    $this->get('/incidents/export.pdf')->assertNotFound();
});

it('exports only This page of incidents when page and per_page are given', function (): void {
    $this->withoutVite();
    $owner = User::factory()->owner()->create();
    foreach (range(1, 12) as $minute) {
        $check = Check::factory()->create();
        Incident::factory()->for($check)->create([
            'site_id' => $check->site_id,
            'opened_at' => now()->subMinutes($minute),
            'first_failure_at' => now()->subMinutes($minute),
            'resolved_at' => null,
        ]);
    }
    $page2Ids = array_map(
        static fn (array $row): string => (string) $row['id'],
        $this->actingAs($owner)->get('/incidents?filter[status]=open&per_page=10&page=2')->assertOk()->inertiaProps()['incidents'],
    );
    expect($page2Ids)->toHaveCount(2);

    $csv = $this->get('/incidents/export.csv?filter[status]=open&per_page=10&page=2')->assertOk()->streamedContent();
    $exportedIds = array_map(
        static fn (string $line): string => str_getcsv($line, escape: '\\')[0] ?? '',
        array_slice(explode("\r\n", rtrim(substr($csv, 3), "\r\n")), 1),
    );
    expect($exportedIds)->toBe($page2Ids);
});

it('exports the first rows up to the row cap in text and refuses a CSV that would drop rows', function (): void {
    $this->withoutVite();
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $check = Check::factory()->create();
    $row = ['check_id' => $check->id, 'site_id' => $check->site_id, 'cause' => 'failed',
        'opened_at' => now(), 'first_failure_at' => now()->subMinute(), 'resolved_at' => now(),
        'evidence' => '{}', 'created_at' => now(), 'updated_at' => now()];
    for ($batch = 0; $batch < 10; $batch++) {
        DB::table('incidents')->insert(array_fill(0, 1000, $row));
    }
    DB::table('incidents')->insert($row);

    $owner->update(['locale' => 'de']);
    $text = $this->actingAs($owner)->get('/incidents/export.txt?filter[status]=all')->assertOk()->streamedContent();
    expect(substr_count($text, "\nID "))->toBe(10000)
        ->and($text)->toEndWith("\nEnthält die ersten 10.000 von 10.001 Zeilen.\n");
    $this->get('/incidents/export.csv?filter[status]=all')->assertStatus(413)
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('errors/show')
            ->where('status', 413)
            ->where('description', 'Ein CSV-Export enthält höchstens 10.000 Zeilen. Grenzen Sie die Filter ein.'));
});

it('renders only actual incident mail for operators using the mail renderer', function (): void {
    $this->withoutVite();
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $member = User::factory()->create();
    $member->assignRole('Member');
    // d4023e31 requires the retained opening observation for actual incident mail.
    $incident = Incident::factory()->create(['evidence' => [
        'summary' => 'causes.http', 'summary_params' => ['status' => 503], 'run_ended_at' => now()->toIso8601String(),
        'evidence' => ['status_code' => 503], 'metrics' => [],
    ]]);
    $incident->site->update(['friendly_name' => '<script>alert(1)</script>']);
    $event = Event::factory()->for($incident)->create(['type' => 'incident.opened', 'site_id' => $incident->site_id,
        'check_id' => $incident->check_id, 'to_result' => CheckResult::Failed, 'cause' => IncidentCause::Failed]);
    $foreign = Event::factory()->create();
    $address = 'berlin-alerts@example.test';
    // Team channels use the instance timezone since 2026-10-30.
    $settings = app(InstanceSettings::class);
    $settings->timezone = 'Europe/Berlin';
    $settings->save();
    $destination = NotificationDestination::factory()->create(['label' => 'Berlin desk', 'locale' => 'de',
        'config' => app(ChannelRegistry::class)->get('mail')->configSchema()->validateConfig(['email' => $address])]);
    $second = NotificationDestination::factory()->create(['locale' => 'en']);
    $this->actingAs($owner)->get('/incidents/'.$incident->id.'/alerts/'.$event->id)->assertNotFound();
    Delivery::query()->create(['event_id' => $event->id, 'destination_id' => $destination->id, 'state' => 'sent',
        'sent_at' => now(), 'next_attempt_at' => now(), 'suppression_evaluated_at' => now()]);
    Delivery::query()->create(['event_id' => $event->id, 'destination_id' => $second->id, 'state' => 'sent',
        'sent_at' => now(), 'next_attempt_at' => now(), 'suppression_evaluated_at' => now()]);
    $unsent = Event::factory()->for($incident)->create(['type' => 'incident.still_down', 'site_id' => $incident->site_id,
        'check_id' => $incident->check_id, 'to_result' => CheckResult::Failed, 'cause' => IncidentCause::Failed]);
    Delivery::query()->create(['event_id' => $unsent->id, 'destination_id' => $destination->id,
        'next_attempt_at' => now(), 'suppressed' => true, 'suppression_reason' => 'incident_snoozed', 'suppression_evaluated_at' => now()]);
    $this->get('/incidents/'.$incident->id.'/alerts/'.$unsent->id)->assertNotFound();
    $digest = Event::factory()->for($incident)->create(['type' => 'incident.still_down', 'site_id' => $incident->site_id,
        'check_id' => $incident->check_id, 'to_result' => CheckResult::Failed, 'cause' => IncidentCause::Failed]);
    Delivery::query()->create(['event_id' => $digest->id, 'destination_id' => $destination->id, 'state' => 'sent',
        'sent_at' => now(), 'next_attempt_at' => now(), 'suppression_evaluated_at' => now(), 'digest' => true]);
    $this->get('/incidents/'.$incident->id.'/alerts/'.$digest->id)->assertNotFound();

    $groups = collect($this->get('/incidents/'.$incident->id)->assertInertia(fn (Assert $page): Assert => $page
        ->where('alert_preview_available', true))->inertiaProps()['timeline'])->pluck('notification_group')->filter()->keyBy('event_type');
    // Only the first accepted delivery of the opener (the one the preview renders) offers the preview; never a digest.
    $opener = collect($this->get('/incidents/'.$incident->id.'?notification_group='.$groups['incident.opened']['id'])
        ->inertiaProps()['expanded_notifications']['deliveries'])->keyBy('id');
    $firstOpener = Delivery::query()->where('event_id', $event->id)->min('id');
    expect($opener)->toHaveCount(2)->and($opener[$firstOpener]['can_preview'])->toBeTrue()->and($opener[$firstOpener]['digest'])->toBeFalse()
        ->and($opener->except($firstOpener)->first()['can_preview'])->toBeFalse();
    $reminders = collect($this->get('/incidents/'.$incident->id.'?notification_group='.$groups['incident.still_down']['id'])
        ->inertiaProps()['expanded_notifications']['deliveries']);
    expect($reminders->firstWhere('event_id', $digest->id))->toMatchArray(['digest' => true, 'can_preview' => false]);
    $preview = $this->get('/incidents/'.$incident->id.'/alerts/'.$event->id)
        ->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertJsonPath('destination.address', $address)->assertJsonPath('destination.locale', 'de')
        ->assertJsonPath('destination.timezone', 'Europe/Berlin');
    expect($preview->json('subject'))->toContain('[Ausgefallen]')
        ->and($preview->json('html'))->not->toContain('<script>alert(1)</script>')
        ->and($preview->headers->has('X-Alert-Subject'))->toBeFalse();
    $this->get('/incidents/'.$incident->id.'/alerts/'.$foreign->id)->assertNotFound();
    $this->actingAs($member)->get('/incidents/'.$incident->id)->assertInertia(fn (Assert $page): Assert => $page->where('alert_preview_available', false));
    $this->get('/incidents/'.$incident->id.'/alerts/'.$event->id)->assertForbidden();
    $binding = app()->getBindings()[AlertPreviewRenderer::class];
    app()->offsetUnset(AlertPreviewRenderer::class);
    try {
        $this->actingAs($owner)->get('/incidents/'.$incident->id)->assertInertia(fn (Assert $page): Assert => $page->where('alert_preview_available', false));
        $this->get('/incidents/'.$incident->id.'/alerts/'.$event->id)->assertNotFound();
    } finally {
        app()->bind(AlertPreviewRenderer::class, $binding['concrete']);
    }
});

it('filters whole viewer-local days with a half-open end across DST and per viewer timezone', function (): void {
    $this->withoutVite();
    $settings = app(InstanceSettings::class);
    $settings->timezone = 'UTC';
    $settings->save();
    $berlin = User::factory()->create(['timezone' => 'Europe/Berlin']);
    $berlin->assignRole('Owner');
    $newYork = User::factory()->create(['timezone' => 'America/New_York']);
    $newYork->assignRole('Owner');
    $check = Check::factory()->create();
    // 2026-10-25 in Europe/Berlin (fall-back DST day) is [2026-10-24T22:00:00Z, 2026-10-25T23:00:00Z).
    // 2026-10-25T03:30:00Z is 2026-10-24 23:30 in America/New_York and 2026-10-25 05:30 in Europe/Berlin.
    $times = ['2026-10-24T21:59:59Z', '2026-10-24T22:00:00Z', '2026-10-25T03:30:00Z', '2026-10-25T22:59:59Z', '2026-10-25T23:00:00Z'];
    $incidents = [];
    foreach ($times as $time) {
        $incidents[] = Incident::factory()->for($check)->create(['first_failure_at' => $time, 'opened_at' => $time, 'resolved_at' => CarbonImmutable::parse($time)->addSecond()]);
    }

    $this->actingAs($berlin)->get('/incidents?filter[status]=all&filter[started_after]=2026-10-25&filter[started_before]=2026-10-25')->assertInertia(fn (Assert $page): Assert => $page
        ->has('incidents', 3)
        ->where('incidents.0.id', $incidents[3]->id)
        ->where('incidents.1.id', $incidents[2]->id)
        ->where('incidents.2.id', $incidents[1]->id));
    $this->get('/incidents?filter[status]=all&filter[started_before]=2026-10-25')->assertInertia(fn (Assert $page): Assert => $page->has('incidents', 4));

    // For a New York viewer, 2026-10-25T03:30:00Z belongs to 2026-10-24, not 2026-10-25.
    $this->actingAs($newYork)->get('/incidents?filter[status]=all&filter[started_after]=2026-10-24&filter[started_before]=2026-10-24')->assertInertia(fn (Assert $page): Assert => $page
        ->has('incidents', 3)
        ->where('incidents.0.id', $incidents[2]->id)
        ->where('incidents.1.id', $incidents[1]->id)
        ->where('incidents.2.id', $incidents[0]->id));
    $this->get('/incidents?filter[status]=all&filter[started_after]=2026-10-25&filter[started_before]=2026-10-25')->assertInertia(fn (Assert $page): Assert => $page
        ->has('incidents', 2)
        ->where('incidents.0.id', $incidents[4]->id)
        ->where('incidents.1.id', $incidents[3]->id));
});

it('links each fixed incident filter to a URL that removes only that filter', function (): void {
    $user = User::factory()->owner()->create(['timezone' => 'Europe/Berlin']);
    $filters = ['status' => 'all', 'cause' => 'errored', 'check' => 'dns',
        'started_after' => '2026-10-25', 'started_before' => '2026-10-26T04:15'];

    $response = $this->actingAs($user)->get('/incidents?'.http_build_query(['filter' => $filters, 'page' => 2]))->assertOk();
    $response->assertInertia(fn (Assert $page): Assert => $page
        ->where('filters.started_after.display', '2026-10-25T00:00:00+02:00')
        ->where('filters.started_before.display', '2026-10-26T04:15:00+01:00'));
    foreach (['cause', 'started_after', 'started_before'] as $key) {
        $remaining = $filters;
        unset($remaining[$key]);
        $url = $response->inertiaProps('filters.'.$key.'.clear_url');
        expect($url)->toBe('/incidents?'.http_build_query(['filter' => $remaining]));
        $this->get($url)->assertInertia(fn (Assert $page): Assert => $page
            ->where('filters.'.$key.'.value', null));
    }
});

it('exports a stable incident snapshot without skipping or duplicating rows when incidents resolve between chunks', function (): void {
    $owner = User::factory()->owner()->create();
    $client = Client::factory()->create();
    $base = CarbonImmutable::parse('2026-10-05T12:00:00Z');
    $siteRows = [];
    for ($index = 0; $index < 505; $index++) {
        $siteRows[] = ['client_id' => $client->id, 'url' => "https://export-snap-{$index}.example.test", 'friendly_name' => "Snap {$index}",
            'badge_id' => (string) Str::uuid(), 'created_at' => $base, 'updated_at' => $base];
    }
    DB::table('sites')->insert($siteRows);
    $siteIds = DB::table('sites')->where('client_id', $client->id)->orderBy('id')->pluck('id')->all();
    $checkRows = array_map(static fn (int $siteId): array => [
        'site_id' => $siteId, 'type' => CheckKey::Uptime->value, 'enabled' => true, 'interval_seconds' => 60,
        'failure_threshold' => 1, 'latest_run_result' => 'failed', 'created_at' => $base, 'updated_at' => $base,
    ], $siteIds);
    DB::table('checks')->insert($checkRows);
    $checks = DB::table('checks')->whereIn('site_id', $siteIds)->orderBy('id')->get(['id', 'site_id']);
    $rows = [];
    foreach ($checks as $index => $check) {
        $at = $base->subMinutes($index);
        $rows[] = ['check_id' => $check->id, 'site_id' => $check->site_id, 'cause' => 'failed',
            'opened_at' => $at, 'first_failure_at' => $at, 'resolved_at' => null,
            'evidence' => '{}', 'created_at' => $at, 'updated_at' => $at];
    }
    DB::table('incidents')->insert($rows);
    $expectedIds = DB::table('incidents')->whereNull('resolved_at')->orderByDesc('first_failure_at')->orderByDesc('id')->pluck('id')->map(strval(...))->all();
    expect($expectedIds)->toHaveCount(505);

    $mutated = false;
    DB::listen(static function (QueryExecuted $query) use (&$mutated, $expectedIds): void {
        if (! $mutated && str_contains($query->sql, 'from "incidents"') && (str_contains($query->sql, 'limit 500') || str_contains($query->sql, 'where "id" in ('))) {
            $mutated = true;
            DB::table('incidents')->where('id', (int) $expectedIds[0])->update(['resolved_at' => now()]);
        }
    });

    $csv = $this->actingAs($owner)->get('/incidents/export.csv?filter[status]=open')->assertOk()->streamedContent();
    $exportedIds = array_map(
        static fn (string $line): string => str_getcsv($line, escape: '\\')[0] ?? '',
        array_slice(explode("\r\n", rtrim(substr($csv, 3), "\r\n")), 1),
    );

    expect($mutated)->toBeTrue()
        ->and($exportedIds)->toBe($expectedIds);
});

it('decides the CSV row-limit refusal from the same snapshot as the exported IDs', function (): void {
    $this->withoutVite();
    $owner = User::factory()->owner()->create();
    $check = Check::factory()->create();
    $row = ['check_id' => $check->id, 'site_id' => $check->site_id, 'cause' => 'failed',
        'opened_at' => now(), 'first_failure_at' => now()->subMinute(), 'resolved_at' => now(),
        'evidence' => '{}', 'created_at' => now(), 'updated_at' => now()];
    for ($batch = 0; $batch < 10; $batch++) {
        DB::table('incidents')->insert(array_fill(0, 1000, $row));
    }
    DB::table('incidents')->insert([$row, $row]);

    $trimmed = false;
    DB::listen(static function (QueryExecuted $query) use (&$trimmed): void {
        if (! $trimmed && str_contains($query->sql, 'from "incidents"') && str_contains($query->sql, 'limit 10001')) {
            $trimmed = true;
            DB::table('incidents')->whereIn('id', DB::table('incidents')->orderByDesc('id')->limit(3)->pluck('id'))->delete();
        }
    });

    // Even if 3 rows disappear after the 10,001-ID snapshot, CSV must refuse 413 rather than serving a capped list.
    $this->actingAs($owner)->get('/incidents/export.csv?filter[status]=all')->assertStatus(413);
    expect($trimmed)->toBeTrue();
});

it('keeps incident export cap, count and chunk reads on one repeatable-read snapshot across a separate writer connection', function (): void {
    $this->withoutVite();
    $roleIds = Role::query()->pluck('id')->all();
    $owner = User::factory()->owner()->create();
    $check = Check::factory()->create();
    $site = $check->site;
    $client = $site->client;
    $row = ['check_id' => $check->id, 'site_id' => $check->site_id, 'cause' => 'failed',
        'opened_at' => now(), 'first_failure_at' => now()->subMinute(), 'resolved_at' => now(),
        'evidence' => '{}', 'created_at' => now(), 'updated_at' => now()];
    for ($batch = 0; $batch < 10; $batch++) {
        DB::table('incidents')->insert(array_fill(0, 1000, $row));
    }
    DB::table('incidents')->insert([$row, $row]);
    $firstThreeIds = DB::table('incidents')->where('check_id', $check->id)->orderByDesc('opened_at')->orderByDesc('id')->limit(3)->pluck('id')->all();
    DB::commit();

    config(['database.connections.pgsql_writer' => config('database.connections.pgsql')]);
    $deletedByWriter = false;
    DB::listen(static function (QueryExecuted $query) use (&$deletedByWriter, $firstThreeIds): void {
        if (! $deletedByWriter && $query->connectionName === 'pgsql' && str_contains($query->sql, 'from "incidents"') && str_contains($query->sql, 'limit 10001')) {
            $deletedByWriter = true;
            DB::connection('pgsql_writer')->table('incidents')->whereIn('id', $firstThreeIds)->delete();
        }
    });

    try {
        $text = $this->actingAs($owner)->get('/incidents/export.txt?filter[status]=all')->assertOk()->streamedContent();
        expect($deletedByWriter)->toBeTrue()
            ->and(substr_count($text, "\nID "))->toBe(10000)
            ->and($text)->toContain("\nID {$firstThreeIds[0]}\n")
            ->and($text)->toEndWith("\nHolds the first 10,000 of 10,002 rows.\n");
        expect(DB::table('incidents')->where('check_id', $check->id)->count())->toBe(9999);
    } finally {
        DB::purge('pgsql_writer');
        DB::table('incidents')->where('check_id', $check->id)->delete();
        $check->delete();
        $site->delete();
        $client->delete();
        $owner->delete();
        Role::query()->whereNotIn('id', $roleIds)->delete();
        DB::beginTransaction();
    }
});
