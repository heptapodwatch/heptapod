<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\CronTaskStore;
use Heptapod\Contracts\RunResult;
use Heptapod\Http\ListPageSize;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Client;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

// Lists that share their page with other content page through ListPaging: `<list>_page`, `<list>_per_page` and the
// remembered size per list, the same sizes as the major lists (ListPageSizeTest).

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04T12:00:00Z'));
    $this->withoutVite();
});

it('pages a site overview\'s events newest first and remembers the size', function (): void {
    $owner = User::factory()->owner()->create();
    $check = Check::factory()->for(Site::factory()->for(Client::factory()))->create();
    $events = collect(range(1, 12))->map(fn (int $minute): Event => Event::factory()->for($check)->create([
        'occurred_at' => now()->subMinutes($minute), 'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Succeeded,
        'type' => 'site.paused']));
    $path = '/sites/'.$check->site_id;

    $this->actingAs($owner)->get($path)->assertInertia(fn (Assert $page): Assert => $page
        ->has('events', 12)->where('events_pagination.total', 12)->where('events_pagination.page_name', 'site_events_page'));
    $this->get($path.'?site_events_per_page=10')->assertCookie(ListPageSize::Cookie, json_encode(['site_events' => 10]), false)
        ->assertInertia(fn (Assert $page): Assert => $page->has('events', 10)->where('events.0.id', $events[0]->id)->where('events_pagination.last_page', 2));

    $this->withUnencryptedCookie(ListPageSize::Cookie, json_encode(['site_events' => 10]));
    $this->get($path.'?site_events_page=2')->assertInertia(fn (Assert $page): Assert => $page
        ->has('events', 2)->where('events.0.id', $events[10]->id)->where('events.1.id', $events[11]->id));
    $this->get($path.'?site_events_per_page=7')->assertSessionHasErrors('site_events_per_page');

    // Paging never widens access: another client's member still cannot open the site.
    $this->actingAs(User::factory()->create())->get($path.'?site_events_page=2')->assertForbidden();
});

it('pages one Activity group\'s own events', function (): void {
    $owner = User::factory()->owner()->create();
    $check = Check::factory()->for(Site::factory()->for(Client::factory()))->create();
    // The outbox assigns flap groups when it records a transition; these alternating transitions carry theirs directly.
    $group = null;
    foreach (range(12, 1) as $minute) {
        $failed = $minute % 2 === 0;
        $group ??= Event::factory()->for($check)->create(['occurred_at' => now()->subMinutes($minute),
            'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Failed])->id;
        if ($minute < 12) {
            Event::factory()->for($check)->create(['occurred_at' => now()->subMinutes($minute), 'flap_group_id' => $group,
                'from_result' => $failed ? CheckResult::Succeeded : CheckResult::Failed, 'to_result' => $failed ? CheckResult::Failed : CheckResult::Succeeded]);
        }
    }
    $members = Event::query()->where('flap_group_id', $group)->orWhere('id', $group)->orderByDesc('occurred_at')->pluck('id');
    expect($members)->toHaveCount(12);

    $this->actingAs($owner)->get('/activity?group='.$group.'&activity_events_per_page=10')->assertInertia(fn (Assert $page): Assert => $page
        ->has('expanded_events', 10)->where('expanded_events.0.id', $members[0])
        ->where('expanded_pagination.total', 12)->where('expanded_pagination.last_page', 2));
    $this->get('/activity?group='.$group.'&activity_events_page=2&activity_events_per_page=10')->assertInertia(fn (Assert $page): Assert => $page
        ->has('expanded_events', 2)->where('expanded_events.0.id', $members[10])->where('expanded_events.1.id', $members[11]));
});

it('pages crawl check tab findings and not-verifiable rows, respects the cookie and keeps permissions unchanged', function (): void {
    $owner = User::factory()->owner()->create();
    $site = Site::factory()->for(Client::factory())->create(['url' => 'https://shop.example.test']);
    $check = Check::factory()->for($site)->create(['type' => CheckKey::BrokenLinks, 'latest_run_result' => CheckResult::Failed]);
    $run = CheckRun::factory()->for($check)->create(['result' => RunResult::Failed, 'started_at' => now()->subMinutes(2), 'ended_at' => now()->subMinute()]);
    $check->update(['latest_run_id' => $run->id, 'latest_run_ended_at' => $run->ended_at]);
    $snapshotId = DB::table('crawl_snapshots')->insertGetId([
        'site_id' => $site->id, 'check_id' => $check->id, 'run_id' => $run->id,
        'claim_token' => '00000000-0000-4000-8000-000000000010', 'run_ended_at' => $run->ended_at, 'collected_at' => $run->ended_at,
        'settings' => '{}', 'truncated' => false, 'observed_urls' => '[]', 'observed_pages' => '[]', 'discovered_urls' => '[]',
        'discovery_complete' => true, 'evaluated_finding_ids' => '[]', 'site_url' => $site->url, 'target_fingerprint' => str_repeat('a', 64),
        'classification_policy_version' => null,
    ]);
    foreach (range(1, 12) as $i) {
        DB::table('crawl_findings')->insert([
            'snapshot_id' => $snapshotId, 'crawled_url' => "https://shop.example.test/broken-{$i}",
            'relative_crawled_url' => "/broken-{$i}", 'status_code' => 404,
            'found_on_url' => 'https://shop.example.test/', 'type' => 'link', 'internal' => true,
        ]);
        DB::table('crawl_findings')->insert([
            'snapshot_id' => $snapshotId, 'crawled_url' => "https://shop.example.test/blocked-{$i}",
            'relative_crawled_url' => "/blocked-{$i}", 'status_code' => 403, 'error_message' => 'bot_protection',
            'found_on_url' => 'https://shop.example.test/', 'type' => 'link', 'internal' => true,
        ]);
    }
    $path = '/sites/'.$site->id.'/checks/'.$check->id;

    $first = $this->actingAs($owner)->get($path.'?findings_per_page=10&not_verifiable_per_page=10')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('history.findings', 10)
            ->where('history.findings.0.url', 'https://shop.example.test/broken-1')
            ->where('history.findings_pagination.total', 12)
            ->where('history.findings_pagination.last_page', 2)
            ->where('history.findings_pagination.page_name', 'findings_page')
            ->has('history.not_verifiable', 10)
            ->where('history.not_verifiable.0.url', 'https://shop.example.test/blocked-1')
            ->where('history.not_verifiable_pagination.total', 12)
            ->where('history.not_verifiable_pagination.last_page', 2)
            ->where('history.not_verifiable_pagination.page_name', 'not_verifiable_page'));
    expect(json_decode((string) $first->getCookie(ListPageSize::Cookie, false)?->getValue(), true))
        ->toBe(['findings' => 10, 'not_verifiable' => 10]);

    $this->withUnencryptedCookie(ListPageSize::Cookie, json_encode(['findings' => 10, 'not_verifiable' => 10]));
    $this->get($path.'?findings_page=2&not_verifiable_page=2')->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('history.findings', 2)
            ->where('history.findings.0.url', 'https://shop.example.test/broken-11')
            ->where('history.findings.1.url', 'https://shop.example.test/broken-12')
            ->has('history.not_verifiable', 2)
            ->where('history.not_verifiable.0.url', 'https://shop.example.test/blocked-11')
            ->where('history.not_verifiable.1.url', 'https://shop.example.test/blocked-12'));
    $this->get($path.'?findings_per_page=7')->assertSessionHasErrors('findings_per_page');
    $this->actingAs(User::factory()->create())->get($path.'?findings_page=2')->assertForbidden();
});

it('pages scheduled tasks on the cron check tab, respects the cookie and keeps permissions unchanged', function (): void {
    $owner = User::factory()->owner()->create();
    $site = Site::factory()->for(Client::factory())->create();
    $check = Check::factory()->for($site)->create(['type' => CheckKey::Cron]);
    $store = app(CronTaskStore::class);
    foreach (range(1, 12) as $i) {
        $store->create($check->id, ['name' => 'Task '.$i, 'type' => 'simple', 'frequency_in_minutes' => 60]);
    }
    $path = '/sites/'.$site->id.'/checks/'.$check->id;

    $this->actingAs($owner)->get($path.'?cron_tasks_per_page=10')
        ->assertOk()
        ->assertCookie(ListPageSize::Cookie, json_encode(['cron_tasks' => 10]), false)
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('history.tasks', 10)
            ->where('history.tasks.0.name', 'Task 1')
            ->where('history.tasks_pagination.total', 12)
            ->where('history.tasks_pagination.last_page', 2)
            ->where('history.tasks_pagination.page_name', 'cron_tasks_page'));

    $this->withUnencryptedCookie(ListPageSize::Cookie, json_encode(['cron_tasks' => 10]));
    $this->get($path.'?cron_tasks_page=2')->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('history.tasks', 2)
            ->where('history.tasks.0.name', 'Task 11')
            ->where('history.tasks.1.name', 'Task 12'));
    $this->get($path.'?cron_tasks_per_page=7')->assertSessionHasErrors('cron_tasks_per_page');
    $this->actingAs(User::factory()->create())->get($path.'?cron_tasks_page=2')->assertForbidden();
});

it('pages application health components on the check tab, respects the cookie and keeps permissions unchanged', function (): void {
    $owner = User::factory()->owner()->create();
    $site = Site::factory()->for(Client::factory())->create();
    $check = Check::factory()->for($site)->create(['type' => CheckKey::ApplicationHealth, 'latest_run_result' => CheckResult::Succeeded]);
    $run = CheckRun::factory()->for($check)->create(['result' => RunResult::Succeeded, 'started_at' => now()->subMinutes(2), 'ended_at' => now()->subMinute()]);
    $check->update(['latest_run_id' => $run->id, 'latest_run_ended_at' => $run->ended_at]);
    DB::table('application_health_snapshots')->insert([
        'check_id' => $check->id, 'run_id' => $run->id, 'claim_token' => '00000000-0000-4000-8000-000000000020',
        'run_ended_at' => $run->ended_at, 'components' => '[]', 'created_at' => $run->ended_at,
    ]);
    foreach (range(1, 12) as $i) {
        DB::table('application_health_components')->insert([
            'check_id' => $check->id, 'source_key' => 'comp-'.$i, 'active' => true,
            'snapshot' => json_encode(['name' => 'Component '.$i, 'label' => 'Component '.$i, 'status' => 'ok', 'shortSummary' => 'OK', 'latest_result' => 'ok'], JSON_THROW_ON_ERROR),
            'run_id' => $run->id, 'run_ended_at' => $run->ended_at, 'detected_at' => $run->ended_at,
        ]);
    }
    $path = '/sites/'.$site->id.'/checks/'.$check->id;

    $this->actingAs($owner)->get($path.'?health_components_per_page=10')
        ->assertOk()
        ->assertCookie(ListPageSize::Cookie, json_encode(['health_components' => 10]), false)
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('history.components', 10)
            ->where('history.components.0.name', 'Component 1')
            ->where('history.components_pagination.total', 12)
            ->where('history.components_pagination.last_page', 2)
            ->where('history.components_pagination.page_name', 'health_components_page'));

    $this->withUnencryptedCookie(ListPageSize::Cookie, json_encode(['health_components' => 10]));
    $this->get($path.'?health_components_page=2')->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('history.components', 2)
            ->where('history.components.0.name', 'Component 11')
            ->where('history.components.1.name', 'Component 12'));
    $this->get($path.'?health_components_per_page=7')->assertSessionHasErrors('health_components_per_page');
    $this->actingAs(User::factory()->create())->get($path.'?health_components_page=2')->assertForbidden();
});

it('pages uptime recent failures newest first, respects the cookie and keeps permissions unchanged', function (): void {
    $owner = User::factory()->owner()->create();
    $site = Site::factory()->for(Client::factory())->create();
    $check = Check::factory()->for($site)->create(['type' => CheckKey::Uptime, 'latest_run_result' => CheckResult::Succeeded]);
    $runs = collect(range(1, 12))->map(fn (int $minute): CheckRun => CheckRun::factory()->for($check)->create([
        'result' => RunResult::Failed,
        'started_at' => now()->subMinutes($minute)->subSecond(),
        'ended_at' => now()->subMinutes($minute),
        'evidence' => ['http_status' => 500 + $minute],
    ]));
    $path = '/sites/'.$site->id.'/checks/'.$check->id;

    $this->actingAs($owner)->get($path.'?uptime_failures_per_page=10')
        ->assertOk()
        ->assertCookie(ListPageSize::Cookie, json_encode(['uptime_failures' => 10]), false)
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('history.recent_failures', 10)
            ->where('history.recent_failures.0.run_id', $runs[0]->id)
            ->where('history.recent_failures_pagination.total', 12)
            ->where('history.recent_failures_pagination.last_page', 2)
            ->where('history.recent_failures_pagination.page_name', 'uptime_failures_page'));

    $this->withUnencryptedCookie(ListPageSize::Cookie, json_encode(['uptime_failures' => 10]));
    $this->get($path.'?uptime_failures_page=2')->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('history.recent_failures', 2)
            ->where('history.recent_failures.0.run_id', $runs[10]->id)
            ->where('history.recent_failures.1.run_id', $runs[11]->id));
    $this->get($path.'?uptime_failures_per_page=7')->assertSessionHasErrors('uptime_failures_per_page');
    $this->actingAs(User::factory()->create())->get($path.'?uptime_failures_page=2')->assertForbidden();
});

it('preserves microsecond precision for open uptime incident failure paging', function (): void {
    $owner = User::factory()->owner()->create();
    $site = Site::factory()->for(Client::factory())->create();
    $check = Check::factory()->for($site)->create(['type' => CheckKey::Uptime, 'latest_run_result' => CheckResult::Failed]);
    $cutoff = CarbonImmutable::parse('2026-10-04T11:55:00.500000Z');
    // Failure 400ms before the incident cutoff in the exact same second must never enter the incident failure list.
    $beforeCutoff = CheckRun::factory()->for($check)->create([
        'result' => RunResult::Failed,
        'started_at' => $cutoff->subMicroseconds(500000),
        'ended_at' => $cutoff->subMicroseconds(400000),
        'evidence' => ['status_code' => 502],
    ]);
    $inIncident = collect(range(0, 11))->map(fn (int $step): CheckRun => CheckRun::factory()->for($check)->create([
        'result' => RunResult::Failed,
        'started_at' => $cutoff->addSeconds($step)->subMicroseconds(100000),
        'ended_at' => $cutoff->addSeconds($step),
        'evidence' => ['status_code' => 503],
    ]));
    $incident = Incident::factory()->for($check)->create([
        'site_id' => $site->id,
        'first_failure_at' => $cutoff,
        'opened_at' => $cutoff->addSecond(),
        'resolved_at' => null,
    ]);

    $this->actingAs($owner)->get('/incidents/'.$incident->id.'?uptime_failures_per_page=10&uptime_failures_page=2')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('current_findings.recent_failures_pagination.total', 12)
            ->has('current_findings.recent_failures', 2)
            ->where('current_findings.recent_failures.0.run_id', $inIncident[1]->id)
            ->where('current_findings.recent_failures.1.run_id', $inIncident[0]->id));

    $allIds = [
        ...array_column($this->get('/incidents/'.$incident->id.'?uptime_failures_per_page=10&uptime_failures_page=1')->inertiaProps()['current_findings']['recent_failures'], 'run_id'),
        ...array_column($this->get('/incidents/'.$incident->id.'?uptime_failures_per_page=10&uptime_failures_page=2')->inertiaProps()['current_findings']['recent_failures'], 'run_id'),
    ];
    expect($allIds)->not->toContain($beforeCutoff->id)->toHaveCount(12);
});
