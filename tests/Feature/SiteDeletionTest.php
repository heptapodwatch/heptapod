<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Checks\Execution\ClaimService;
use Heptapod\Checks\Execution\RunOrigin;
use Heptapod\Checks\Recording\RecordableRun;
use Heptapod\Checks\Recording\TransactionalResultRecorder;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\CronTaskStore;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Enums\IncidentResolutionReason;
use Heptapod\Jobs\PurgeDeletedSite;
use Heptapod\Jobs\RunCheck;
use Heptapod\Models\Check;
use Heptapod\Models\Client;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\Site;
use Heptapod\Models\SystemIssue;
use Heptapod\Models\User;
use Heptapod\SystemHealth\SystemIssueKind;
use Heptapod\Testing\ClaimedRun;
use Heptapod\Testing\FakeOutboundHttp;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-03T12:00:00Z'));
    $this->withoutVite();
    Queue::fake();
    app()->instance(OutboundHttp::class, new FakeOutboundHttp);
});

/**
 * A site with an open outage, its opening event, $runs uptime runs every 15 minutes back from now (2,500 reach into the
 * September partition), each with its attempt.
 */
function siteWithHistory(int $runs): Site
{
    $client = Client::factory()->create(['name' => 'Purge client']);
    $site = Site::factory()->for($client)->create(['url' => 'https://purge.example.test', 'friendly_name' => 'Purge shop']);
    $check = Check::factory()->for($site)->create(['type' => CheckKey::Uptime, 'latest_run_result' => CheckResult::Failed]);
    $incident = Incident::factory()->for($check)->create(['opened_at' => now()->subMinutes(10)]);
    Event::factory()->for($check)->create(['type' => 'incident.opened', 'incident_id' => $incident->id, 'occurred_at' => now()->subMinutes(10)]);
    DB::insert(<<<'SQL'
        INSERT INTO check_runs (check_id, check_type, result, started_at, ended_at, summary, metrics)
        SELECT ?, 'uptime', 'succeeded', ended_at - interval '1 second', ended_at, 'checks.uptime.ok', '{}'::jsonb
        FROM (SELECT ?::timestamptz - n * interval '15 minutes' AS ended_at FROM generate_series(1, ?) n) runs
        SQL, [$check->id, now()->toIso8601String(), $runs]);
    DB::insert(<<<'SQL'
        INSERT INTO check_run_attempts (check_id, check_run_id, claim_token, phase, queue, requested_at, finished_at)
        SELECT check_id, id, gen_random_uuid()::text, 'done', 'checks-fast', ended_at, ended_at FROM check_runs WHERE check_id = ?
        SQL, [$check->id]);

    return $site;
}

/**
 * Every row that still names the site or one of its checks, per foreign key the schema declares on sites or checks.
 *
 * @param  list<int>  $checkIds
 * @return array<string, int>
 */
function rowsNamingSite(int $siteId, array $checkIds): array
{
    $rows = [];
    foreach (DB::select(<<<'SQL'
        SELECT c.conrelid::regclass::text AS child, a.attname AS fk, c.confrelid::regclass::text AS parent
        FROM pg_constraint c JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = c.conkey[1]
        WHERE c.contype = 'f' AND c.confrelid IN ('sites'::regclass, 'checks'::regclass) AND c.conparentid = 0
        SQL) as $foreignKey) {
        $rows[$foreignKey->child.'.'.$foreignKey->fk] = DB::table($foreignKey->child)
            ->whereIn($foreignKey->fk, $foreignKey->parent === 'sites' ? [$siteId] : $checkIds)->count();
    }
    $rows['sites'] = DB::table('sites')->where('id', $siteId)->count();

    return array_filter($rows);
}

/** One purge slice as a queue worker runs it: the uniqueness of a queued slice ends when the worker starts it. */
function purgeSlice(int $siteId, int $seconds = PurgeDeletedSite::SliceSeconds): void
{
    $slice = new PurgeDeletedSite($siteId, $seconds);
    new UniqueLock(app(Repository::class))->release($slice);
    app()->call($slice->handle(...));
}

it('pauses a site at once when its deletion is requested, shows it as deleting and does not resume it', function (): void {
    $owner = User::factory()->owner()->create();
    $site = siteWithHistory(10);
    $incident = Incident::query()->sole();

    $this->actingAs($owner)->delete('/sites/'.$site->id, ['name' => 'Purge shop'])->assertRedirect('/sites');

    $site->refresh();
    Queue::assertPushed(PurgeDeletedSite::class, fn (PurgeDeletedSite $job): bool => $job->siteId === $site->id);
    expect($site->paused_at)->not->toBeNull()
        ->and($site->marked_for_deletion_at)->not->toBeNull()
        ->and($site->resume_at)->toBeNull()
        ->and(DB::table('check_runs')->whereIn('check_id', $site->checks()->select('id'))->count())->toBe(10)
        ->and($incident->refresh()->resolved_at)->not->toBeNull()
        ->and(DB::table('activity_log')->where('event', 'incident.resolved')->where('subject_id', $incident->id)->value('properties'))
        ->toContain(IncidentResolutionReason::SiteDeleted->value);
    $this->get('/sites/'.$site->id)->assertInertia(fn (Assert $page): Assert => $page->where('site.identity.deleting', true)
        ->where('site.identity.state', 'paused'));
    $this->get('/sites')->assertInertia(fn (Assert $page): Assert => $page
        ->loadDeferredProps(['list'], fn (Assert $list): Assert => $list->where('sites.0.identity.deleting', true)));

    $this->post('/sites/'.$site->id.'/resume')->assertRedirect();
    expect($site->refresh()->paused_at)->not->toBeNull();
});

it('requests the deletion through the API as the web does', function (): void {
    $owner = User::factory()->owner()->create();
    $site = siteWithHistory(10);
    $token = $owner->createToken('write', ['*'])->plainTextToken;

    $this->withToken($token)->deleteJson('/api/monitors/'.$site->id)->assertNoContent();

    Queue::assertPushed(PurgeDeletedSite::class, fn (PurgeDeletedSite $job): bool => $job->siteId === $site->id);
    $this->withToken($token)->getJson('/api/monitors/'.$site->id)->assertOk()->assertJsonPath('marked_for_deletion_at', '2026-10-03 12:00:00');
});

it('shrinks the history in batches, then deletes the site as DeleteSite does with a small remainder', function (): void {
    $owner = User::factory()->owner()->create();
    $site = siteWithHistory(2500);
    // An addon's history, two levels below the check: 1,500 pings of one cron task.
    $cron = Check::factory()->for($site)->create(['type' => CheckKey::Cron]);
    $task = app(CronTaskStore::class)->create($cron->id, ['name' => 'Backup', 'type' => 'simple', 'frequency_in_minutes' => 60]);
    DB::insert(<<<'SQL'
        INSERT INTO cron_pings (cron_check_id, phase, ping_revision, result, meta, received_at)
        SELECT ?, 'finished', n, 'finished', '{}'::jsonb, ?::timestamptz - n * interval '1 minute' FROM generate_series(1, 1500) n
        SQL, [$task->id, now()->toIso8601String()]);
    /** @var list<int> $checkIds */
    $checkIds = $site->checks()->pluck('id')->all();
    $this->actingAs($owner)->delete('/sites/'.$site->id, ['name' => 'Purge shop'])->assertRedirect('/sites');
    $history = static fn (): array => [...rowsNamingSite($site->id, $checkIds), 'cron_pings' => DB::table('cron_pings')->where('cron_check_id', $task->id)->count()];
    expect($history())->toHaveKeys(['check_runs.check_id', 'check_run_attempts.check_id', 'list_response_buckets.check_id'])
        ->and($history()['cron_pings'])->toBe(1500)
        ->and(DB::table('check_runs')->whereIn('check_id', $checkIds)->distinct()->count('tableoid'))->toBe(2);
    // What the cascade of DeleteSite finds, read just before it runs.
    $remainder = null;
    DB::beforeExecuting(static function (string $query) use (&$remainder, $history): void {
        if ($remainder === null && str_starts_with($query, 'delete from "sites"')) {
            $remainder = $history();
        }
    });

    // A slice without time left stops after its first rows: each one takes a single batch, the last one runs DeleteSite.
    $slices = 0;
    do {
        $before = array_sum($history());
        purgeSlice($site->id, seconds: 0);
        $slices++;
        expect($before - array_sum($history()))->toBeGreaterThan(0)->toBeLessThanOrEqual(PurgeDeletedSite::PurgeBatch + 10);
    } while (DB::table('sites')->where('id', $site->id)->exists() && $slices < 50);

    expect($history())->toBe(['cron_pings' => 0])
        ->and($remainder)->not->toBeNull()
        ->and(max($remainder))->toBeLessThanOrEqual(3)
        ->and(DB::table('activity_log')->where('event', 'deleted')->where('subject_type', $site->getMorphClass())->where('subject_id', $site->id)->count())->toBe(1)
        // DeleteSite's detachment: the history stays as deleted-site history.
        ->and(DB::table('events')->where('client_id', $site->client_id)->whereNull('site_id')->count())->toBe(2);
    // The request queued the first slice; every slice but the last queued the next.
    Queue::assertPushed(PurgeDeletedSite::class, $slices);
});

it('lets a worker queued or running before the deletion request record nothing, during and after the purge', function (): void {
    $owner = User::factory()->owner()->create();
    $site = siteWithHistory(10);
    $uptime = $site->checks()->sole();
    $performance = Check::factory()->for($site)->create(['type' => CheckKey::Performance]);
    $running = ClaimedRun::start($uptime);
    expect(app(ClaimService::class)->runNow($performance->id, RunOrigin::System))->not->toBeNull();
    $definition = app(CheckRegistry::class)->get(CheckKey::Performance)->definition();
    $queued = new RunCheck($performance->id, $performance->refresh()->claim_token ?? '', $definition->timeout, $definition->queue);
    $this->actingAs($owner)->delete('/sites/'.$site->id, ['name' => 'Purge shop'])->assertRedirect('/sites');

    // The first slice shrinks the uptime check's attempts but keeps the one its claim still names.
    purgeSlice($site->id, seconds: 0);
    $runs = DB::table('check_runs')->count();
    $events = DB::table('events')->count();
    $recorded = app(TransactionalResultRecorder::class)->record(new RecordableRun($uptime->id, $running->claimToken, $running->startedAt, now()->toImmutable(), null));
    app()->call($queued->handle(...));

    expect(DB::table('check_run_attempts')->where('check_id', $uptime->id)->pluck('claim_token')->all())->toBe([$running->claimToken])
        ->and($recorded->accepted)->toBeFalse()
        ->and(app(OutboundHttp::class)->requests)->toBe([])
        ->and(DB::table('check_runs')->count())->toBe($runs)
        ->and(DB::table('events')->count())->toBe($events);

    purgeSlice($site->id);

    expect(Site::query()->find($site->id))->toBeNull()
        ->and(app(TransactionalResultRecorder::class)->record(new RecordableRun($uptime->id, $running->claimToken, $running->startedAt, now()->toImmutable(), null))->accepted)
        ->toBeFalse();
});

it('resumes a purge whose slice was lost and opens a system issue for a deletion that does not finish', function (): void {
    $owner = User::factory()->owner()->create();
    $site = siteWithHistory(1500);
    $this->actingAs($owner)->delete('/sites/'.$site->id, ['name' => 'Purge shop'])->assertRedirect('/sites');
    // A worker crashed after one slice: the next slice is never run.
    purgeSlice($site->id, seconds: 0);
    expect(DB::table('check_runs')->whereIn('check_id', $site->checks()->select('id'))->count())->toBe(1500)
        ->and(DB::table('check_run_attempts')->whereIn('check_id', $site->checks()->select('id'))->count())->toBe(500);

    // The sweep does not double a slice still queued, and queues one again once a lost one frees its uniqueness.
    $this->artisan('schedule:test', ['--name' => 'purge-deleted-sites'])->assertSuccessful();
    $pushed = Queue::pushed(PurgeDeletedSite::class)->count();
    $this->travel(5)->minutes();
    $this->artisan('schedule:test', ['--name' => 'purge-deleted-sites'])->assertSuccessful();
    expect(Queue::pushed(PurgeDeletedSite::class)->count())->toBe($pushed + 1);

    // A deletion still pending an hour after it was requested is a system issue until it finishes.
    $this->travel(56)->minutes();
    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
    expect(SystemIssue::open()->where('kind', SystemIssueKind::SiteDeletionStuck->value)->sole()->details)
        ->toMatchArray(['sites' => 1, 'pending_since' => '2026-10-03T12:00:00+00:00']);
    // The open issue follows the count while it stays open.
    Site::factory()->create(['paused_at' => now()->subHours(2), 'marked_for_deletion_at' => now()->subHours(2)]);
    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
    expect(SystemIssue::open()->where('kind', SystemIssueKind::SiteDeletionStuck->value)->sole()->details)
        ->toMatchArray(['sites' => 2, 'pending_since' => '2026-10-03T11:01:00+00:00']);
    DB::table('sites')->where('id', '<>', $site->id)->whereNotNull('marked_for_deletion_at')->delete();

    purgeSlice($site->id);
    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();

    expect(Site::query()->find($site->id))->toBeNull()
        ->and(SystemIssue::open()->where('kind', SystemIssueKind::SiteDeletionStuck->value)->exists())->toBeFalse();
});

it('refuses cron pings of a site marked for deletion like an unknown task, and keeps them for a paused one', function (): void {
    $owner = User::factory()->owner()->create();
    $site = siteWithHistory(1);
    $cron = Check::factory()->for($site)->create(['type' => CheckKey::Cron]);
    $task = app(CronTaskStore::class)->create($cron->id, ['name' => 'Backup', 'type' => 'simple', 'frequency_in_minutes' => 60]);
    $this->actingAs($owner)->post('/sites/'.$site->id.'/pause')->assertRedirect();
    $this->postJson(route('cron.ping', ['uuid' => $task->uuid]))->assertNoContent();

    $this->delete('/sites/'.$site->id, ['name' => 'Purge shop'])->assertRedirect('/sites');

    $this->postJson(route('cron.ping', ['uuid' => $task->uuid]))->assertNotFound();
    $this->postJson(route('cron.ping', ['uuid' => (string) Str::uuid()]))->assertNotFound();
    expect(DB::table('cron_pings')->where('cron_check_id', $task->id)->count())->toBe(1);
});

it('deletes at most one batch of runs per statement, even when runs share an instant', function (): void {
    $owner = User::factory()->owner()->create();
    $site = Site::factory()->create(['friendly_name' => 'Purge shop']);
    $check = Check::factory()->for($site)->create(['type' => CheckKey::Uptime]);
    DB::insert(<<<'SQL'
        INSERT INTO check_runs (check_id, check_type, result, ended_at, summary, metrics)
        SELECT ?, 'uptime', 'succeeded', ?::timestamptz, 'checks.uptime.ok', '{}'::jsonb FROM generate_series(1, 1200)
        SQL, [$check->id, now()->subHour()->toIso8601String()]);
    $this->actingAs($owner)->delete('/sites/'.$site->id, ['name' => 'Purge shop'])->assertRedirect('/sites');

    purgeSlice($site->id, seconds: 0);

    expect(DB::table('check_runs')->where('check_id', $check->id)->count())->toBe(200);
});
