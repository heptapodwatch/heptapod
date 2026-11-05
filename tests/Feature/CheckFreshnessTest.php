<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Actions\BuildAlertMailContext;
use Heptapod\Actions\CancelCheckRun;
use Heptapod\Actions\DisableCheck;
use Heptapod\Actions\EnableCheck;
use Heptapod\Actions\PauseSite;
use Heptapod\Actions\ResumeSite;
use Heptapod\Actions\UpdateCheckConfig;
use Heptapod\Addons\Channels\TemplateMail;
use Heptapod\Addons\Crawl\CrawlMetrics;
use Heptapod\Addons\Cron\CronDefinitions;
use Heptapod\Addons\Uptime\UptimeMetrics;
use Heptapod\Checks\DatabaseClock;
use Heptapod\Checks\Execution\CheckExecutor;
use Heptapod\Checks\Execution\ClaimService;
use Heptapod\Checks\Execution\RunOrigin;
use Heptapod\Checks\Execution\WorkerMemoryExhausted;
use Heptapod\Checks\NoResultFreshness;
use Heptapod\Checks\Recording\RecordableRun;
use Heptapod\Checks\Recording\ResultRecorder;
use Heptapod\Checks\SnoozeNotificationSuppression;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckOutcome;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\HistoryReader;
use Heptapod\Contracts\Mail\EvidenceBlock;
use Heptapod\Contracts\Mail\FactsBlock;
use Heptapod\Contracts\NoResultReason;
use Heptapod\Contracts\RunResult;
use Heptapod\Contracts\SuppressionComponent;
use Heptapod\Contracts\SuppressionSubject;
use Heptapod\Enums\FreshnessSuppression;
use Heptapod\Enums\HealthStatus;
use Heptapod\Enums\IncidentCause;
use Heptapod\Events\Notifications\NotificationEventTypes;
use Heptapod\Jobs\RunCheck;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\CheckRunAttempt;
use Heptapod\Models\Client;
use Heptapod\Models\DispatcherHeartbeat;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\Site;
use Heptapod\Models\SystemIssue;
use Heptapod\SystemHealth\CheckFreshnessIssues;
use Heptapod\SystemHealth\EvaluateSystemIssues;
use Heptapod\SystemHealth\SystemIssueMailer;
use Heptapod\Testing\ClaimedRun;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Horizon\Horizon;

require_once __DIR__.'/../Support/SystemIssueRecipients.php';

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-21T12:00:00Z'));
    $prefix = 'check-freshness-test:'.Str::uuid().':';
    config(['mail.default' => 'smtp', 'queue.default' => 'redis', 'database.redis.options.prefix' => $prefix,
        'cache.prefix' => $prefix, 'horizon.prefix' => $prefix.'horizon:']);
    Horizon::use(config('horizon.use'));
    Redis::purge('default');
    Redis::purge('cache');
    Redis::purge('horizon');
    Cache::purge('redis');
});

afterEach(function (): void {
    // Remove the evaluator records and fixture heartbeat from this test's private cache namespace only.
    foreach ([EvaluateSystemIssues::EvaluationKey, 'heptapod:mail-transport:outcome', config('heptapod.health.scheduler_cache_key')] as $key) {
        Cache::store('redis')->forget($key);
    }
});

/**
 * The settings page lists every open system issue. Select this check's freshness issue by kind and scope,
 * never by position: another open issue (e.g. a host disk alert) can sort before it.
 *
 * @return array<string, mixed>
 */
function freshnessPageIssue(TestResponse $response, int $checkId): array
{
    $issues = $response->viewData('page')['props']['systemPage']['health']['systemIssues'];
    $matches = array_values(array_filter($issues, fn (array $issue): bool => $issue['kind'] === 'check_not_fresh' && $issue['scope'] === (string) $checkId));
    expect($matches)->toHaveCount(1);

    return $matches[0];
}

/** Keeps unrelated instance alarms quiet; the test still drives the real evaluator and database. */
function freshnessHeartbeats(): void
{
    Cache::store('redis')->forever(config('heptapod.health.scheduler_cache_key'), now()->toIso8601String());
    DispatcherHeartbeat::query()->create(['id' => '00000000-0000-0000-0000-000000000093', 'last_seen_at' => now()]);
}

function freshnessClaim(Check $check): CheckRunAttempt
{
    $check->update(['next_run_at' => app(DatabaseClock::class)->now()->subMinute()]);
    app(ClaimService::class)->dispatchDue();
    $check->refresh();

    return CheckRunAttempt::query()->where('claim_token', $check->claim_token)->sole();
}

function freshnessAccept(Check $check): void
{
    $check->refresh();
    $curl = array_fill_keys(['namelookup_time', 'connect_time', 'appconnect_time', 'starttransfer_time', 'pretransfer_time', 'redirect_time', 'total_time', 'header_size', 'size_download', 'speed_download'], null);
    $metrics = $check->type === CheckKey::Uptime
        ? new UptimeMetrics(null, null, null, null, null, 0.2, $curl, now()->toIso8601String())
        : new CrawlMetrics(1, 1, 0, 0, 0, 0);
    app(ResultRecorder::class)->record(new RecordableRun($check->id, $check->claim_token,
        CarbonImmutable::now()->subSecond(), CarbonImmutable::now(), new CheckOutcome(RunResult::Succeeded,
            $check->type === CheckKey::Uptime ? 'uptime.succeeded' : 'broken_links.succeeded', [], $metrics)));
}

it('reproduces the RC silent loss while uptime finishes and opens once then recovers on an accepted result', function (): void {
    Mail::fake();
    $owner = systemIssueRecipient('Owner', attributes: ['locale' => 'en']);
    $admin = systemIssueRecipient('Admin', true, ['locale' => 'de']);
    $excluded = systemIssueRecipient('Admin');
    $uptime = Check::factory()->create(['next_run_at' => now()->addDay()]);
    $crawl = Check::factory()->for($uptime->site)->create(['type' => CheckKey::BrokenLinks, 'interval_seconds' => 86400]);
    freshnessClaim($crawl);
    $since = $crawl->refresh()->awaiting_result_since;
    for ($i = 0; $i < 3; $i++) {
        $this->travelTo($since->addHours($i + 1));
        $crawl->update(['run_started_at' => now()->subHour(), 'lease_expires_at' => app(DatabaseClock::class)->now()->subSecond()]);
        CheckRunAttempt::query()->where('claim_token', $crawl->claim_token)->update(['started_at' => now()->subHour(), 'phase' => 'running']);
        app(ClaimService::class)->dispatchDue();
        $crawl->refresh();
        freshnessClaim($uptime);
        freshnessAccept($uptime);
        expect($uptime->refresh()->latest_run_result)->toBe(CheckResult::Succeeded);
    }
    expect($crawl->lost_attempts)->toBe(3);
    expect($crawl->awaiting_result_since->equalTo($since))->toBeTrue();
    expect(CheckRunAttempt::query()->where('check_id', $crawl->id)->where('note', 'lease_replaced')->whereNull('check_run_id')->count())->toBe(3);
    $this->travelTo($since->addHours(6)->addSecond());
    freshnessHeartbeats();
    freshnessClaim($uptime);
    freshnessAccept($uptime);
    $eventsBefore = DB::table('events')->count();
    $incidentsBefore = DB::table('incidents')->count();

    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();

    $issue = SystemIssue::open()->where('kind', 'check_not_fresh')->sole();
    expect($issue->scope)->toBe((string) $crawl->id);
    expect($issue->severity())->toBe(HealthStatus::Warn);
    expect($issue->details)->toMatchArray(['check_id' => $crawl->id, 'lost_attempts' => 3, 'threshold_seconds' => 21600, 'loss_cause' => 'lease_lost']);
    expect(systemIssueDeliveries($issue->id, 'open')->count())->toBe(2);
    $this->assertDatabaseMissing('system_issues', ['kind' => 'checks_not_running', 'resolved_at' => null]);
    // No site event or incident: the only new event is the issue's own opening in the delivery ledger.
    expect(DB::table('events')->whereNull('system_issue_id')->count())->toBe($eventsBefore);
    expect(DB::table('events')->where('system_issue_id', $issue->id)->pluck('type')->all())->toBe(['system.issue_opened']);
    expect(DB::table('incidents')->count())->toBe($incidentsBefore);
    Mail::assertSent(TemplateMail::class, fn (TemplateMail $mail): bool => $mail->hasTo($owner->email) && str_contains($mail->render(), 'Run lost (cause unknown)'));
    Mail::assertSent(TemplateMail::class, fn (TemplateMail $mail): bool => $mail->hasTo($admin->email) && str_contains($mail->render(), 'Ausführung verloren'));
    Mail::assertNotSent(TemplateMail::class, fn (TemplateMail $mail): bool => $mail->hasTo($excluded->email));

    freshnessAccept($crawl);
    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
    expect($crawl->refresh()->awaiting_result_since)->toBeNull();
    expect($crawl->lost_attempts)->toBe(0);
    expect($issue->refresh()->resolved_at)->not->toBeNull();
    expect($issue->details['resolution'])->toBe('result_accepted');
    expect(systemIssueDeliveries($issue->id, 'recovery')->count())->toBe(2);
});

it('uses queue thresholds instead of the age of a daily result', function (CheckKey $type, int $interval, int $age, bool $opens): void {
    Mail::fake();
    $check = Check::factory()->create(['type' => $type, 'interval_seconds' => $interval, 'next_run_at' => now()->addDay(),
        'awaiting_result_since' => now()->subSeconds($age), 'latest_run_ended_at' => now()->subHours(20)]);
    freshnessHeartbeats();
    CheckRun::factory()->for($check)->create();

    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();

    expect(SystemIssue::open()->where('kind', 'check_not_fresh')->where('scope', (string) $check->id)->count())->toBe($opens ? 1 : 0);
})->with([
    'fast below' => [CheckKey::Uptime, 60, 899, false],
    'fast above' => [CheckKey::Uptime, 60, 901, true],
    'daily below' => [CheckKey::BrokenLinks, 86400, 21599, false],
    'daily above' => [CheckKey::BrokenLinks, 86400, 21601, true],
]);

it('rejects a missing queue or bounds shorter than one attempt window', function (string $scenario): void {
    config([$scenario === 'missing' ? 'heptapod.freshness.queues.checks-browser' : 'heptapod.freshness.queues.checks-fast.min_seconds' => $scenario === 'missing' ? null : 764]);

    expect(fn () => app(CheckFreshnessIssues::class)->validateConfiguration())->toThrow(LogicException::class);
})->with(['missing', 'short']);

it('stops tracking excluded checks and starts a new episode after enable or resume', function (string $scenario): void {
    Mail::fake();
    $check = Check::factory()->create(['type' => $scenario === 'unavailable' ? CheckKey::Lighthouse : CheckKey::BrokenLinks,
        'interval_seconds' => 86400, 'next_run_at' => now()->addDay(), 'awaiting_result_since' => now()->subHours(7), 'lost_attempts' => 17]);
    freshnessHeartbeats();
    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
    $issue = SystemIssue::open()->where('kind', 'check_not_fresh')->sole();
    match ($scenario) {
        'paused' => app(PauseSite::class)($check->site),
        'disabled' => app(DisableCheck::class)($check),
        'deleting' => $check->site->update(['marked_for_deletion_at' => now()]),
        'unavailable' => config(['heptapod.browser.enabled' => false]),
    };

    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();

    expect($issue->refresh()->details['resolution'])->toBe('monitoring_stopped');
    expect($issue->resolved_at)->not->toBeNull();
    expect(SystemIssue::open()->where('kind', 'check_not_fresh')->count())->toBe(0);
    if ($scenario === 'paused' || $scenario === 'disabled') {
        $scenario === 'paused' ? app(ResumeSite::class)($check->site) : app(EnableCheck::class)($check->refresh());
        expect($check->refresh()->awaiting_result_since)->toBeNull();
        expect($check->lost_attempts)->toBe(0);
        freshnessClaim($check);
        expect($check->refresh()->awaiting_result_since->equalTo(now()))->toBeTrue();
        expect($check->lost_attempts)->toBe(0);
    }
})->with(['paused', 'disabled', 'deleting', 'unavailable']);

it('does not flag a never claimed check but flags repeated loss of the first run', function (): void {
    Mail::fake();
    $never = Check::factory()->create(['next_run_at' => now()->addDay()]);
    $lost = Check::factory()->create(['next_run_at' => now()->addDay(), 'awaiting_result_since' => now()->subHours(2), 'lost_attempts' => 4]);
    freshnessHeartbeats();
    CheckRun::factory()->for($never)->create();

    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();

    $this->assertDatabaseMissing('system_issues', ['kind' => 'check_not_fresh', 'scope' => (string) $never->id]);
    $this->assertDatabaseHas('system_issues', ['kind' => 'check_not_fresh', 'scope' => (string) $lost->id, 'resolved_at' => null]);
});

it('suppresses new items under an instance alarm without resolving an already open item', function (string $alarm): void {
    Mail::fake();
    $existing = Check::factory()->create(['next_run_at' => now()->addDay(), 'awaiting_result_since' => now()->subHour()]);
    $new = Check::factory()->create(['next_run_at' => now()->addDay()]);
    $run = CheckRun::factory()->for($existing)->create();
    freshnessHeartbeats();
    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
    $new->update(['awaiting_result_since' => now()->subHour()]);
    if ($alarm === 'checks_not_running') {
        $run->update(['ended_at' => now()->subHour(), 'started_at' => now()->subHours(2)]);
        $existing->update(['next_run_at' => now()->subHour()]);
    } else {
        Cache::store('redis')->forget(config('heptapod.health.scheduler_cache_key'));
    }

    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();

    $this->assertDatabaseHas('system_issues', ['kind' => $alarm, 'resolved_at' => null]);
    $this->assertDatabaseHas('system_issues', ['kind' => 'check_not_fresh', 'scope' => (string) $existing->id, 'resolved_at' => null]);
    $this->assertDatabaseMissing('system_issues', ['kind' => 'check_not_fresh', 'scope' => (string) $new->id]);
})->with(['checks_not_running', 'scheduler_stale']);

it('does not double alert a site with an open uptime failure', function (): void {
    Mail::fake();
    $uptime = Check::factory()->create(['next_run_at' => now()->addDay()]);
    Incident::factory()->for($uptime)->create();
    $crawl = Check::factory()->for($uptime->site)->create(['type' => CheckKey::BrokenLinks, 'interval_seconds' => 86400,
        'next_run_at' => now()->addDay(), 'awaiting_result_since' => now()->subHours(7)]);
    freshnessHeartbeats();
    CheckRun::factory()->for($uptime)->create();

    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();

    $this->assertDatabaseMissing('system_issues', ['kind' => 'check_not_fresh', 'scope' => (string) $crawl->id]);
});

it('recovers a removed check using its retained name in the mail model', function (): void {
    Mail::fake();
    $owner = systemIssueRecipient('Owner');
    $check = Check::factory()->create(['next_run_at' => now()->addDay(), 'awaiting_result_since' => now()->subHour()]);
    $check->site->update(['friendly_name' => 'Retained site']);
    freshnessHeartbeats();
    CheckRun::factory()->for($check)->create();
    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
    $issue = SystemIssue::open()->where('kind', 'check_not_fresh')->sole();
    $check->delete();

    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();

    expect($issue->refresh()->details['resolution'])->toBe('check_deleted');
    expect(systemIssueDeliveries($issue->id, 'recovery')->count())->toBe(1);
    expect(app(SystemIssueMailer::class)->model($issue, true, 'en', true)->subject)->toContain('System recovered');
    Mail::assertSent(TemplateMail::class, fn (TemplateMail $mail): bool => str_contains($mail->render(), 'Retained site') && str_contains($mail->render(), 'The check was removed'));
});

it('renders honest started and never-started loss labels in both locales', function (string $locale, string $started, string $queued): void {
    $owner = systemIssueRecipient('Owner', attributes: ['locale' => $locale]);
    $check = Check::factory()->create();
    foreach ([true, false] as $didStart) {
        CheckRunAttempt::query()->create(['check_id' => $check->id, 'claim_token' => $didStart ? '00000000-0000-0000-0000-000000000091' : '00000000-0000-0000-0000-000000000092',
            'phase' => 'skipped', 'queue' => 'checks-fast', 'requested_at' => now()->subHour(), 'started_at' => $didStart ? now()->subHour() : null,
            'finished_at' => now(), 'note' => 'lease_replaced']);
    }

    $this->actingAs($owner)->get('/runs?check='.$check->id)->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('runs', 2)->where('runs.0.note', $queued)->where('runs.1.note', $started));
})->with([
    ['en', 'Run lost (cause unknown)', 'Did not start'],
    ['de', 'Ausführung verloren (Ursache unbekannt)', 'Nicht gestartet'],
]);

it('records proven timeout and memory causes and exposes them on the check header and API', function (string $cause): void {
    $owner = systemIssueRecipient('Owner');
    $check = Check::factory()->create(['type' => CheckKey::BrokenLinks, 'interval_seconds' => 86400]);
    $attempt = freshnessClaim($check);
    $started = now()->subMinute();
    $check->update(['run_started_at' => $started]);
    $attempt->update(['phase' => 'running', 'started_at' => $started]);
    $exception = $cause === 'timed_out' ? new TimeoutExceededException : new WorkerMemoryExhausted('Check worker ran out of memory.');

    new RunCheck($check->id, $check->claim_token, $cause === 'timed_out' ? 600 : 1200, 'checks-crawl')->failed($exception);

    expect($check->refresh()->summary)->toBe($cause === 'timed_out' ? 'causes.engine.job_timed_out' : 'causes.engine.worker_memory_exhausted');
    if ($cause === 'timed_out') {
        expect($check->summary_params)->toBe(['seconds' => 615]);
    }
    expect($check->awaiting_result_since)->toBeNull();
    $this->withToken($owner->createToken('freshness', ['monitors:read'])->plainTextToken)
        ->getJson('/api/monitors/'.$check->site_id)->assertJsonPath('checks.0.last_attempt_loss.cause', $cause)->assertJsonPath('checks.0.freshness.state', 'fresh');
    $this->actingAs($owner)->get('/sites/'.$check->site_id.'/checks/'.$check->id)->assertInertia(fn (Assert $page): AssertableInertia => $page->where('schedule.last_loss.cause', $cause));
})->with(['timed_out', 'worker_out_of_memory']);

it('returns the documented not-fresh and not-expected API shapes without per-check site queries', function (): void {
    $owner = systemIssueRecipient('Owner');
    $check = Check::factory()->create(['awaiting_result_since' => now()->subHour(), 'lost_attempts' => 3, 'last_loss_started_at' => now()->subHour()]);
    Check::factory()->for($check->site)->create(['type' => CheckKey::CertificateHealth, 'enabled' => false]);
    $this->withToken($owner->createToken('freshness-list', ['monitors:read'])->plainTextToken);
    DB::enableQueryLog();

    $response = $this->getJson('/api/monitors')->assertOk();
    $checks = collect($response->json('data.0.checks'))->keyBy('id');
    expect($checks[$check->id]['freshness'])->toBe([
        'state' => 'not_fresh', 'awaiting_result_since' => '2026-10-21T11:00:00+00:00',
        'not_fresh_since' => '2026-10-21T11:15:00+00:00', 'threshold_seconds' => 900, 'lost_attempts' => 3,
    ]);
    expect($checks[$check->id]['last_attempt_loss'])->toBe(['cause' => 'lease_lost']);
    $off = $checks->firstWhere('enabled', false);
    expect($off['freshness']['state'])->toBe('not_expected');
    expect($off['last_attempt_loss'])->toBeNull();

    $siteLookups = array_filter(DB::getQueryLog(), static fn (array $query): bool => str_contains($query['query'], 'from "sites" where "sites"."id" ='));
    DB::disableQueryLog();
    expect($siteLookups)->toBeEmpty();
});

it('still tracks missing results during maintenance or a snooze', function (string $scenario): void {
    Mail::fake();
    $check = Check::factory()->create(['next_run_at' => now()->addDay(), 'awaiting_result_since' => now()->subHour()]);
    if ($scenario === 'maintenance') {
        MaintenancePeriod::factory()->create(['site_id' => $check->site_id, 'starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);
    } else {
        $check->snoozes()->create(['starts_at' => now()->subHour(), 'ends_at' => now()->addHour(), 'snoozed_by' => 'fixture', 'source' => 'ui']);
    }
    freshnessHeartbeats();
    CheckRun::factory()->for($check)->create();

    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();

    $this->assertDatabaseHas('system_issues', ['kind' => 'check_not_fresh', 'scope' => (string) $check->id, 'resolved_at' => null]);
})->with(['maintenance', 'snooze']);

it('ends the episode on queued cancellation or explicit revocation', function (string $close): void {
    $check = Check::factory()->create(['type' => CheckKey::BrokenLinks, 'interval_seconds' => 86400]);
    freshnessClaim($check);
    $check->update(['lost_attempts' => 2]);

    $close === 'cancel' ? app(CancelCheckRun::class)($check) : app(ClaimService::class)->revoke($check);

    expect($check->refresh()->awaiting_result_since)->toBeNull();
    expect($check->lost_attempts)->toBe(0);
})->with(['cancel', 'revoke']);

it('does not attribute a timeout budget to retained generic error runs', function (): void {
    $owner = systemIssueRecipient('Owner');
    $check = Check::factory()->create(['latest_run_result' => CheckResult::Errored, 'summary' => 'checks.engine.errored']);
    $run = CheckRun::factory()->for($check)->create(['result' => RunResult::Errored, 'summary' => 'checks.engine.errored',
        'error' => json_encode(['class' => TimeoutExceededException::class, 'message' => 'A framework timeout message'], JSON_THROW_ON_ERROR)]);
    $check->update(['latest_run_id' => $run->id, 'latest_run_ended_at' => $run->ended_at]);

    $this->actingAs($owner)->get('/sites/'.$check->site_id.'/checks/'.$check->id)
        ->assertInertia(fn (Assert $page): AssertableInertia => $page->where('check.summary', 'causes.error')->where('schedule.last_loss', null)
            ->where('runs.0.summary', 'causes.error')->where('runs.0.summary_params', ['message' => 'A framework timeout message']));
});

it('keeps an open freshness issue across a routine site settings save without recovery mail', function (): void {
    Mail::fake();
    $owner = systemIssueRecipient('Owner', attributes: ['locale' => 'en']);
    $uptime = Check::factory()->create(['next_run_at' => now()->addDay()]);
    $crawl = Check::factory()->for($uptime->site)->create(['type' => CheckKey::BrokenLinks, 'interval_seconds' => 86400,
        'next_run_at' => now()->addDay(), 'awaiting_result_since' => now()->subHours(7), 'lost_attempts' => 3]);
    CheckRun::factory()->for($uptime)->create();
    freshnessHeartbeats();
    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
    $issue = SystemIssue::open()->where('kind', 'check_not_fresh')->sole();
    $since = $crawl->awaiting_result_since;

    $this->actingAs($owner)->from('/sites/'.$crawl->site_id.'/settings')->patch('/sites/'.$crawl->site_id, [
        'url' => $crawl->site->url, 'friendly_name' => 'Renamed without a new result', 'tags' => ['renamed'],
        'checks' => ['uptime', 'broken_links'],
    ])->assertRedirect('/sites/'.$crawl->site_id.'/settings')->assertSessionHasNoErrors();
    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();

    expect($crawl->refresh()->awaiting_result_since?->equalTo($since))->toBeTrue();
    expect($crawl->lost_attempts)->toBe(3);
    expect($issue->refresh()->resolved_at)->toBeNull();
    expect($crawl->site->refresh()->friendly_name)->toBe('Renamed without a new result');
    expect(systemIssueDeliveries($issue->id, 'recovery')->count())->toBe(0);
    Mail::assertNotSent(TemplateMail::class, fn (TemplateMail $mail): bool => str_starts_with($mail->model->subject, '[System recovered] Check has no recent result'));
});

it('preserves enabled episodes through routine API monitor settings cron sync and repeated enable', function (string $save): void {
    $owner = systemIssueRecipient('Owner');
    $check = Check::factory()->create(['type' => $save === 'cron sync' ? CheckKey::Cron : CheckKey::BrokenLinks,
        'awaiting_result_since' => now()->subHours(7), 'lost_attempts' => 3]);
    $since = $check->awaiting_result_since;
    $this->withToken($owner->createToken('routine-save', ['*'])->plainTextToken);

    match ($save) {
        'monitor settings' => $this->putJson('/api/monitors/'.$check->site_id, ['notes' => 'Routine save', 'broken_links_check_settings' => []])->assertOk(),
        'cron sync' => $this->postJson('/api/monitors/'.$check->site_id.'/cron-checks/sync', ['cron_checks' => [
            ['name' => 'existing task', 'type' => 'simple', 'frequency_in_minutes' => 5, 'grace_time_in_minutes' => 5, 'description' => ''],
        ]])->assertOk(),
        'repeat enable' => $this->postJson('/api/checks/'.$check->id.'/enable')->assertJsonPath('enabled', true),
    };

    expect($check->refresh()->awaiting_result_since?->equalTo($since))->toBeTrue();
    expect($check->lost_attempts)->toBe(3);
})->with(['monitor settings', 'cron sync', 'repeat enable']);

it('resets a disabled check only on its actual enable transition and returns the enabled state', function (): void {
    $owner = systemIssueRecipient('Owner');
    $check = Check::factory()->create(['enabled' => false, 'awaiting_result_since' => now()->subHour(),
        'lost_attempts' => 2, 'last_loss_started_at' => now()->subHour()]);

    $this->withToken($owner->createToken('enable', ['checks:write'])->plainTextToken)
        ->postJson('/api/checks/'.$check->id.'/enable')->assertJsonPath('enabled', true);

    expect($check->refresh()->awaiting_result_since)->toBeNull();
    expect($check->lost_attempts)->toBe(0);
    expect($check->last_loss_started_at)->toBeNull();
});

it('mails result recovery only for a result since the issue episode and otherwise says the check was reset', function (string $close, string $locale): void {
    Mail::fake();
    $owner = systemIssueRecipient('Owner', attributes: ['locale' => $locale]);
    $check = Check::factory()->create(['type' => CheckKey::BrokenLinks, 'interval_seconds' => 86400]);
    freshnessClaim($check);
    $since = $check->refresh()->awaiting_result_since;
    $check->update(['latest_run_ended_at' => $since->subDay()]);
    $this->travelTo($since->addHours(7));
    $healthy = Check::factory()->create(['next_run_at' => now()->addDay()]);
    CheckRun::factory()->for($healthy)->create();
    freshnessHeartbeats();
    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
    $issue = SystemIssue::open()->where('kind', 'check_not_fresh')->sole();

    if ($close === 'result') {
        freshnessAccept($check);
    } elseif ($close === 'url') {
        $this->actingAs($owner)->patch('/sites/'.$check->site_id, ['url' => rtrim($check->site->url, '/').'/new-path', 'checks' => ['broken_links']])
            ->assertRedirect()->assertSessionHasNoErrors();
    } else {
        $this->actingAs($owner)->patch('/sites/'.$check->site_id.'/checks/'.$check->id, ['max_pages' => 5])
            ->assertRedirect()->assertSessionHasNoErrors();
    }
    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();

    expect($issue->refresh()->resolved_at)->not->toBeNull();
    expect($issue->details['resolution'])->toBe($close === 'result' ? 'result_accepted' : 'check_reset');
    $expectedText = $close === 'result'
        ? ($locale === 'de' ? 'Für die Prüfung liegt wieder ein Ergebnis vor.' : 'The check has a result again.')
        : ($locale === 'de' ? 'Die Prüfung wurde zurückgesetzt.' : 'The check was reset.');
    Mail::assertSent(TemplateMail::class, fn (TemplateMail $mail): bool => $mail->hasTo($owner->email)
        && str_contains($mail->model->subject, $locale === 'de' ? 'System wiederhergestellt' : 'System recovered')
        && $mail->model->lead === $expectedText && str_contains($mail->render(), $expectedText));
    expect(systemIssueDeliveries($issue->id, 'recovery')->count())->toBe(1);
})->with([
    ['result', 'en'], ['result', 'de'], ['url', 'en'], ['url', 'de'], ['config', 'en'], ['config', 'de'],
]);

it('reports did not start on every surface for the latest loss even after an earlier running loss', function (string $locale, string $label): void {
    Mail::fake();
    $owner = systemIssueRecipient('Owner', attributes: ['locale' => $locale]);
    $check = Check::factory()->create(['type' => CheckKey::BrokenLinks, 'interval_seconds' => 86400]);
    $first = freshnessClaim($check);
    $started = now()->subMinute();
    $first->update(['phase' => 'running', 'started_at' => $started]);
    $check->update(['run_started_at' => $started, 'lease_expires_at' => app(DatabaseClock::class)->now()->subSecond()]);
    app(ClaimService::class)->dispatchDue();
    expect($check->refresh()->last_loss_started_at?->equalTo($started))->toBeTrue();
    $this->artisan('queue:clear', ['connection' => 'redis', '--queue' => 'checks-crawl'])->assertSuccessful();
    $check->update(['lease_expires_at' => app(DatabaseClock::class)->now()->subSecond()]);

    app(ClaimService::class)->dispatchDue();
    $check->refresh();
    $this->travelTo($check->awaiting_result_since->addHours(7));
    $healthy = Check::factory()->create(['next_run_at' => now()->addDay()]);
    CheckRun::factory()->for($healthy)->create();
    freshnessHeartbeats();
    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();

    expect($check->refresh()->last_loss_started_at)->toBeNull();
    expect($check->lost_attempts)->toBe(2);
    expect(SystemIssue::open()->where('kind', 'check_not_fresh')->sole()->details['loss_cause'])->toBe('did_not_start');
    $this->withToken($owner->createToken('loss-cause', ['monitors:read'])->plainTextToken)
        ->getJson('/api/monitors/'.$check->site_id)->assertJsonPath('checks.0.last_attempt_loss.cause', 'did_not_start');
    $this->actingAs($owner)->get('/sites/'.$check->site_id.'/checks/'.$check->id)
        ->assertInertia(fn (Assert $page): AssertableInertia => $page->where('schedule.last_loss.cause', 'did_not_start'));
    Mail::assertSent(TemplateMail::class, fn (TemplateMail $mail): bool => $mail->hasTo($owner->email) && str_contains($mail->render(), $label));
})->with([['en', 'Did not start'], ['de', 'Nicht gestartet']]);

it('does not restart a new episode when an enable request holds a stale disabled model', function (): void {
    $check = Check::factory()->create(['enabled' => false]);
    $staleRequest = Check::query()->findOrFail($check->id);
    app(EnableCheck::class)($check);
    freshnessClaim($check);
    $since = $check->refresh()->awaiting_result_since;

    app(EnableCheck::class)($staleRequest);

    expect($staleRequest->enabled)->toBeTrue();
    expect($check->refresh()->awaiting_result_since?->equalTo($since))->toBeTrue();
});

it('does not mistake a result from earlier in the same second for a result in the issue episode', function (): void {
    Mail::fake();
    $owner = systemIssueRecipient('Owner', attributes: ['locale' => 'en']);
    $check = Check::factory()->create(['next_run_at' => now()->addDay(),
        'awaiting_result_since' => CarbonImmutable::parse('2026-10-21T10:59:59.900000Z'),
        'latest_run_ended_at' => CarbonImmutable::parse('2026-10-21T10:59:59.100000Z')]);
    $healthy = Check::factory()->create(['next_run_at' => now()->addDay()]);
    CheckRun::factory()->for($healthy)->create();
    freshnessHeartbeats();
    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
    $issue = SystemIssue::open()->where('kind', 'check_not_fresh')->sole();

    $this->actingAs($owner)->patch('/sites/'.$check->site_id, ['url' => rtrim($check->site->url, '/').'/reset'])
        ->assertRedirect()->assertSessionHasNoErrors();
    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();

    expect($issue->refresh()->details['resolution'])->toBe('check_reset');
    Mail::assertSent(TemplateMail::class, fn (TemplateMail $mail): bool => $mail->hasTo($owner->email) && $mail->model->lead === 'The check was reset.');
});

it('explains missing performance results during an uptime outage and clears the state on recovery', function (): void {
    $owner = systemIssueRecipient('Owner', attributes: ['locale' => 'en']);
    $uptime = Check::factory()->create(['next_run_at' => now()->addDay()]);
    $performance = Check::factory()->for($uptime->site)->create(['type' => CheckKey::Performance]);
    $incident = Incident::factory()->for($uptime)->create(['first_failure_at' => now()->subHours(2), 'opened_at' => now()->subHour()]);
    $previous = CheckRun::factory()->for($performance)->create(['result' => RunResult::Succeeded, 'summary' => 'performance.succeeded', 'summary_params' => ['average' => 200],
        'started_at' => now()->subHours(3)->subSecond(), 'ended_at' => now()->subHours(3),
        'metrics' => ['average_response_time_in_ms' => 200, 'previous_average_response_time_in_ms' => null, 'change_percentage' => null, 'sample_count' => 1, 'previous_sample_count' => 0, 'consecutive_warning_samples' => 0, 'consecutive_good_samples' => 0]]);
    $performance->update(['latest_run_id' => $previous->id, 'latest_run_ended_at' => $previous->ended_at, 'latest_run_result' => CheckResult::Succeeded, 'summary' => $previous->summary, 'summary_params' => $previous->summary_params]);
    $registry = app(CheckRegistry::class);
    $context = ClaimedRun::start($performance, history: app(HistoryReader::class));
    $outcome = $registry->get(CheckKey::Performance)->execute($context);
    expect($outcome)->toBe(NoResultReason::PerformanceNoSamples);
    app(ResultRecorder::class)->record(new RecordableRun($performance->id, $context->claimToken, $context->startedAt, $context->startedAt->addSecond(), $outcome));
    $this->assertDatabaseHas('check_run_attempts', ['check_id' => $performance->id, 'note' => 'no_result:performance_no_samples', 'check_run_id' => null]);
    expect(CheckRun::query()->where('check_id', $performance->id)->count())->toBe(1);
    $token = $owner->createToken('performance-read', ['monitors:read'])->plainTextToken;
    $path = '/sites/'.$uptime->site_id.'/checks/'.$performance->id;
    $this->actingAs($owner)->get($path)->assertInertia(fn (Assert $page): Assert => $page
        ->where('performance_unavailable.key', 'performance.no_data_while_down')
        ->where('performance_unavailable.params.date', '2026-10-21T10:00:00+00:00'));
    $response = $this->withToken($token)->getJson('/api/monitors/'.$uptime->site_id);
    $checks = collect($response->json('checks'))->keyBy('id');
    expect($checks[$performance->id]['performance_unavailable'])->toBe(['key' => 'performance.no_data_while_down', 'params' => ['date' => '2026-10-21T10:00:00+00:00']]);
    expect($checks[$uptime->id]['performance_unavailable'])->toBeNull();
    $performance->update(['enabled' => false]);
    $this->get($path)->assertInertia(fn (Assert $page): Assert => $page->where('performance_unavailable', null));
    $performance->update(['enabled' => true]);
    $incident->update(['cause' => IncidentCause::Errored]);
    $this->get($path)->assertInertia(fn (Assert $page): Assert => $page->where('performance_unavailable', null));
    $incident->update(['cause' => IncidentCause::Failed]);

    $incident->update(['resolved_at' => now()]);
    freshnessClaim($uptime);
    freshnessAccept($uptime);
    $this->travelTo(CarbonImmutable::parse('2026-10-21T12:01:00Z'));
    $context = ClaimedRun::start($performance->refresh(), history: app(HistoryReader::class));
    $outcome = $registry->get(CheckKey::Performance)->execute($context);
    expect($outcome)->toBeInstanceOf(CheckOutcome::class);
    app(ResultRecorder::class)->record(new RecordableRun($performance->id, $context->claimToken, $context->startedAt, $context->startedAt->addSecond(), $outcome));

    $this->get($path)->assertInertia(fn (Assert $page): Assert => $page->where('performance_unavailable', null));
    $checks = collect($this->withToken($token)->getJson('/api/monitors/'.$uptime->site_id)->json('checks'))->keyBy('id');
    expect($checks[$performance->id]['performance_unavailable'])->toBeNull();
    expect($performance->refresh()->latest_run_result)->toBe(CheckResult::Succeeded);
});

it('retains a no-result episode and its losses until real response-time measurements recover it', function (): void {
    Mail::fake();
    $owner = systemIssueRecipient('Owner', attributes: ['locale' => 'en']);
    $admin = systemIssueRecipient('Admin', true, ['locale' => 'de']);
    $uptime = Check::factory()->create(['enabled' => false, 'next_run_at' => now()->addDay()]);
    $performance = Check::factory()->for($uptime->site)->create(['type' => CheckKey::Performance,
        'awaiting_result_since' => now()->subHour(), 'lost_attempts' => 2, 'last_loss_started_at' => now()->subHour()]);
    $since = $performance->awaiting_result_since;
    $context = ClaimedRun::start($performance, history: app(HistoryReader::class));
    $outcome = app(CheckRegistry::class)->get(CheckKey::Performance)->execute($context);

    app(ResultRecorder::class)->record(new RecordableRun($performance->id, $context->claimToken, $context->startedAt, $context->startedAt->addSecond(), $outcome));

    expect($performance->refresh()->awaiting_result_since?->equalTo($since))->toBeTrue();
    expect($performance->lost_attempts)->toBe(2);
    expect($performance->claim_token)->toBeNull();
    expect($performance->next_run_at->greaterThan($context->startedAt))->toBeTrue();
    freshnessHeartbeats();
    $healthy = Check::factory()->create(['next_run_at' => now()->addDay()]);
    CheckRun::factory()->for($healthy)->create();
    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
    $issue = SystemIssue::open()->where('kind', 'check_not_fresh')->where('scope', (string) $performance->id)->sole();
    expect($issue->details['no_result_cause'])->toBe('freshness.no_result.uptime_disabled');
    foreach (['en' => 'No response-time data: the uptime check is disabled.', 'de' => 'Keine Antwortzeiten: Die Erreichbarkeitsprüfung ist deaktiviert.'] as $locale => $text) {
        expect(__($issue->details['no_result_cause'], locale: $locale))->toBe($text);
    }
    Mail::assertSent(TemplateMail::class, fn (TemplateMail $mail): bool => $mail->hasTo($owner->email) && str_contains($mail->render(), 'No response-time data: the uptime check is disabled.'));
    Mail::assertSent(TemplateMail::class, fn (TemplateMail $mail): bool => $mail->hasTo($admin->email) && str_contains($mail->render(), 'Keine Antwortzeiten: Die Erreichbarkeitsprüfung ist deaktiviert.'));
    expect(freshnessPageIssue($this->actingAs($owner)->get('/settings/system'), $performance->id)['details']['no_result_cause'])->toBe('freshness.no_result.uptime_disabled');
    $maintenance = MaintenancePeriod::factory()->create(['site_id' => $uptime->site_id, 'starts_at' => now()->subMinute(), 'ends_at' => now()->addMinute(), 'mute_alerts' => true]);
    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
    expect($issue->refresh()->resolved_at)->toBeNull();
    $maintenance->delete();
    $uptime->update(['enabled' => true]);
    freshnessClaim($uptime);
    freshnessAccept($uptime);
    $this->travelTo(now()->addMinute());
    $context = ClaimedRun::start($performance, history: app(HistoryReader::class));
    $outcome = app(CheckRegistry::class)->get(CheckKey::Performance)->execute($context);
    expect($outcome)->toBeInstanceOf(CheckOutcome::class);
    app(ResultRecorder::class)->record(new RecordableRun($performance->id, $context->claimToken, $context->startedAt, $context->startedAt->addSecond(), $outcome));
    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
    expect($performance->refresh()->awaiting_result_since)->toBeNull();
    expect($issue->refresh()->resolved_at)->not->toBeNull();
});

it('classifies every no-result contract case and rejects unknown persisted reasons', function (): void {
    $check = Check::factory()->create(['type' => CheckKey::Performance]);
    $classifier = app(NoResultFreshness::class);
    $expected = [
        'performance_uptime_interval_too_long' => ['counts', 'freshness.no_result.uptime_disabled', 'No response-time data: the uptime check is disabled.', 'Keine Antwortzeiten: Die Erreichbarkeitsprüfung ist deaktiviert.'],
        'performance_no_samples' => ['counts', 'freshness.no_result.uptime_disabled', 'No response-time data: the uptime check is disabled.', 'Keine Antwortzeiten: Die Erreichbarkeitsprüfung ist deaktiviert.'],
        'mixed_content_start_page_not_scanned' => ['counts', 'freshness.no_result.mixed_content_start_page', 'Mixed content not checked: the start page was not scanned in the last crawl.', 'Mixed Content nicht geprüft: Die Startseite wurde beim letzten Crawl nicht gescannt.'],
        'cron_tasks_pending' => ['resets', null, null, null],
    ];
    foreach (NoResultReason::cases() as $reason) {
        [$action, $key, $en, $de] = $expected[$reason->value];
        expect($classifier->classify($reason, $check, CarbonImmutable::now()))->toBe(['action' => $action, 'reason' => $key]);
        if ($key !== null) {
            expect(__($key, locale: 'en'))->toBe($en);
            expect(__($key, locale: 'de'))->toBe($de);
        }
    }
    $check->update(['awaiting_result_since' => now()->subHour()]);
    CheckRunAttempt::query()->create(['check_id' => $check->id, 'claim_token' => '00000000-0000-0000-0000-000000000047',
        'phase' => 'skipped', 'queue' => 'checks-fast', 'requested_at' => now(), 'finished_at' => now(), 'note' => 'no_result:unknown']);
    expect(fn () => $classifier->forCheck($check, CarbonImmutable::now()))->toThrow(LogicException::class, 'Unknown no-result reason');
});

it('suppresses visible uptime failures and planned no-result gaps but counts hidden missing measurements', function (string $state, ?string $cause): void {
    Mail::fake();
    $uptime = Check::factory()->create(['next_run_at' => now()->addDay(), 'latest_run_ended_at' => now()->subMinutes(30), 'latest_run_result' => match ($state) {
        'latest error' => CheckResult::Errored,
        'warning with recent errors' => CheckResult::Warning,
        default => CheckResult::Succeeded,
    }]);
    $performance = Check::factory()->for($uptime->site)->create(['type' => CheckKey::Performance, 'awaiting_result_since' => now()->subHour()]);
    if (in_array($state, ['down', 'error incident'], true)) {
        Incident::factory()->for($uptime)->create(['cause' => $state === 'down' ? IncidentCause::Failed : IncidentCause::Errored]);
    }
    if (in_array($state, ['recent errors', 'old errors', 'window start', 'before window start', 'window end', 'warning with recent errors'], true)) {
        $endedAt = match ($state) {
            'window start' => now()->subHour(),
            'before window start' => now()->subHour()->subSecond(),
            'window end' => now(),
            'old errors' => now()->subMinutes(61),
            default => now()->subMinutes(10),
        };
        CheckRun::factory()->for($uptime)->create(['result' => RunResult::Errored, 'metrics' => null, 'started_at' => now()->subHours(2), 'ended_at' => $endedAt]);
    }
    if ($state === 'uptime snoozed') {
        $uptime->snoozes()->create(['starts_at' => now()->subMinute(), 'ends_at' => now()->addMinute(), 'snoozed_by' => 'fixture', 'source' => 'ui']);
    }
    if ($state === 'maintenance') {
        MaintenancePeriod::factory()->create(['site_id' => $uptime->site_id, 'starts_at' => now()->subMinute(), 'ends_at' => now()->addMinute(), 'mute_alerts' => true]);
    }
    $context = ClaimedRun::start($performance, history: app(HistoryReader::class));
    $outcome = app(CheckRegistry::class)->get(CheckKey::Performance)->execute($context);
    app(ResultRecorder::class)->record(new RecordableRun($performance->id, $context->claimToken, $context->startedAt, $context->startedAt->addSecond(), $outcome));
    $classification = app(NoResultFreshness::class)->forCheck($performance->refresh(), CarbonImmutable::now());
    expect($classification['action'])->toBe($cause === null ? 'suppressed' : 'counts');
    if ($cause !== null) {
        expect($classification['reason'])->toBe($cause);
        expect(__($cause, locale: 'en'))->toBe($cause === 'freshness.no_result.uptime_errors' ? 'No response-time data: the uptime check has errors.' : 'No response-time data: the uptime check returns no measurements.');
        expect(__($cause, locale: 'de'))->toBe($cause === 'freshness.no_result.uptime_errors' ? 'Keine Antwortzeiten: Die Erreichbarkeitsprüfung meldet Prüffehler.' : 'Keine Antwortzeiten: Die Erreichbarkeitsprüfung liefert keine Messwerte.');
    }
    freshnessHeartbeats();
    $healthy = Check::factory()->create(['next_run_at' => now()->addDay()]);
    CheckRun::factory()->for($healthy)->create();
    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
    expect(SystemIssue::open()->where('kind', 'check_not_fresh')->where('scope', (string) $performance->id)->exists())->toBe($cause !== null);
    if (in_array($state, ['maintenance', 'uptime snoozed'], true)) {
        $this->travelTo(now()->addMinutes(2));
        DispatcherHeartbeat::query()->whereKey('00000000-0000-0000-0000-000000000093')->delete();
        freshnessHeartbeats();
        $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
        $this->assertDatabaseHas('system_issues', ['kind' => 'check_not_fresh', 'scope' => (string) $performance->id, 'resolved_at' => null]);
    }
})->with([
    'Down incident' => ['down', null], 'Check error incident' => ['error incident', null], 'latest Check error' => ['latest error', null],
    'OK snapshot with recent errors' => ['recent errors', 'freshness.no_result.uptime_errors'],
    'Warning snapshot with recent errors' => ['warning with recent errors', 'freshness.no_result.uptime_errors'],
    'error exactly at window start counts' => ['window start', 'freshness.no_result.uptime_errors'],
    'error one second before window start excluded' => ['before window start', 'freshness.no_result.uptime_no_measurements'],
    'error exactly at window end excluded' => ['window end', 'freshness.no_result.uptime_no_measurements'],
    'errors outside window' => ['old errors', 'freshness.no_result.uptime_no_measurements'],
    'OK without timing metric' => ['no measurements', 'freshness.no_result.uptime_no_measurements'],
    'planned maintenance' => ['maintenance', null], 'uptime snooze' => ['uptime snoozed', null],
]);

it('keeps the capped current window disjoint from all seven baseline hours at every start edge', function (string $time): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-21T12:'.$time.'Z'));
    $uptime = Check::factory()->create(['interval_seconds' => 43200, 'next_run_at' => now()->addDay()]);
    $performance = Check::factory()->for($uptime->site)->create(['type' => CheckKey::Performance]);
    $currentStart = CarbonImmutable::now()->subHours(23);
    $baselineEnd = CarbonImmutable::now()->startOfHour()->subDay()->addHour();
    expect($currentStart->greaterThanOrEqualTo($baselineEnd))->toBeTrue();
    CheckRun::factory()->for($uptime)->create(['started_at' => $currentStart->subSecond(), 'ended_at' => $currentStart, 'metrics' => ['total_time_in_seconds' => 0.9]]);
    CheckRun::factory()->for($uptime)->create(['started_at' => $baselineEnd->subSeconds(2), 'ended_at' => $baselineEnd->subSecond(), 'metrics' => ['total_time_in_seconds' => 0.1]]);
    $context = ClaimedRun::start($performance, history: app(HistoryReader::class));

    $outcome = app(CheckRegistry::class)->get(CheckKey::Performance)->execute($context);

    expect($outcome)->toBeInstanceOf(CheckOutcome::class);
    expect($outcome->metrics->toArray())->toMatchArray(['sample_count' => 1, 'previous_sample_count' => 1, 'average_response_time_in_ms' => 900.0]);
})->with(['00:00', '01:00', '30:00', '59:00', '59:59']);

it('classifies the twelve-hour interval boundary after visible failure and disabled suppression priorities', function (int $interval, string $state, string $action, string $cause): void {
    $uptime = Check::factory()->create(['interval_seconds' => $interval, 'enabled' => $state !== 'disabled', 'next_run_at' => now()->addDay()]);
    $performance = Check::factory()->for($uptime->site)->create(['type' => CheckKey::Performance]);
    if ($state === 'down') {
        Incident::factory()->for($uptime)->create();
    }
    $context = ClaimedRun::start($performance, history: app(HistoryReader::class));
    $outcome = app(CheckRegistry::class)->get(CheckKey::Performance)->execute($context);
    expect($outcome)->toBe($interval > 43200 ? NoResultReason::PerformanceUptimeIntervalTooLong : NoResultReason::PerformanceNoSamples);

    app(ResultRecorder::class)->record(new RecordableRun($performance->id, $context->claimToken, $context->startedAt, $context->startedAt->addSecond(), $outcome));

    expect(app(NoResultFreshness::class)->forCheck($performance->refresh(), CarbonImmutable::now()))->toBe(['action' => $action, 'reason' => $cause]);
    if ($cause === 'freshness.no_result.uptime_interval_too_long') {
        expect(__($cause, locale: 'en'))->toBe('No response-time data: the uptime check runs less often than every 12 hours.');
        expect(__($cause, locale: 'de'))->toBe('Keine Antwortzeiten: Die Erreichbarkeitsprüfung läuft seltener als alle 12 Stunden.');
    }
})->with([
    'exactly twelve hours' => [43200, 'up', 'counts', 'freshness.no_result.uptime_no_measurements'],
    'one second over' => [43201, 'up', 'counts', 'freshness.no_result.uptime_interval_too_long'],
    'long interval and Down' => [43201, 'down', 'suppressed', 'uptime_incident'],
    'long interval and disabled' => [43201, 'disabled', 'counts', 'freshness.no_result.uptime_disabled'],
]);

it('accepts uptime settings up to sixty days and rejects sixty-one days', function (): void {
    $action = app(UpdateCheckConfig::class);
    expect($action->validateSchedule(['frequency_value' => 60, 'frequency_unit' => 'day'], true))->toMatchArray(['frequency_value' => 60, 'frequency_unit' => 'day']);
    expect(fn () => $action->validateSchedule(['frequency_value' => 61, 'frequency_unit' => 'day'], true))->toThrow(ValidationException::class);
});

it('resets the episode only while real daily cron tasks are waiting for their first due time', function (): void {
    $check = Check::factory()->create(['type' => CheckKey::Cron, 'awaiting_result_since' => now()->subDays(2), 'lost_attempts' => 3]);
    app(CronDefinitions::class)->create($check->id, ['name' => 'Daily backup', 'type' => 'simple', 'frequency_in_minutes' => 1440, 'grace_time_in_minutes' => 5]);
    $context = ClaimedRun::start($check);
    $outcome = app(CheckRegistry::class)->get(CheckKey::Cron)->execute($context);
    expect($outcome)->toBe(NoResultReason::CronTasksPending);

    app(ResultRecorder::class)->record(new RecordableRun($check->id, $context->claimToken, $context->startedAt, $context->startedAt->addSecond(), $outcome));

    expect($check->refresh()->awaiting_result_since)->toBeNull();
    expect($check->lost_attempts)->toBe(0);
});

it('counts a no-result gap behind a disabled uptime check even when its last result was a Check error', function (): void {
    $uptime = Check::factory()->create(['enabled' => false, 'latest_run_result' => CheckResult::Errored, 'next_run_at' => now()->addDay()]);
    $performance = Check::factory()->for($uptime->site)->create(['type' => CheckKey::Performance]);
    $context = ClaimedRun::start($performance, history: app(HistoryReader::class));
    $outcome = app(CheckRegistry::class)->get(CheckKey::Performance)->execute($context);
    app(ResultRecorder::class)->record(new RecordableRun($performance->id, $context->claimToken, $context->startedAt, $context->startedAt->addSecond(), $outcome));

    expect(app(NoResultFreshness::class)->forCheck($performance->refresh(), CarbonImmutable::now()))
        ->toBe(['action' => 'counts', 'reason' => 'freshness.no_result.uptime_disabled']);
});

it('does not open a freshness issue when the outage ends before the next performance attempt', function (): void {
    Mail::fake();
    $uptime = Check::factory()->create(['next_run_at' => now()->addDay()]);
    $performance = Check::factory()->for($uptime->site)->create(['type' => CheckKey::Performance,
        'awaiting_result_since' => now()->subHours(2), 'next_run_at' => now()->addDay()]);
    $incident = Incident::factory()->for($uptime)->create();
    $context = ClaimedRun::start($performance, history: app(HistoryReader::class));
    $outcome = app(CheckRegistry::class)->get(CheckKey::Performance)->execute($context);
    app(ResultRecorder::class)->record(new RecordableRun($performance->id, $context->claimToken, $context->startedAt, $context->startedAt->addSecond(), $outcome));
    $this->travelTo(now()->addMinutes(2));
    $incident->update(['resolved_at' => now()]);
    freshnessHeartbeats();
    $healthy = Check::factory()->create(['next_run_at' => now()->addDay()]);
    CheckRun::factory()->for($healthy)->create();

    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();

    expect(app(NoResultFreshness::class)->forCheck($performance->refresh(), CarbonImmutable::now()))
        ->toBe(['action' => 'suppressed', 'reason' => 'uptime_incident']);
    $this->assertDatabaseMissing('system_issues', ['kind' => 'check_not_fresh', 'scope' => (string) $performance->id]);
    $owner = systemIssueRecipient('Owner');
    $this->actingAs($owner)->get('/sites/'.$performance->site_id)->assertInertia(fn (Assert $page): Assert => $page
        ->where('site.checks', fn ($strip): bool => collect($strip)->firstWhere('id', $performance->id)['freshness_suppressed_by'] === 'down'));
});

it('finds the freshness issue on the system page when unrelated issues sort first', function (): void {
    Mail::fake();
    $owner = systemIssueRecipient('Owner', attributes: ['locale' => 'en']);
    $uptime = Check::factory()->create(['enabled' => false, 'next_run_at' => now()->addDay()]);
    $performance = Check::factory()->for($uptime->site)->create(['type' => CheckKey::Performance,
        'awaiting_result_since' => now()->subHour(), 'next_run_at' => now()->addDay()]);
    $context = ClaimedRun::start($performance, history: app(HistoryReader::class));
    $outcome = app(CheckRegistry::class)->get(CheckKey::Performance)->execute($context);
    app(ResultRecorder::class)->record(new RecordableRun($performance->id, $context->claimToken, $context->startedAt, $context->startedAt->addSecond(), $outcome));
    freshnessHeartbeats();
    $healthy = Check::factory()->create(['next_run_at' => now()->addDay()]);
    CheckRun::factory()->for($healthy)->create();
    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
    // An older Warn issue and a newer Down issue, as a host disk alert or a stale scheduler would open them.
    SystemIssue::query()->create(['kind' => 'disk_space_low', 'scope' => '/backups', 'opened_at' => now()->subDay(),
        'details' => ['path' => '/backups', 'severity' => 'warning', 'free_percent' => '15.00', 'free_bytes' => 15, 'total_bytes' => 100]]);
    SystemIssue::query()->create(['kind' => 'scheduler_stale', 'scope' => '', 'opened_at' => now()->addMinute(), 'details' => []]);

    $response = $this->actingAs($owner)->get('/settings/system');

    expect(freshnessPageIssue($response, $performance->id)['details']['no_result_cause'])->toBe('freshness.no_result.uptime_disabled');
    $order = array_map(fn (array $issue): string => $issue['kind'], $response->viewData('page')['props']['systemPage']['health']['systemIssues']);
    expect($order)->toBe(['scheduler_stale', 'disk_space_low', 'check_not_fresh']);
});

it('uses every suppression reason consistently in the evaluator API and localized check page', function (FreshnessSuppression $reason): void {
    Mail::fake();
    $owner = systemIssueRecipient('Owner', attributes: ['locale' => 'en']);
    $uptime = Check::factory()->create(['next_run_at' => now()->addDay()]);
    $check = Check::factory()->for($uptime->site)->create(['type' => CheckKey::Performance,
        'awaiting_result_since' => now()->subHours(2), 'latest_run_ended_at' => now()->subHours(3),
        'latest_run_result' => CheckResult::Succeeded]);
    match ($reason) {
        FreshnessSuppression::Down => Incident::factory()->for($uptime)->create(['cause' => IncidentCause::Failed, 'first_failure_at' => now()->subHours(3)->addMinutes(15)]),
        FreshnessSuppression::UptimeError => Incident::factory()->for($uptime)->create(['cause' => IncidentCause::Errored, 'first_failure_at' => now()->subHours(3)->addMinutes(30)]),
        FreshnessSuppression::Maintenance => MaintenancePeriod::factory()->create(['site_id' => $uptime->site_id,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(), 'mute_alerts' => true]),
        FreshnessSuppression::Snooze => [
            $uptime->snoozes()->create(['starts_at' => now()->subMinutes(15), 'ends_at' => now()->addHour(), 'snoozed_by' => 'fixture', 'source' => 'ui']),
            $uptime->snoozes()->create(['starts_at' => now()->subMinutes(45), 'ends_at' => now()->addHour(), 'snoozed_by' => 'fixture', 'source' => 'ui']),
        ],
    };
    $context = ClaimedRun::start($check, history: app(HistoryReader::class));
    $outcome = app(CheckRegistry::class)->get(CheckKey::Performance)->execute($context);
    app(ResultRecorder::class)->record(new RecordableRun($check->id, $context->claimToken, $context->startedAt, $context->startedAt->addSecond(), $outcome));
    freshnessHeartbeats();
    CheckRun::factory()->for($uptime)->create();

    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();

    $this->assertDatabaseMissing('system_issues', ['kind' => 'check_not_fresh', 'scope' => (string) $check->id, 'resolved_at' => null]);
    $this->withToken($owner->createToken('suppression-read', ['monitors:read'])->plainTextToken);
    $checks = collect($this->getJson('/api/monitors/'.$check->site_id)->json('checks'))->keyBy('id');
    expect($checks[$check->id])->toMatchArray(['freshness_suppressed_by' => $reason->value, 'display_result' => 'no_current_data', 'latest_run_result' => 'succeeded']);
    expect($checks[$check->id]['freshness']['state'])->toBe('not_fresh');
    $since = match ($reason) {
        FreshnessSuppression::Down => '2026-10-21T09:15:00+00:00',
        FreshnessSuppression::UptimeError => '2026-10-21T09:30:00+00:00',
        FreshnessSuppression::Maintenance => '2026-10-21T11:00:00+00:00',
        FreshnessSuppression::Snooze => '2026-10-21T11:15:00+00:00',
    };
    expect($checks[$check->id]['freshness_suppressed_since'])->toBe($since);
    if ($reason === FreshnessSuppression::Down) {
        expect($checks[$check->id]['performance_unavailable']['params']['date'])->toBe($since);
    }
    $texts = match ($reason) {
        FreshnessSuppression::Down => ['en' => 'No response-time data while the site is down (since {{date}})', 'de' => 'Keine Antwortzeiten, solange die Website ausgefallen ist (seit {{date}})'],
        FreshnessSuppression::Maintenance => ['en' => 'No response-time data during maintenance (since {{date}})', 'de' => 'Keine Antwortzeiten während der Wartung (seit {{date}})'],
        FreshnessSuppression::Snooze => ['en' => 'No response-time data while alerts are snoozed (since {{date}})', 'de' => 'Keine Antwortzeiten, solange Alarme pausiert sind (seit {{date}})'],
        FreshnessSuppression::UptimeError => ['en' => 'No response-time data while the uptime check reports an error (since {{date}})', 'de' => 'Keine Antwortzeiten, solange die Erreichbarkeitsprüfung einen Prüffehler meldet (seit {{date}})'],
    };
    foreach ($texts as $locale => $text) {
        $owner->update(['locale' => $locale]);
        $response = $this->actingAs($owner)->get('/sites/'.$check->site_id.'/checks/'.$check->id)
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('freshness_suppressed_by', $reason->value)
                ->where('site.checks', fn ($strip): bool => collect($strip)->firstWhere('id', $check->id)['freshness_suppressed_by'] === $reason->value)
                ->where('freshness_suppression.key', 'freshness.suppressed.performance.'.$reason->value)
                ->where('freshness_suppression.params.date', $since)
                ->where('freshness_suppressed_since', $checks[$check->id]['freshness_suppressed_since'])
                ->where('schedule.freshness.state', 'not_fresh')->where('check.checked_at', '2026-10-21T09:00:00.000000Z'));
        if ($reason === FreshnessSuppression::Down) {
            $response->assertInertia(fn (Assert $page): Assert => $page->where('performance_unavailable.params.date', $since));
        }
        $key = $response->viewData('page')['props']['freshness_suppression']['key'];
        expect(__($key, locale: $locale))->toBe($text);
        expect(__('No current data', locale: $locale))->toBe($locale === 'de' ? 'Keine aktuellen Daten' : 'No current data');
    }
})->with(FreshnessSuppression::cases());

it('keeps an unexplained gap generic with its stored result and null suppression', function (): void {
    Mail::fake();
    $owner = systemIssueRecipient('Owner', attributes: ['locale' => 'en']);
    $uptime = Check::factory()->create(['next_run_at' => now()->addDay()]);
    $check = Check::factory()->for($uptime->site)->create(['type' => CheckKey::Performance,
        'awaiting_result_since' => now()->subHours(2), 'latest_run_result' => CheckResult::Succeeded]);
    $context = ClaimedRun::start($check, history: app(HistoryReader::class));
    $outcome = app(CheckRegistry::class)->get(CheckKey::Performance)->execute($context);
    app(ResultRecorder::class)->record(new RecordableRun($check->id, $context->claimToken, $context->startedAt, $context->startedAt->addSecond(), $outcome));
    freshnessHeartbeats();
    CheckRun::factory()->for($uptime)->create();

    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();

    $this->assertDatabaseHas('system_issues', ['kind' => 'check_not_fresh', 'scope' => (string) $check->id, 'resolved_at' => null]);
    $this->withToken($owner->createToken('gap-read', ['monitors:read'])->plainTextToken);
    $checks = collect($this->getJson('/api/monitors/'.$check->site_id)->json('checks'))->keyBy('id');
    expect($checks[$check->id])->toMatchArray(['freshness_suppressed_by' => null, 'freshness_suppressed_since' => null, 'display_result' => 'succeeded']);
    expect($checks[$check->id]['freshness']['state'])->toBe('not_fresh');
    $this->actingAs($owner)->get('/sites/'.$check->site_id.'/checks/'.$check->id)->assertInertia(fn (Assert $page): Assert => $page
        ->where('freshness_suppressed_by', null)->where('freshness_suppression', null)->where('schedule.freshness.state', 'not_fresh'));
});

it('uses the latest errored uptime result end when no uptime incident exists', function (): void {
    $owner = systemIssueRecipient('Owner', attributes: ['locale' => 'en']);
    $uptime = Check::factory()->create(['next_run_at' => now()->addDay()]);
    $run = CheckRun::factory()->for($uptime)->create(['result' => RunResult::Errored,
        'started_at' => now()->subMinutes(31), 'ended_at' => now()->subMinutes(30), 'summary' => 'checks.engine.errored']);
    $uptime->update(['latest_run_id' => $run->id, 'latest_run_started_at' => $run->started_at,
        'latest_run_ended_at' => $run->ended_at, 'latest_run_result' => CheckResult::Errored, 'summary' => $run->summary]);
    $check = Check::factory()->for($uptime->site)->create(['type' => CheckKey::Performance, 'awaiting_result_since' => now()->subHours(2)]);
    $context = ClaimedRun::start($check, history: app(HistoryReader::class));
    $outcome = app(CheckRegistry::class)->get(CheckKey::Performance)->execute($context);
    app(ResultRecorder::class)->record(new RecordableRun($check->id, $context->claimToken, $context->startedAt, $context->startedAt->addSecond(), $outcome));
    $this->withToken($owner->createToken('latest-error', ['monitors:read'])->plainTextToken);

    $checks = collect($this->getJson('/api/monitors/'.$check->site_id)->json('checks'))->keyBy('id');

    // Without an incident the source has no reliable start: it is the latest error, never a moving "since".
    expect($checks[$check->id])->toMatchArray(['freshness_suppressed_by' => 'uptime_error', 'freshness_suppressed_since' => null,
        'freshness_latest_error_at' => '2026-10-21T11:30:00+00:00']);
    $this->actingAs($owner)->get('/sites/'.$check->site_id.'/checks/'.$check->id)->assertInertia(fn (Assert $page): Assert => $page
        ->where('freshness_suppressed_by', 'uptime_error')
        ->where('freshness_suppressed_since', null)
        ->where('freshness_latest_error_at', $checks[$check->id]['freshness_latest_error_at'])
        ->where('freshness_suppression.key', 'freshness.suppressed.performance.uptime_error_latest')
        ->where('freshness_suppression.params.date', $checks[$check->id]['freshness_latest_error_at']));
    expect(__('freshness.suppressed.performance.uptime_error_latest', locale: 'en'))
        ->toBe('No response-time data: the uptime check reported an error (latest at {{date}})');
    expect(__('freshness.suppressed.performance.uptime_error_latest', locale: 'de'))
        ->toBe('Keine Antwortzeiten: Die Erreichbarkeitsprüfung meldete einen Prüffehler (zuletzt um {{date}})');
});

it('projects suppressed and unexplained gaps consistently on overview tabs list and API', function (): void {
    $owner = systemIssueRecipient('Owner', attributes: ['locale' => 'en']);
    $uptime = Check::factory()->create(['next_run_at' => now()->addDay()]);
    $suppressed = Check::factory()->for($uptime->site)->create(['type' => CheckKey::Performance,
        'awaiting_result_since' => now()->subHours(2), 'latest_run_result' => CheckResult::Succeeded]);
    Incident::factory()->for($uptime)->create(['cause' => IncidentCause::Failed]);
    $gap = Check::factory()->create(['type' => CheckKey::Performance,
        'awaiting_result_since' => now()->subHours(2), 'latest_run_result' => CheckResult::Succeeded]);
    $this->actingAs($owner);

    foreach ([$suppressed, $gap] as $check) {
        $expected = $check->id === $suppressed->id ? 'down' : null;
        foreach (['/sites/'.$check->site_id, '/sites/'.$check->site_id.'/checks/'.$check->id] as $url) {
            $response = $this->get($url);
            $checks = collect($response->viewData('page')['props']['site']['checks'])->keyBy('id');
            expect($checks[$check->id])->toMatchArray(['not_fresh' => true, 'freshness_suppressed_by' => $expected]);
        }
        $this->withToken($owner->createToken('strip-read', ['monitors:read'])->plainTextToken);
        $checks = collect($this->getJson('/api/monitors/'.$check->site_id)->json('checks'))->keyBy('id');
        expect($checks[$check->id]['freshness_suppressed_by'])->toBe($expected);
    }
    $apiChecks = collect($this->getJson('/api/monitors')->json('data'))->flatMap(fn (array $site): array => $site['checks'])->keyBy('id');
    expect($apiChecks[$suppressed->id]['freshness_suppressed_by'])->toBe('down');
    expect($apiChecks[$gap->id]['freshness_suppressed_by'])->toBeNull();
    $version = $this->actingAs($owner)->get('/sites')->inertiaPage()['version'];
    $response = $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Inertia-Partial-Component' => 'sites/index', 'X-Inertia-Partial-Data' => 'sites'])->get('/sites');
    $checks = collect($response->json('props.sites'))->flatMap(fn (array $site): array => $site['checks'])->keyBy('id');
    expect($checks[$suppressed->id])->toMatchArray(['not_fresh' => true, 'freshness_suppressed_by' => 'down']);
    expect($checks[$gap->id])->toMatchArray(['not_fresh' => true, 'freshness_suppressed_by' => null]);
});

it('keeps sites-list gap lookup queries constant across sites and checks per page', function (): void {
    config(['heptapod.browser.enabled' => true]);
    $owner = systemIssueRecipient('Owner', attributes: ['locale' => 'en']);
    $counts = [];
    foreach ([[5, 2], [25, 10]] as [$siteCount, $checkCount]) {
        $client = Client::factory()->create();
        $sites = Site::factory()->for($client)->count($siteCount)->create();
        foreach ($sites as $site) {
            foreach (array_slice(CheckKey::cases(), 0, $checkCount) as $key) {
                $check = Check::factory()->for($site)->create(['type' => $key, 'awaiting_result_since' => now()->subDay(),
                    'next_run_at' => now()->addDay(), 'latest_run_result' => CheckResult::Succeeded]);
                CheckRunAttempt::query()->create(['check_id' => $check->id, 'claim_token' => Str::uuid()->toString(),
                    'phase' => 'done', 'queue' => 'checks-fast', 'requested_at' => now()->subHour(), 'finished_at' => now()->subMinutes(59)]);
            }
        }
        $url = '/sites?filter[client_id]='.$client->id;
        $version = $this->actingAs($owner)->withoutHeaders(['X-Inertia', 'X-Inertia-Version', 'X-Inertia-Partial-Component', 'X-Inertia-Partial-Data'])->get('/sites')->inertiaPage()['version'];
        $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Inertia-Partial-Component' => 'sites/index', 'X-Inertia-Partial-Data' => 'sites'])->get($url);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->get($url);

        DB::disableQueryLog();
        $counts[] = count(DB::getQueryLog());
        expect($response->json('props.sites'))->toHaveCount($siteCount);
        expect(collect($response->json('props.sites'))->flatMap(fn (array $site): array => $site['checks'])->where('not_fresh', true))->toHaveCount($siteCount * $checkCount);
    }
    expect($counts[1])->toBe($counts[0]);
});

it('keeps sites-list planned suppression queries constant across page sizes', function (string $source): void {
    config(['heptapod.browser.enabled' => true]);
    $owner = systemIssueRecipient('Owner', attributes: ['locale' => 'en']);
    $counts = [];
    foreach ([[5, 2], [25, 10]] as [$siteCount, $checkCount]) {
        $client = Client::factory()->create();
        $sites = Site::factory()->for($client)->count($siteCount)->create();
        if ($source === 'maintenance') {
            MaintenancePeriod::factory()->create(['site_id' => null, 'target_type' => 'client', 'target_id' => $client->id,
                'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(), 'mute_alerts' => true]);
        }
        $expected = [];
        foreach ($sites as $index => $site) {
            $keys = [CheckKey::Uptime, CheckKey::Performance, ...array_values(array_filter(CheckKey::cases(), fn (CheckKey $key): bool => ! in_array($key, [CheckKey::Uptime, CheckKey::Performance], true)))];
            foreach (array_slice($keys, 0, $checkCount) as $key) {
                $check = Check::factory()->for($site)->create(['type' => $key, 'awaiting_result_since' => now()->subDay(),
                    'next_run_at' => now()->addDay(), 'latest_run_result' => CheckResult::Succeeded]);
                CheckRunAttempt::query()->create(['check_id' => $check->id, 'claim_token' => Str::uuid()->toString(),
                    'phase' => 'done', 'queue' => 'checks-fast', 'requested_at' => now()->subHour(), 'finished_at' => now()->subMinutes(59),
                    'note' => $key === CheckKey::Performance ? 'no_result:performance_no_samples' : null]);
                if ($key === CheckKey::Performance) {
                    $snoozed = $source === 'snooze' && $index % 2 === 0;
                    if ($snoozed) {
                        $check->snoozes()->create(['starts_at' => now()->subHour(), 'ends_at' => now()->addHour(), 'snoozed_by' => 'fixture', 'source' => 'ui']);
                    }
                    $expected[$check->id] = $source === 'maintenance' ? 'maintenance' : ($snoozed ? 'snooze' : null);
                }
            }
        }
        $url = '/sites?filter[client_id]='.$client->id;
        $version = $this->actingAs($owner)->withoutHeaders(['X-Inertia', 'X-Inertia-Version', 'X-Inertia-Partial-Component', 'X-Inertia-Partial-Data'])->get('/sites')->inertiaPage()['version'];
        $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Inertia-Partial-Component' => 'sites/index', 'X-Inertia-Partial-Data' => 'sites'])->get($url);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->get($url);

        DB::disableQueryLog();
        $counts[] = count(DB::getQueryLog());
        expect($response->json('props.sites'))->toHaveCount($siteCount);
        $checks = collect($response->json('props.sites'))->flatMap(fn (array $site): array => $site['checks'])->keyBy('id');
        foreach ($expected as $id => $reason) {
            expect($checks[$id]['freshness_suppressed_by'])->toBe($reason);
        }
    }
    expect($counts[1])->toBe($counts[0]);
})->with(['maintenance', 'snooze']);

it('preserves planned suppression decisions and source starts inside a page snapshot', function (string $scenario, ?string $reason, ?string $since): void {
    $client = Client::factory()->create();
    $site = Site::factory()->for($client)->create();
    $check = Check::factory()->for($site)->create(['type' => CheckKey::Performance]);
    $subject = new SuppressionSubject($check->id);
    if (str_starts_with($scenario, 'snooze')) {
        $attributes = ['starts_at' => now()->subHour(), 'ends_at' => now()->addHour(), 'snoozed_by' => 'fixture', 'source' => 'ui'];
        if (in_array($scenario, ['snooze component', 'snooze other component'], true)) {
            $attributes += ['component_type' => SuppressionComponent::Cron->value, 'component_id' => 7];
            $subject = new SuppressionSubject($check->id, SuppressionComponent::Cron, $scenario === 'snooze component' ? 7 : 8);
        }
        if ($scenario === 'snooze unsnoozed') {
            $attributes['unsnoozed_at'] = now()->subMinute();
        }
        if ($scenario === 'snooze unsnoozed later') {
            $attributes['unsnoozed_at'] = now()->addMinute();
        }
        $check->snoozes()->create($attributes);
        if ($scenario === 'snooze overlapping') {
            $check->snoozes()->create(array_replace($attributes, ['starts_at' => now()->subHours(2)]));
        }
    } else {
        $attributes = ['site_id' => $site->id, 'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(), 'mute_alerts' => true];
        if (in_array($scenario, ['client', 'excluded', 'excluded later'], true)) {
            $attributes = array_replace($attributes, ['site_id' => null, 'target_type' => 'client', 'target_id' => $client->id]);
        }
        if ($scenario === 'tag') {
            $site->attachTag('snapshot-target', 'sites');
            $attributes = array_replace($attributes, ['site_id' => null, 'target_type' => 'tag', 'target_id' => $site->tags()->sole()->id]);
        }
        if ($scenario === 'unmuted') {
            $attributes['mute_alerts'] = false;
        }
        if ($scenario === 'ended') {
            $attributes['ends_at'] = now();
        }
        if ($scenario === 'not started') {
            $attributes['starts_at'] = now()->addMicrosecond();
        }
        $period = MaintenancePeriod::factory()->create($attributes);
        if (in_array($scenario, ['excluded', 'excluded later'], true)) {
            DB::table('maintenance_site_exclusions')->insert(['maintenance_period_id' => $period->id, 'site_id' => $site->id,
                'left_at' => ($scenario === 'excluded' ? now() : now()->addMicrosecond())->format('Y-m-d H:i:s.uP')]);
        }
        if ($scenario === 'overlapping') {
            MaintenancePeriod::factory()->create(array_replace($attributes, ['starts_at' => now()->subHours(2)]));
        }
        if ($scenario === 'maintenance over snooze') {
            $check->snoozes()->create(['starts_at' => now()->subHours(2), 'ends_at' => now()->addHour(), 'snoozed_by' => 'fixture', 'source' => 'ui']);
        }
    }
    $suppression = app(SnoozeNotificationSuppression::class);
    $at = CarbonImmutable::now('UTC');
    $fallback = $suppression->isSuppressed($subject, $at);

    $snapshot = $suppression->withPreloadedChecks(new Collection([$check]), $at,
        fn () => $suppression->isSuppressed($subject, $at));

    expect($snapshot->toArray())->toBe($fallback->toArray());
    expect($snapshot->reason)->toBe($reason);
    expect($snapshot->since?->toIso8601String())->toBe($since);
    expect($suppression->isSuppressed($subject, $at)->toArray())->toBe($fallback->toArray());
})->with([
    'site' => ['site', 'maintenance', '2026-10-21T11:00:00+00:00'],
    'client' => ['client', 'maintenance', '2026-10-21T11:00:00+00:00'],
    'tag' => ['tag', 'maintenance', '2026-10-21T11:00:00+00:00'],
    'excluded at boundary' => ['excluded', null, null],
    'excluded later' => ['excluded later', 'maintenance', '2026-10-21T11:00:00+00:00'],
    'unmuted' => ['unmuted', null, null],
    'ended at boundary' => ['ended', null, null],
    'not started' => ['not started', null, null],
    'component' => ['snooze component', 'snooze', '2026-10-21T11:00:00+00:00'],
    'other component' => ['snooze other component', null, null],
    'whole check' => ['snooze whole', 'snooze', '2026-10-21T11:00:00+00:00'],
    'unsnoozed' => ['snooze unsnoozed', null, null],
    'unsnoozed later' => ['snooze unsnoozed later', 'snooze', '2026-10-21T11:00:00+00:00'],
    'overlapping windows' => ['overlapping', 'maintenance', '2026-10-21T10:00:00+00:00'],
    'overlapping snoozes' => ['snooze overlapping', 'snooze', '2026-10-21T10:00:00+00:00'],
    'maintenance precedes snooze' => ['maintenance over snooze', 'maintenance', '2026-10-21T11:00:00+00:00'],
]);

it('isolates planned suppression snapshots by time subject nesting and exceptions', function (): void {
    $check = Check::factory()->create();
    $other = Check::factory()->create();
    $check->snoozes()->create(['starts_at' => now()->subHour(), 'ends_at' => now()->addMicrosecond(), 'snoozed_by' => 'fixture', 'source' => 'ui']);
    $suppression = app(SnoozeNotificationSuppression::class);
    $at = CarbonImmutable::now('UTC');
    $subject = new SuppressionSubject($check->id);
    $checks = new Collection([$check]);

    expect(fn () => $suppression->withPreloadedChecks($checks, $at, function () use ($suppression, $subject, $other, $at): void {
        expect($suppression->isSuppressed($subject, $at)->reason)->toBe('snooze');
        expect($suppression->isSuppressed($subject, $at->addMicrosecond())->suppressed)->toBeFalse();
        expect($suppression->isSuppressed(new SuppressionSubject($other->id), $at)->suppressed)->toBeFalse();
        $suppression->withPreloadedChecks(new Collection([$other]), $at, function () use ($suppression, $subject, $at): void {
            expect($suppression->isSuppressed($subject, $at)->reason)->toBe('snooze');
        });
        expect($suppression->isSuppressed($subject, $at)->reason)->toBe('snooze');
        throw new LogicException('leave snapshot');
    }))->toThrow(LogicException::class, 'leave snapshot');
    DB::table('check_snoozes')->where('check_id', $check->id)->delete();
    expect($suppression->isSuppressed($subject, $at)->suppressed)->toBeFalse();
});

/** @return Closure(?int): ?CheckRun */
function recoveryBandStep(Check $performance, Check $uptime): Closure
{
    return function (?int $milliseconds) use ($performance, $uptime): ?CheckRun {
        test()->travelTo(CarbonImmutable::now()->addMinutes(61));
        if ($milliseconds !== null) {
            foreach ([1, 2, 3] as $minute) {
                CheckRun::factory()->for($uptime)->create(['started_at' => now()->subMinutes($minute)->subSecond(),
                    'ended_at' => now()->subMinutes($minute), 'metrics' => ['total_time_in_seconds' => $milliseconds / 1000]]);
            }
        }
        Bus::fake([RunCheck::class]);
        expect(app(ClaimService::class)->runNow($performance->id, RunOrigin::System))->not->toBeNull();
        $performance->refresh();
        app(CheckExecutor::class)->execute($performance->id, $performance->claim_token,
            app(CheckRegistry::class)->get(CheckKey::Performance)->definition()->timeout);
        $performance->refresh();

        return CheckRun::query()->where('check_id', $performance->id)->where('ended_at', CarbonImmutable::now())->first();
    };
}

it('holds a performance warning through oscillation inside the recovery band', function (): void {
    $uptime = Check::factory()->create(['next_run_at' => now()->addDay()]);
    $performance = Check::factory()->for($uptime->site)->create(['type' => CheckKey::Performance]);
    $step = recoveryBandStep($performance, $uptime);
    foreach ([2005, 2005, 2005, 1995, 1995, 1995, 2005, 1995, 1995, 1995] as $index => $milliseconds) {
        $run = $step($milliseconds);
        expect($run->result)->toBe($index < 2 ? RunResult::Succeeded : RunResult::Warning);
    }
    expect($run->summary)->toBe('performance.recovery_band');
    expect(Event::query()->where('check_id', $performance->id)->where('type', 'check.result_changed')->where('to_result', CheckResult::Warning)->count())->toBe(1);
    expect(Event::query()->where('check_id', $performance->id)->where('type', 'check.result_changed')->where('from_result', CheckResult::Warning)->count())->toBe(0);
});

it('recovers a performance warning only after three samples clear the recovery band', function (): void {
    $uptime = Check::factory()->create(['next_run_at' => now()->addDay()]);
    $performance = Check::factory()->for($uptime->site)->create(['type' => CheckKey::Performance]);
    $step = recoveryBandStep($performance, $uptime);
    foreach ([2005, 2005, 2005, 1890, 1890, 1890] as $index => $milliseconds) {
        $run = $step($milliseconds);
        expect($run->result)->toBe(in_array($index, [2, 3, 4], true) ? RunResult::Warning : RunResult::Succeeded);
    }
    expect($run->summary)->toBe('performance.recovered_below');
    expect($run->summary_params)->toMatchArray(['median' => 1890, 'recovery_below' => 1900]);
    $recovery = Event::query()->where('check_id', $performance->id)->where('type', 'check.result_changed')->where('from_result', CheckResult::Warning)->where('to_result', CheckResult::Succeeded)->sole();
    $run->delete();
    $context = app(BuildAlertMailContext::class)($recovery, app(NotificationEventTypes::class)->severity($recovery), [], [], NotificationDestination::factory()->create());
    $facts = array_values(array_filter($context?->blocks ?? [], static fn (EvidenceBlock $block): bool => $block instanceof FactsBlock));
    expect($facts)->toHaveCount(1);
    expect(array_map(static fn (array $row): array => [$row['label'], $row['value']->text], $facts[0]->rows))
        ->toBe([['60-min median', '1,890 ms'], ['Recovers below', '1,900 ms']]);
});

it('recovers a performance warning at exactly the recovery bound for a non-default margin', function (): void {
    $uptime = Check::factory()->create(['next_run_at' => now()->addDay()]);
    $performance = Check::factory()->for($uptime->site)->create(['type' => CheckKey::Performance,
        'config' => app(CheckRegistry::class)->get(CheckKey::Performance)->definition()->configSchema->validateConfig(['recovery_margin_percentage' => 7])]);
    $step = recoveryBandStep($performance, $uptime);
    foreach ([2005, 2005, 2005, 1860, 1860, 1860] as $index => $milliseconds) {
        $run = $step($milliseconds);
        expect($run->result)->toBe(in_array($index, [2, 3, 4], true) ? RunResult::Warning : RunResult::Succeeded);
    }
    expect($run->summary)->toBe('performance.recovered_below');
    expect($run->summary_params)->toMatchArray(['median' => 1860, 'recovery_below' => 1860]);
});

it('preserves the original performance flips with zero recovery margin and rejects margins over twenty', function (): void {
    $schema = app(CheckRegistry::class)->get(CheckKey::Performance)->definition()->configSchema;
    $uptime = Check::factory()->create(['next_run_at' => now()->addDay()]);
    $performance = Check::factory()->for($uptime->site)->create(['type' => CheckKey::Performance, 'config' => $schema->validateConfig(['recovery_margin_percentage' => 0])]);
    $step = recoveryBandStep($performance, $uptime);
    foreach ([2005, 2005, 2005, 1995, 1995, 1995, 2005, 1995, 1995, 1995] as $index => $milliseconds) {
        expect($step($milliseconds)->result)->toBe(in_array($index, [2, 3, 4], true) ? RunResult::Warning : RunResult::Succeeded);
    }
    expect(Event::query()->where('check_id', $performance->id)->where('type', 'check.result_changed')->where('from_result', CheckResult::Warning)->count())->toBe(1);
    try {
        $schema->validateConfig(['recovery_margin_percentage' => 21]);
        $this->fail('Margin over twenty accepted.');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['values.recovery_margin_percentage']);
    }
});

it('holds the relative performance recovery band until three samples clear it', function (): void {
    $uptime = Check::factory()->create(['next_run_at' => now()->addDay()]);
    $performance = Check::factory()->for($uptime->site)->create(['type' => CheckKey::Performance,
        'config' => app(CheckRegistry::class)->get(CheckKey::Performance)->definition()->configSchema->validateConfig(['threshold_in_ms' => 10000])]);
    foreach ([1, 2, 3] as $day) {
        foreach (range(13, 21) as $hour) {
            foreach ([1, 2, 3] as $minute) {
                $at = CarbonImmutable::now()->subDays($day)->setTime($hour, $minute);
                CheckRun::factory()->for($uptime)->create(['started_at' => $at->subSecond(), 'ended_at' => $at, 'metrics' => ['total_time_in_seconds' => 0.5]]);
            }
        }
    }
    $step = recoveryBandStep($performance, $uptime);
    foreach ([800, 800, 800, 730, 730, 730, 700, 700, 700] as $index => $milliseconds) {
        $run = $step($milliseconds);
        expect($run->result)->toBe($index >= 2 && $index < 8 ? RunResult::Warning : RunResult::Succeeded);
        if ($index === 5) {
            expect($run->summary)->toBe('performance.recovery_band');
            expect($run->summary_params)->toMatchArray(['baseline' => 500, 'p90' => 500, 'relative_threshold' => 750, 'recovery_below' => 712.5]);
        }
    }
    expect($run->summary)->toBe('performance.recovered_below');
    expect($run->summary_params['recovery_below'])->toBe(712.5);
    $recovery = Event::query()->where('check_id', $performance->id)->where('type', 'check.result_changed')->where('from_result', CheckResult::Warning)->sole();
    $run->delete();
    $context = app(BuildAlertMailContext::class)($recovery, app(NotificationEventTypes::class)->severity($recovery), [], [], NotificationDestination::factory()->create());
    $facts = array_values(array_filter($context?->blocks ?? [], static fn (EvidenceBlock $block): bool => $block instanceof FactsBlock));
    expect($facts)->toHaveCount(1);
    expect(array_map(static fn (array $row): array => [$row['label'], $row['value']->text], $facts[0]->rows))
        ->toBe([['60-min median', '700 ms'], ['Recovers below', '712 ms']]);
});

it('retains a performance warning and uptime outage without fresh recovery samples', function (): void {
    $uptime = Check::factory()->create(['next_run_at' => now()->addDay()]);
    $performance = Check::factory()->for($uptime->site)->create(['type' => CheckKey::Performance]);
    $step = recoveryBandStep($performance, $uptime);
    foreach ([2005, 2005, 2005] as $milliseconds) {
        $step($milliseconds);
    }
    $incident = Incident::factory()->for($uptime)->create(['cause' => IncidentCause::Failed]);
    $events = Event::query()->where('check_id', $performance->id)->where('type', 'check.result_changed')->count();

    expect($step(null))->toBeNull();

    expect($performance->refresh()->latest_run_result)->toBe(CheckResult::Warning);
    expect(CheckRunAttempt::query()->where('check_id', $performance->id)->where('note', 'no_result:performance_no_samples')->count())->toBe(1);
    expect(Event::query()->where('check_id', $performance->id)->where('type', 'check.result_changed')->count())->toBe($events);
    expect($incident->refresh()->resolved_at)->toBeNull();
    expect($incident->cause)->toBe(IncidentCause::Failed);
});
