<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Actions\BuildAlertMailContext;
use Heptapod\Addons\Channels\TemplateMail;
use Heptapod\Addons\Dns\DnsMetrics;
use Heptapod\Addons\Dns\DnsPersistence;
use Heptapod\Checks\CheckRunPresentations;
use Heptapod\Checks\Execution\ClaimService;
use Heptapod\Checks\Execution\RunOrigin;
use Heptapod\Checks\Recording\RecordableRun;
use Heptapod\Checks\Recording\ResultRecorder;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\CheckHeadline;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckOutcome;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\CheckRunPresentationData;
use Heptapod\Contracts\CheckRunPresentationPartData;
use Heptapod\Contracts\DnsLookupResult;
use Heptapod\Contracts\DnsResolver;
use Heptapod\Contracts\ExternalNetworkException;
use Heptapod\Contracts\ExternalTimeoutException;
use Heptapod\Contracts\Mail\MailTone;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Contracts\RunReference;
use Heptapod\Contracts\RunResult;
use Heptapod\Events\LiveSignal;
use Heptapod\Events\Notifications\NotificationEventTypes;
use Heptapod\Jobs\DispatchNotifications;
use Heptapod\Jobs\RunCheck;
use Heptapod\Jobs\SendNotification;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\CheckRunAttempt;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Testing\FakeDnsResolver;
use Heptapod\Testing\OffsetDatabaseClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class);

// Upper budget for recording one finished DNS run: 34 on d5c9d279, plus the recorder's lock-order read
// (the check's site and type, before NotificationLocks::checkForUpdate).
const RUN_FINISH_QUERIES = 35;

/** @param list<string> $categories */
function acceptDnsRoute(NotificationDestination $destination, array $categories): void
{
    DB::table('notification_preferences')->where('destination_id', $destination->id)->whereIn('category', $categories)->update(['mode' => 'immediate']);
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-22T12:00:00Z'));
    Queue::fake();
    app()->instance(DnsResolver::class, new FakeDnsResolver);
});

/** @param array<string, mixed> $config */
function acceptDnsCheck(array $config = []): Check
{
    $site = Site::factory()->create(['url' => 'https://www.example.com']);
    $schema = app(CheckRegistry::class)->get(CheckKey::Dns)->definition()->configSchema;

    return Check::factory()->create(['site_id' => $site->id, 'type' => CheckKey::Dns, 'failure_threshold' => 1,
        'config' => $schema->validateConfig($config)]);
}

/** @return array{host: string, ttl: int, class: string, type: string, ip: string} */
function acceptDnsAddress(string $ip = '192.0.2.1'): array
{
    return ['host' => 'www.example.com', 'ttl' => 300, 'class' => 'IN', 'type' => 'A', 'ip' => $ip];
}

/** @return array{host: string, ttl: int, class: string, type: string, pri: int, target: string} */
function acceptDnsMail(): array
{
    return ['host' => 'www.example.com', 'ttl' => 300, 'class' => 'IN', 'type' => 'MX', 'pri' => 10, 'target' => 'mail.example.com'];
}

/** @param list<array<string, mixed>> $records */
function acceptDnsAnswers(array $records, ?string $server = null, int $serial = 1): void
{
    $fake = app(DnsResolver::class);
    if (! $fake instanceof FakeDnsResolver) {
        throw new LogicException('DNS accept tests require the fake resolver.');
    }
    if ($server === null) {
        $fake->respondWith('example.com', 'NS', new DnsLookupResult(array_map(
            fn (string $name): array => ['host' => 'example.com', 'ttl' => 300, 'class' => 'IN', 'type' => 'NS', 'target' => $name],
            ['ns1.example.com', 'ns2.example.com'],
        ), [], null));
        acceptDnsAnswers($records, 'ns1.example.com');
        acceptDnsAnswers($records, 'ns2.example.com');
    } else {
        $fake->respondWith('example.com', 'SOA', new DnsLookupResult([
            ['host' => 'example.com', 'ttl' => 300, 'class' => 'IN', 'type' => 'SOA', 'serial' => $serial],
        ], [], $server), $server);
    }
    foreach (['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'CAA'] as $type) {
        $answers = array_values(array_filter($records, fn (array $record): bool => $record['type'] === $type));
        $fake->respondWith('www.example.com', $type, new DnsLookupResult($answers, [], $server), $server);
    }
}

function acceptDnsQueuedRun(Check $check): CheckRun
{
    $definition = app(CheckRegistry::class)->get(CheckKey::Dns)->definition();
    $job = new RunCheck($check->id, $check->refresh()->claim_token, $definition->timeout, $definition->queue);
    app()->call($job->handle(...));

    return $check->runs()->orderByDesc('ended_at')->orderByDesc('id')->firstOrFail();
}

function acceptDnsPoll(Check $check): CheckRun
{
    expect(app(ClaimService::class)->runNow($check->id, RunOrigin::System))->not->toBeNull();

    return acceptDnsQueuedRun($check);
}

function acceptDnsOwner(): User
{
    return User::factory()->owner()->create();
}

function acceptDnsObservation(Check $check, CheckRun $run): void
{
    Queue::fake();
    $url = '/sites/'.$check->site_id.'/checks/'.$check->id;
    test()->actingAs(acceptDnsOwner())->post($url.'/accept-dns', ['accepted_run_id' => $run->id])
        ->assertRedirect($url)->assertSessionHasNoErrors();
    Queue::assertPushed(RunCheck::class, 1);
}

function acceptDnsPendingReason(Check $check): ?string
{
    /** @var string|null */
    return DB::table('checks')->where('id', $check->id)->value('pending_reason');
}

/** Accepts a failed observation through the endpoint; the recheck job stays queued and is not worked. */
function acceptDnsPendingRecheck(): Check
{
    OffsetDatabaseClock::use(0);
    $check = acceptDnsCheck(['check_nameservers_in_sync' => true]);
    acceptDnsAnswers([acceptDnsAddress()]);
    acceptDnsPoll($check);
    acceptDnsAnswers([acceptDnsAddress('192.0.2.2')]);
    acceptDnsAnswers([acceptDnsAddress('192.0.2.2')], 'ns2.example.com', serial: 2);
    $failed = acceptDnsPoll($check);
    expect($failed->result)->toBe(RunResult::Failed);
    test()->travel(1)->seconds();
    $signals = acceptDnsListenForSignals();
    $committedLevel = DB::transactionLevel();
    acceptDnsObservation($check, $failed);
    // Open sites, site and check pages reload on state.changed; it fires only once the acceptance has committed.
    expect($signals->all())->toContain(['state.changed', ['client' => $check->site->client_id, 'site' => $check->site_id, 'check' => $check->id], $committedLevel]);

    return $check->refresh();
}

/** @return Collection<int, array{string, array<string, int>, int}> topic, ids and the transaction level at dispatch, for every LiveSignal from now on */
function acceptDnsListenForSignals(): Collection
{
    $signals = new Collection;
    EventFacade::listen(LiveSignal::class, static function (LiveSignal $signal) use ($signals): void {
        $signals->push([$signal->topic, $signal->ids, DB::transactionLevel()]);
    });

    return $signals;
}

/** The site-level state of the only site: check page header, site page header, sites list row, tiles and attention counts. */
function acceptDnsSiteState(Check $check, string $state): void
{
    $site = '/sites/'.$check->site_id;
    test()->get($site.'/checks/'.$check->id)->assertInertia(fn (Assert $page): Assert => $page->where('header.state', $state));
    test()->get($site)->assertInertia(fn (Assert $page): Assert => $page->where('header.state', $state));
    $attention = in_array($state, ['down', 'failed', 'check-error', 'warning'], true) ? 1 : 0;
    test()->get('/sites')->assertInertia(fn (Assert $page): Assert => $page
        ->where('tiles.'.($state === 'check-error' ? 'check_errors' : $state), 1)
        ->where('tiles.pending', $state === 'pending' ? 1 : 0)->where('tiles.failed', $state === 'failed' ? 1 : 0)
        ->where('summary.attention', $attention)->where('needs_attention_total', $attention)
        ->loadDeferredProps(['list'], fn (Assert $list): Assert => $list->where('sites.0.state', $state)));
}

it('resolves the incident after accepting a disappeared record type as normal', function (): void {
    $check = acceptDnsCheck();
    $ipv6 = ['host' => 'www.example.com', 'ttl' => 300, 'class' => 'IN', 'type' => 'AAAA', 'ipv6' => '2001:db8::1'];
    acceptDnsAnswers([acceptDnsAddress(), $ipv6]);
    expect(acceptDnsPoll($check)->result)->toBe(RunResult::Succeeded);
    acceptDnsAnswers([acceptDnsAddress()]);
    $missing = acceptDnsPoll($check);
    expect($missing->result)->toBe(RunResult::Failed);
    $incident = Incident::query()->where('check_id', $check->id)->sole();
    expect($incident->resolved_at)->toBeNull();

    acceptDnsObservation($check, $missing);
    $recheck = acceptDnsQueuedRun($check);

    expect($recheck->result)->toBe(RunResult::Succeeded);
    expect($check->refresh()->latest_run_result->value)->toBe('succeeded');
    expect($incident->refresh()->resolved_at)->not->toBeNull();
    Event::query()->where('check_id', $check->id)->where('type', 'incident.resolved')->sole();
    $baseline = app(DnsPersistence::class)->baseline($check->id);
    expect($baseline['expected'])->toBe(['www.example.com|A']);
    expect(array_column($baseline['snapshot']['issues'], 'type'))->not->toContain('dns.issue.records_missing');
    expect(array_column($missing->evidence['issues'], 'type'))->toContain('dns.issue.records_missing');
});

it('does not repeat a change event after accepting a newly appeared type', function (): void {
    $check = acceptDnsCheck();
    acceptDnsAnswers([acceptDnsAddress()]);
    acceptDnsPoll($check);
    acceptDnsAnswers([acceptDnsAddress(), acceptDnsMail()]);
    $appeared = acceptDnsPoll($check);
    expect($appeared->result)->toBe(RunResult::Warning);
    expect(Event::query()->where('type', 'dns.records_changed')->count())->toBe(1);

    acceptDnsObservation($check, $appeared);

    expect(acceptDnsQueuedRun($check)->result)->toBe(RunResult::Succeeded);
    expect(Event::query()->where('type', 'dns.records_changed')->count())->toBe(1);
});

it('succeeds after accepting a changed record value', function (): void {
    $check = acceptDnsCheck();
    acceptDnsAnswers([acceptDnsAddress()]);
    acceptDnsPoll($check);
    acceptDnsAnswers([acceptDnsAddress('192.0.2.2')]);
    $changed = acceptDnsPoll($check);
    expect($changed->result)->toBe(RunResult::Warning);

    acceptDnsObservation($check, $changed);

    expect(acceptDnsQueuedRun($check)->result)->toBe(RunResult::Succeeded);
});

it('rejects a stale observation without changing the baseline or audit log', function (): void {
    $check = acceptDnsCheck();
    acceptDnsAnswers([acceptDnsAddress()]);
    $older = acceptDnsPoll($check);
    acceptDnsAnswers([acceptDnsAddress('192.0.2.2')]);
    acceptDnsPoll($check);
    $before = DB::table('dns_baselines')->where('check_id', $check->id)->sole();
    Queue::fake();

    $this->actingAs(acceptDnsOwner())->post('/sites/'.$check->site_id.'/checks/'.$check->id.'/accept-dns', ['accepted_run_id' => $older->id])
        ->assertSessionHasErrors(['accepted_run_id' => __('There are newer DNS results — please review again.')]);

    expect(DB::table('dns_baselines')->where('check_id', $check->id)->sole())->toEqual($before);
    expect(DB::table('activity_log')->where('event', 'dns.snapshot_accepted')->count())->toBe(0);
    Queue::assertNotPushed(RunCheck::class);
});

it('emits a new change event for a later deviation after accept', function (): void {
    $check = acceptDnsCheck();
    acceptDnsAnswers([acceptDnsAddress()]);
    acceptDnsPoll($check);
    acceptDnsAnswers([acceptDnsAddress('192.0.2.2')]);
    $changed = acceptDnsPoll($check);
    acceptDnsObservation($check, $changed);
    expect(acceptDnsQueuedRun($check)->result)->toBe(RunResult::Succeeded);
    acceptDnsAnswers([acceptDnsAddress('192.0.2.3')]);

    expect(acceptDnsPoll($check)->result)->toBe(RunResult::Warning);
    expect(Event::query()->where('type', 'dns.records_changed')->count())->toBe(2);
});

it('starts a new warning episode on accept even when nameservers remain out of sync', function (): void {
    $check = acceptDnsCheck(['check_nameservers_in_sync' => true]);
    acceptDnsAnswers([acceptDnsAddress()]);
    acceptDnsPoll($check);
    acceptDnsAnswers([acceptDnsAddress('192.0.2.2')], 'ns2.example.com');
    expect(acceptDnsPoll($check)->result)->toBe(RunResult::Warning);
    acceptDnsAnswers([acceptDnsAddress('192.0.2.2')], 'ns2.example.com', serial: 2);
    $outOfSync = acceptDnsPoll($check);
    expect($outOfSync->result)->toBe(RunResult::Failed);
    expect(DB::table('dns_baselines')->where('check_id', $check->id)->value('warning_episode_run_id'))->not->toBeNull();

    acceptDnsObservation($check, $outOfSync);
    $baseline = app(DnsPersistence::class)->baseline($check->id);
    expect(array_column($baseline['snapshot']['issues'], 'type'))->toBe(['dns.issue.nameservers_out_of_sync']);
    expect(acceptDnsQueuedRun($check)->result)->toBe(RunResult::Failed);
    acceptDnsAnswers([acceptDnsAddress('192.0.2.3')], 'ns2.example.com', serial: 2);
    expect(acceptDnsPoll($check)->result)->toBe(RunResult::Failed);

    expect(Event::query()->where('type', 'dns.records_changed')->count())->toBe(2);
});

it('keeps inherited expectations for vanished types in automatic accept mode', function (): void {
    $check = acceptDnsCheck(['accept_changes_automatically' => true]);
    acceptDnsAnswers([acceptDnsAddress()]);
    acceptDnsPoll($check);
    acceptDnsAnswers([acceptDnsAddress(), acceptDnsMail()]);
    expect(acceptDnsPoll($check)->result)->toBe(RunResult::Warning);
    acceptDnsAnswers([acceptDnsAddress()]);

    expect(acceptDnsPoll($check)->result)->toBe(RunResult::Failed);
    expect(acceptDnsPoll($check)->result)->toBe(RunResult::Failed);
    expect(app(DnsPersistence::class)->baseline($check->id)['expected'])->toContain('www.example.com|MX');
});

it('rolls back accept when an active lease prevents the recheck', function (string $locale, string $message, ?string $unavailableState): void {
    $check = acceptDnsCheck();
    acceptDnsAnswers([acceptDnsAddress()]);
    $latest = acceptDnsPoll($check);
    expect(app(ClaimService::class)->runNow($check->id, RunOrigin::System))->not->toBeNull();
    $before = DB::table('dns_baselines')->where('check_id', $check->id)->sole();
    $generation = DB::table('dns_baseline_generations')->where('check_id', $check->id)->value('generation');
    Queue::fake();

    if ($unavailableState === 'disabled') {
        $check->update(['enabled' => false]);
    } elseif ($unavailableState === 'paused') {
        $check->site->update(['paused_at' => now()]);
    } elseif ($unavailableState === 'paused_after_authorization') {
        // The real policy still decides; pause after it runs, when the controller already holds the site snapshot.
        Gate::after(static function (User $actor, string $ability) use ($check): void {
            if ($ability === 'update') {
                Site::query()->whereKey($check->site_id)->update(['paused_at' => now()]);
            }
        });
    }
    $owner = acceptDnsOwner();
    $owner->update(['locale' => $locale]);
    $signals = acceptDnsListenForSignals();
    $this->actingAs($owner)->post('/sites/'.$check->site_id.'/checks/'.$check->id.'/accept-dns', ['accepted_run_id' => $latest->id])
        ->assertSessionHasErrors(['check' => $message]);

    // The rolled-back acceptance announces nothing: neither the pending state nor the refused claim.
    expect($signals->all())->toBe([]);

    expect(DB::table('dns_baselines')->where('check_id', $check->id)->sole())->toEqual($before);
    expect(DB::table('dns_baseline_generations')->where('check_id', $check->id)->value('generation'))->toBe($generation);
    expect(DB::table('activity_log')->where('event', 'dns.snapshot_accepted')->count())->toBe(0);
    expect(acceptDnsPendingReason($check))->toBeNull();
    Queue::assertNotPushed(RunCheck::class);
})->with([
    'EN active lease' => ['en', 'A check is running right now. Try again in a few seconds.', null],
    'DE active lease' => ['de', 'Gerade läuft eine Prüfung. Versuchen Sie es in wenigen Sekunden erneut.', null],
    'disabled retains the original refusal' => ['en', 'This check is disabled, paused, or already has an active run.', 'disabled'],
    'paused retains the original refusal' => ['en', 'This check is disabled, paused, or already has an active run.', 'paused'],
    'pause after authorization retains the original refusal' => ['en', 'This check is disabled, paused, or already has an active run.', 'paused_after_authorization'],
]);

// Approved deviation from real entry points: an active lease blocks the accept endpoint, so the addon generation seam is driven directly.
it('fences a pre-accept observation at the addon generation seam', function (): void {
    $check = acceptDnsCheck(['accept_changes_automatically' => true]);
    acceptDnsAnswers([acceptDnsAddress()]);
    $latest = acceptDnsPoll($check);
    acceptDnsAnswers([acceptDnsAddress(), acceptDnsMail()]);
    $at = CarbonImmutable::now('UTC');
    $context = new CheckContext($check->site_id, $check->id, (int) DB::scalar("SELECT nextval('check_runs_id_seq')"),
        (string) Str::uuid(), $check->site->url, $at, $at->addSeconds(120), $check->executionConfig(), [], null);
    $outcome = app(CheckRegistry::class)->get(CheckKey::Dns)->execute($context);
    expect($outcome->result)->toBe(RunResult::Warning);

    acceptDnsObservation($check, $latest);
    $accepted = app(DnsPersistence::class)->baseline($check->id);
    expect($accepted['run_id'])->toBe($latest->id);
    expect($accepted['expected'])->toBe(['www.example.com|A']);
    app(DnsPersistence::class)->finalize(new RunReference($context->runId, $at), $context->claimToken);

    expect(app(DnsPersistence::class)->baseline($check->id))->toBe($accepted);
    acceptDnsAnswers([acceptDnsAddress()]);
    expect(acceptDnsQueuedRun($check)->result)->toBe(RunResult::Succeeded);
});

it('projects whether accepting changes records or inherited record types', function (bool $changed, bool $outOfSync, bool $wouldChange, array $issues, bool $proxyRotation = false): void {
    $check = acceptDnsCheck(['check_nameservers_in_sync' => true]);
    acceptDnsAnswers([acceptDnsAddress($proxyRotation ? '104.16.0.1' : '192.0.2.1')]);
    acceptDnsPoll($check);
    acceptDnsAnswers([acceptDnsAddress($proxyRotation ? '104.16.0.2' : ($changed ? '192.0.2.2' : '192.0.2.1'))]);
    if ($outOfSync) {
        acceptDnsAnswers([acceptDnsAddress($changed ? '192.0.2.2' : '192.0.2.1')], 'ns2.example.com', serial: 2);
    }
    acceptDnsPoll($check);

    $this->actingAs(acceptDnsOwner())->get('/sites/'.$check->site_id.'/checks/'.$check->id)
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('history.current_issues', $issues)
            ->where('history.accept_would_change_baseline', $wouldChange));
})->with([
    'proxy rotation is not an acceptable difference' => [false, false, false, [], true],
    'records differ only' => [true, false, true, []],
    'records differ and nameservers disagree' => [true, true, true, [['type' => 'dns.issue.nameservers_out_of_sync', 'host' => 'www.example.com', 'record_type' => null]]],
    'only nameservers disagree' => [false, true, false, [['type' => 'dns.issue.nameservers_out_of_sync', 'host' => 'www.example.com', 'record_type' => null]]],
]);

it('offers accept for an inherited empty type even when automatic accept stored the empty records', function (): void {
    $check = acceptDnsCheck(['accept_changes_automatically' => true]);
    acceptDnsAnswers([acceptDnsAddress(), acceptDnsMail()]);
    acceptDnsPoll($check);
    acceptDnsAnswers([acceptDnsAddress()]);
    acceptDnsPoll($check);
    $schema = app(CheckRegistry::class)->get(CheckKey::Dns)->definition()->configSchema;
    $check->update(['config' => $schema->validateConfig(['accept_changes_automatically' => false])]);

    $this->actingAs(acceptDnsOwner())->get('/sites/'.$check->site_id.'/checks/'.$check->id)
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('history.changes', [])
            ->where('history.accept_would_change_baseline', true)
            ->where('history.current_issues.0.type', 'dns.issue.records_missing'));
});

it('projects pending and completed accepted DNS states and flashes the requested attempt', function (bool $outOfSync, string $outcome = 'observed'): void {
    OffsetDatabaseClock::use(0);
    $check = acceptDnsCheck(['check_nameservers_in_sync' => true]);
    acceptDnsAnswers([acceptDnsAddress()]);
    acceptDnsPoll($check);
    acceptDnsAnswers([acceptDnsAddress('192.0.2.2')]);
    if ($outOfSync) {
        acceptDnsAnswers([acceptDnsAddress('192.0.2.2')], 'ns2.example.com', serial: 2);
    }
    $changed = acceptDnsPoll($check);
    $owner = acceptDnsOwner();
    $url = '/sites/'.$check->site_id.'/checks/'.$check->id;
    $this->travel(1)->seconds();
    $this->actingAs($owner)->post($url.'/accept-dns', ['accepted_run_id' => $changed->id])
        ->assertRedirect($url)->assertSessionHasNoErrors();
    $attempt = CheckRunAttempt::query()->where('claim_token', $check->refresh()->claim_token)->sole();
    // Flash is consumed by the redirected Inertia response, not a shared page prop.
    $this->get($url)->assertInertia(fn (Assert $page): Assert => $page
        ->hasFlash('requested_run_attempt_id', $attempt->id)
        ->where('check.pending_recheck', true)
        ->where('check.state', 'pending')
        ->where('site.checks.0.state', 'pending')
        ->where('history.accepted_by', $owner->name));
    $this->get('/sites/'.$check->site_id)->assertInertia(fn (Assert $page): Assert => $page
        ->where('site.checks.0.state', 'pending'));
    $this->get('/sites')->assertInertia(fn (Assert $page): Assert => $page
        ->loadDeferredProps(['list'], fn (Assert $list): Assert => $list->where('sites.0.checks.0.state', 'pending')));
    acceptDnsSiteState($check, 'pending');
    expect(acceptDnsPendingReason($check))->toBe('dns_accept_recheck');
    if ($outcome === 'lost-lease') {
        $check->refresh()->update(['lease_expires_at' => now()->subSecond()]);
        $this->get($url)->assertInertia(fn (Assert $page): Assert => $page
            ->where('check.state', 'pending')->where('check.pending_recheck', true)
            ->where('schedule.run.phase', 'queued'));
        Queue::swap(Queue::getFacadeRoot()->queue);
        Bus::fake([RunCheck::class]);
        expect(app(ClaimService::class)->dispatchDue())->toBe(1);
        expect($attempt->refresh()->note)->toBe('lease_replaced');
        // A replaced lease does not end the recheck; the replacing attempt's finish does.
        acceptDnsSiteState($check, 'pending');
        expect(acceptDnsPendingReason($check))->toBe('dns_accept_recheck');
    }
    if (in_array($outcome, ['errored', 'warning'], true)) {
        $fake = app(DnsResolver::class);
        if (! $fake instanceof FakeDnsResolver) {
            throw new LogicException('DNS accept tests require the fake resolver.');
        }
        $fake->respondWith('www.example.com', 'A', $outcome === 'warning'
            ? new ExternalTimeoutException('DNS fixture timeout.') : new ExternalNetworkException('DNS fixture failure.'), 'ns1.example.com');
    }
    if ($outcome === 'timeout') {
        $check->refresh()->update(['run_started_at' => now()]);
        $attempt->update(['phase' => 'running', 'started_at' => now()]);
        new RunCheck($check->id, $check->claim_token, 120, 'checks-fast')->failed(new TimeoutExceededException);
        $recheck = $check->runs()->orderByDesc('id')->firstOrFail();
        expect($recheck->summary)->toBe('causes.engine.job_timed_out');
    } else {
        $recheck = acceptDnsQueuedRun($check);
    }
    $unstaged = in_array($outcome, ['errored', 'warning', 'timeout'], true);
    $state = match ($outcome) {
        'errored', 'timeout' => 'check-error',
        'warning' => 'warning',
        default => $outOfSync ? 'failed' : 'ok',
    };
    expect($recheck->result)->toBe(match ($state) {
        'check-error' => RunResult::Errored,
        'warning' => RunResult::Warning,
        'failed' => RunResult::Failed,
        default => RunResult::Succeeded,
    });
    if ($unstaged) {
        expect(DB::table('dns_observations')->where('run_id', $recheck->id)->exists())->toBeFalse();
        expect(DB::table('dns_baselines')->where('check_id', $check->id)->value('accepted_pending_recheck'))->toBeTrue();
    }
    $this->get($url)->assertInertia(fn (Assert $page): Assert => $page
        ->where('check.state', $state)->where('site.checks.0.state', $state)
        ->where('check.pending_recheck', false)
        ->where('history.accept_would_change_baseline', false)
        ->where('history.current_run_id', $unstaged ? $changed->id : $recheck->id)
        ->where('history.current_issues', $outOfSync
            ? [['type' => 'dns.issue.nameservers_out_of_sync', 'host' => 'www.example.com', 'record_type' => null]] : []));
    $this->get('/sites/'.$check->site_id)->assertInertia(fn (Assert $page): Assert => $page
        ->where('site.checks.0.state', $state));
    $this->get('/sites')->assertInertia(fn (Assert $page): Assert => $page
        ->loadDeferredProps(['list'], fn (Assert $list): Assert => $list->where('sites.0.checks.0.state', $state)));
    acceptDnsSiteState($check, $state);
    expect(acceptDnsPendingReason($check))->toBeNull();
})->with([
    'confirmed' => [false],
    'still differs' => [true],
    'resolver error without observation' => [false, 'errored'],
    'warning without observation' => [false, 'warning'],
    'worker timeout without observation' => [false, 'timeout'],
    'lost lease waits then reclaimed attempt completes' => [false, 'lost-lease'],
]);

it('sorts, filters and counts a site as pending while its accepted DNS recheck waits', function (): void {
    $pending = acceptDnsPendingRecheck();
    $pending->site->update(['friendly_name' => 'Alpha pending']);
    foreach (['Bravo failed' => CheckResult::Failed, 'Charlie ok' => CheckResult::Succeeded] as $name => $result) {
        $site = Site::factory()->create(['friendly_name' => $name]);
        Check::factory()->for($site)->create(['type' => CheckKey::Performance, 'latest_run_result' => $result]);
    }
    $names = static fn (array $sites): array => array_column($sites, 'name');

    // Initial response: one materialized index projection feeds tiles, summary, attention and the filtered total.
    $initial = $this->get('/sites?sort=status')->assertInertia(fn (Assert $page): Assert => $page
        ->where('tiles.pending', 1)->where('tiles.failed', 1)->where('tiles.ok', 1)
        ->where('summary.attention', 1)->where('needs_attention_total', 1)
        ->where('needs_attention.0.name', 'Bravo failed')->has('needs_attention', 1));
    $this->get('/sites?filter[state]=pending')->assertInertia(fn (Assert $page): Assert => $page->where('pagination.total', 1));
    $this->get('/sites?filter[attention]=1')->assertInertia(fn (Assert $page): Assert => $page->where('pagination.total', 1));

    // Partial reload: summary(), page(), attention() and the dashboard tiles each read the projection on their own.
    $partial = fn (string $query): array => $this->get('/sites'.$query, ['X-Inertia' => 'true', 'X-Inertia-Version' => $initial->inertiaPage()['version'],
        'X-Inertia-Partial-Component' => 'sites/index', 'X-Inertia-Partial-Data' => 'sites,summary,tiles,needs_attention,needs_attention_total,pagination'])
        ->assertOk()->json('props');
    $props = $partial('?sort=status');
    expect($names($props['sites']))->toBe(['Bravo failed', 'Alpha pending', 'Charlie ok'])
        ->and($props['sites'][1]['state'])->toBe('pending')
        ->and([$props['tiles']['pending'], $props['tiles']['failed'], $props['summary']['attention'], $props['needs_attention_total']])->toBe([1, 1, 1, 1])
        ->and($names($props['needs_attention']))->toBe(['Bravo failed']);
    expect($names($partial('?filter[state]=pending')['sites']))->toBe(['Alpha pending']);
    expect($names($partial('?filter[attention]=1')['sites']))->toBe(['Bravo failed']);
});

it('clears the pending recheck when the check stops being runnable', function (string $path, string $state): void {
    $check = acceptDnsPendingRecheck();
    acceptDnsSiteState($check, 'pending');
    $committedLevel = DB::transactionLevel();
    $siteSignal = ['state.changed', ['client' => $check->site->client_id, 'site' => $check->site_id], $committedLevel];
    $checkSignal = ['state.changed', ['client' => $check->site->client_id, 'site' => $check->site_id, 'check' => $check->id], $committedLevel];

    if ($path === 'disabled') {
        $signals = acceptDnsListenForSignals();
        $this->withToken(acceptDnsOwner()->createToken('dns-disable', ['checks:write'])->plainTextToken)
            ->postJson('/api/checks/'.$check->id.'/disable')->assertOk()->assertJsonPath('enabled', false);
        $this->withoutToken();
        // The disable itself announces the site, so open pages leave pending without a signal from the clear.
        expect($signals->all())->toContain($checkSignal);
    } elseif ($path === 'paused') {
        $signals = acceptDnsListenForSignals();
        $this->post('/sites/'.$check->site_id.'/pause')->assertRedirect();
        expect($signals->all())->toContain($siteSignal);
    } else {
        // Disabled after the claim without the action: the executor start skip is the clearing path.
        $check->update(['enabled' => false]);
        $signals = acceptDnsListenForSignals();
    }
    if ($path !== 'executor skip') {
        expect(acceptDnsPendingReason($check))->toBeNull();
    }
    acceptDnsQueuedRun($check);
    if ($path === 'executor skip') {
        // The skip stores no result; only the cleared mark's own signal moves open pages off pending.
        expect($signals->all())->toContain($checkSignal);
    }

    expect(CheckRunAttempt::query()->where('check_id', $check->id)->orderByDesc('id')->firstOrFail()->note)->toBe('check_unavailable');
    expect(acceptDnsPendingReason($check))->toBeNull();
    acceptDnsSiteState($check, $state);
})->with([
    'check disabled' => ['disabled', 'disabled'],
    'site paused' => ['paused', 'paused'],
    'executor skip' => ['executor skip', 'disabled'],
]);

it('adds no query to a finished run unless its check is marked pending, and then only the mark write', function (bool $marked): void {
    if ($marked) {
        $check = acceptDnsPendingRecheck();
    } else {
        // The same failed check claimed the same way, without the accept that marks it.
        OffsetDatabaseClock::use(0);
        $check = acceptDnsCheck(['check_nameservers_in_sync' => true]);
        acceptDnsAnswers([acceptDnsAddress()]);
        acceptDnsPoll($check);
        acceptDnsAnswers([acceptDnsAddress('192.0.2.2')]);
        acceptDnsAnswers([acceptDnsAddress('192.0.2.2')], 'ns2.example.com', serial: 2);
        expect(acceptDnsPoll($check)->result)->toBe(RunResult::Failed);
        $this->travel(1)->seconds();
        expect(app(ClaimService::class)->runNow($check->id, RunOrigin::System))->not->toBeNull();
    }
    $check->refresh();
    $at = CarbonImmutable::now('UTC');
    // The run ends after its claimed slot, so finishing moves next_run_at (the dirty column a careless clear would flush).
    $endedAt = $at->addSeconds($check->interval_seconds * 3);

    DB::flushQueryLog();
    DB::enableQueryLog();
    app(ResultRecorder::class)->record(new RecordableRun($check->id, $check->claim_token ?? throw new LogicException('The check must be claimed.'),
        $at, $endedAt, new CheckOutcome(RunResult::Succeeded, 'dns.succeeded', [], new DnsMetrics(1, 0, false))));
    $queries = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    // Every query that touches pending_reason is listed, so an unmarked run reads or writes nothing for it and a
    // marked run adds only the clearing write; the total stays within the run-finish budget plus that write.
    $markWrites = array_values(array_filter($queries, static fn (string $query): bool => str_contains($query, 'pending_reason')));
    expect($markWrites)->toBe($marked ? ['update "checks" set "pending_reason" = ? where "checks"."id" = ?'] : [])
        ->and(count($queries))->toBeLessThanOrEqual(RUN_FINISH_QUERIES + ($marked ? 1 : 0), implode("\n", $queries))
        ->and($check->refresh()->next_run_at->greaterThan($at->addSeconds($check->interval_seconds)))->toBeTrue()
        ->and(acceptDnsPendingReason($check))->toBeNull()
        ->and($check->refresh()->latest_run_result)->toBe(CheckResult::Succeeded);
})->with(['unmarked check' => [false], 'marked check' => [true]]);

it('removes the pending recheck with the deleted site', function (): void {
    $check = acceptDnsPendingRecheck();
    $this->delete('/sites/'.$check->site_id, ['name' => $check->site->displayName()])->assertRedirect('/sites');

    expect(DB::table('checks')->where('id', $check->id)->exists())->toBeFalse();
    $this->get('/sites')->assertInertia(fn (Assert $page): Assert => $page->where('tiles.pending', 0)->where('summary.total', 0));
});

it('marks a newer accept pending again and clears it when that recheck finishes', function (): void {
    $check = acceptDnsPendingRecheck();
    $recheck = acceptDnsQueuedRun($check);
    expect($recheck->result)->toBe(RunResult::Failed);
    expect(acceptDnsPendingReason($check))->toBeNull();
    acceptDnsSiteState($check, 'failed');

    $this->travel(1)->seconds();
    acceptDnsObservation($check, $recheck);
    acceptDnsSiteState($check, 'pending');
    expect(acceptDnsPendingReason($check))->toBe('dns_accept_recheck');
    expect(acceptDnsQueuedRun($check)->result)->toBe(RunResult::Failed);

    expect(acceptDnsPendingReason($check))->toBeNull();
    acceptDnsSiteState($check, 'failed');
});

it('keeps the recheck pending across a configuration revoke until the next run finishes', function (): void {
    $check = acceptDnsPendingRecheck();
    $attempt = CheckRunAttempt::query()->where('claim_token', $check->claim_token)->sole();

    $this->patch('/sites/'.$check->site_id.'/checks/'.$check->id, ['check_nameservers_in_sync' => false])->assertRedirect();

    expect($attempt->refresh()->note)->toBe('revoked');
    acceptDnsSiteState($check, 'pending');
    expect(acceptDnsPendingReason($check))->toBe('dns_accept_recheck');
    expect(app(ClaimService::class)->dispatchDue())->toBe(1);
    expect(acceptDnsQueuedRun($check)->result)->toBe(RunResult::Succeeded);
    expect(acceptDnsPendingReason($check))->toBeNull();
    acceptDnsSiteState($check, 'ok');
});

it('sends exactly one recovery mail for the incident resolved by accepting a disappeared type', function (bool $pruned): void {
    config(['mail.default' => 'smtp']);
    Mail::fake();
    $destination = NotificationDestination::factory()->create();
    acceptDnsRoute($destination, ['failure', 'check_error', 'recovery']);
    $check = acceptDnsCheck();
    $check->site->update(['friendly_name' => 'DNS gate']);
    $ipv6 = ['host' => 'www.example.com', 'ttl' => 300, 'class' => 'IN', 'type' => 'AAAA', 'ipv6' => '2001:db8::1'];
    acceptDnsAnswers([acceptDnsAddress(), $ipv6]);
    acceptDnsPoll($check);
    acceptDnsAnswers([acceptDnsAddress()]);
    $missing = acceptDnsPoll($check);
    $incident = Incident::query()->where('check_id', $check->id)->sole();
    $opening = Event::query()->where('incident_id', $incident->id)->where('type', 'incident.opened')->sole();
    if ($pruned) {
        $missing->update(['evidence' => []]);
        $snapshot = $incident->evidence;
        $snapshot['evidence'] = [];
        $incident->update(['evidence' => $snapshot]);
    }
    $context = app(BuildAlertMailContext::class)($opening->fresh(), app(NotificationEventTypes::class)->severity($opening), [], [], $destination);
    expect($context->reason->key)->toBe($pruned ? 'dns.failed' : 'dns.issue.records_missing');
    expect(app(CheckRunPresentations::class)->atOpening($incident)->parts[0]->key)->toBe($context->reason->key);
    app()->call(new DispatchNotifications()->handle(...));
    foreach (Delivery::query()->get() as $delivery) {
        app()->call(new SendNotification($delivery->id)->handle(...));
    }
    Mail::assertSentCount(1);
    $openingMail = Mail::sent(TemplateMail::class)->sole()->model;
    expect($openingMail->subject)->toBe($pruned
        ? '[Failed] DNS gate — DNS records are missing or nameservers are out of sync'
        : '[Failed] DNS gate — AAAA records are missing');
    expect($openingMail->headline)->toBe($pruned
        ? 'DNS records are missing or nameservers are out of sync.'
        : 'AAAA records are missing.');
    echo 'Opening subject: '.$openingMail->subject."\nOpening body headline: ".$openingMail->headline."\n";
    acceptDnsObservation($check, $missing);
    expect(acceptDnsQueuedRun($check)->result)->toBe(RunResult::Succeeded);

    app()->call(new DispatchNotifications()->handle(...));
    app()->call(new DispatchNotifications()->handle(...));
    expect(Delivery::query()->count())->toBe(2);
    $recovery = Event::query()->where('incident_id', $incident->id)->where('type', 'incident.resolved')->sole();
    $delivery = Delivery::query()->where('event_id', $recovery->id)->sole();
    app()->call(new SendNotification($delivery->id)->handle(...));
    app()->call(new SendNotification($delivery->id)->handle(...));

    expect($delivery->refresh()->state)->toBe(DeliveryState::Sent);
    expect(Mail::sent(TemplateMail::class, fn (TemplateMail $mail): bool => $mail->model->tone === MailTone::Recovered))->toHaveCount(1);
    Mail::assertSentCount(2);
    $recoveredMail = Mail::sent(TemplateMail::class, fn (TemplateMail $mail): bool => $mail->model->tone === MailTone::Recovered)->sole()->model;
    expect($recoveredMail->subject)->toBe('[Recovered] DNS gate — DNS records are unchanged');
    echo 'Recovery subject: '.$recoveredMail->subject."\n";
})->with(['retained opening evidence' => false, 'pruned opening evidence' => true]);

it('sends a concise DNS body headline without zero counts or repeated missing types', function (string $scenario, string $locale, string $headline): void {
    Mail::fake();
    $destination = NotificationDestination::factory()->create(['locale' => $locale]);
    acceptDnsRoute($destination, ['changes']);
    $check = acceptDnsCheck();
    $ipv6 = ['host' => 'www.example.com', 'ttl' => 300, 'class' => 'IN', 'type' => 'AAAA', 'ipv6' => '2001:db8::1'];
    $before = match ($scenario) {
        'removed' => [acceptDnsAddress(), acceptDnsAddress('192.0.2.2')],
        'added' => [acceptDnsAddress()],
        default => [acceptDnsAddress(), $ipv6],
    };
    acceptDnsAnswers($before);
    acceptDnsPoll($check);
    $after = match ($scenario) {
        'missing', 'removed' => [acceptDnsAddress()],
        'added' => [acceptDnsAddress(), acceptDnsAddress('192.0.2.2'), acceptDnsAddress('192.0.2.3')],
        'missing-and-changed' => [acceptDnsAddress('192.0.2.2')],
    };
    acceptDnsAnswers($after);
    acceptDnsPoll($check);

    app()->call(new DispatchNotifications()->handle(...));
    foreach (Delivery::query()->get() as $delivery) {
        app()->call(new SendNotification($delivery->id)->handle(...));
    }

    Mail::assertSentCount(1);
    $mail = Mail::sent(TemplateMail::class)->sole()->model;
    echo $locale.' '.$scenario.' body headline: '.$mail->headline."\n";
    expect($mail->headline)->toBe($headline);
})->with([
    'EN missing only' => ['missing', 'en', 'AAAA records are missing.'],
    'DE missing only' => ['missing', 'de', 'AAAA-Einträge fehlen.'],
    'EN removed only' => ['removed', 'en', 'A records changed: 1 removed.'],
    'DE removed only' => ['removed', 'de', 'A-Einträge geändert: 1 entfernt.'],
    'EN added only' => ['added', 'en', 'A records changed: 2 added.'],
    'DE added only' => ['added', 'de', 'A-Einträge geändert: 2 hinzugefügt.'],
    'EN missing and another type changed' => ['missing-and-changed', 'en', 'AAAA records are missing, A records changed: 1 removed, 1 added.'],
    'DE missing and another type changed' => ['missing-and-changed', 'de', 'AAAA-Einträge fehlen, A-Einträge geändert: 1 entfernt, 1 hinzugefügt.'],
]);

it('presents the retained DNS issues rather than the generic either-or summary', function (string $scenario, array $keys, ?string $types, ?string $changedTypes = null, string $boundary = 'both'): void {
    $check = acceptDnsCheck(['check_nameservers_in_sync' => true]);
    acceptDnsAnswers([acceptDnsAddress(), acceptDnsMail()]);
    acceptDnsPoll($check);
    acceptDnsAnswers(match ($scenario) {
        'missing', 'old' => [acceptDnsAddress()],
        'missing-change' => [acceptDnsAddress('192.0.2.2')],
        default => [acceptDnsAddress(), acceptDnsMail()],
    });
    if (in_array($scenario, ['sync', 'change-sync'], true)) {
        acceptDnsAnswers([acceptDnsAddress($scenario === 'change-sync' ? '192.0.2.2' : '192.0.2.1'), acceptDnsMail()], 'ns2.example.com', serial: 2);
    }
    $run = acceptDnsPoll($check);
    if ($scenario === 'old') {
        $run->update(['evidence' => [], 'result' => RunResult::Failed, 'summary' => 'dns.failed']);
        $check->refresh()->update(['latest_run_result' => 'failed', 'summary' => 'dns.failed']);
    }

    $event = Event::query()->where('check_id', $check->id)->where('type', 'incident.opened')->sole();
    expect(array_map(static fn (CheckRunPresentationPartData $part): string => $part->key, app(CheckRunPresentations::class)->forEvent($event)->parts))->toBe($keys);
    $owner = acceptDnsOwner();
    $attempt = CheckRunAttempt::query()->where('check_run_id', $run->id)->sole();
    $api = $this->withToken($owner->createToken('dns-headline-read', ['checks:read'])->plainTextToken)
        ->getJson('/api/runs/attempt:'.$attempt->id)
        ->assertJsonPath('result.headline_parts.reasons.0.key', $keys[0]);
    expect($api->json('result.headline'))->not->toContain('www.example.com');
    expect(array_keys($api->json('result')))->toBe(['run_ref', 'result', 'headline', 'headline_status', 'headline_parts', 'headline_data', 'display_headline', 'display_headline_untrusted']);
    if ($types !== null) {
        $api->assertJsonPath('result.headline_parts.reasons.0.params.types', $types)
            ->assertJsonPath('result.headline_parts.reasons.0.lists.types', [$types]);
    } else {
        $api->assertJsonMissingPath('result.headline_parts.reasons.0.lists');
    }

    if ($changedTypes !== null && $boundary !== 'ui') {
        $api->assertJsonPath('result.headline_parts.reasons.1.lists.types', [$changedTypes])
            ->assertJsonPath('result.headline_parts.reasons.1.params', ['types' => $changedTypes, 'added' => 1, 'removed' => 1]);
    }

    $this->actingAs(acceptDnsOwner())->get('/sites/'.$check->site_id.'/checks/'.$check->id)
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('check.presentation.parts', function (Collection $parts) use ($keys, $types, $changedTypes, $boundary): bool {
                expect($parts->pluck('key')->all())->toBe($keys);
                if ($types !== null) {
                    expect($parts->first()['lists']['types'])->toBe([$types]);
                }
                if ($changedTypes !== null && $boundary !== 'api') {
                    expect($parts->last()['lists']['types'])->toBe([$changedTypes]);
                    expect($parts->last()['params'])->toBe(['added' => 1, 'removed' => 1]);
                }

                return true;
            }));
})->with([
    'missing' => ['missing', ['dns.issue.records_missing'], 'MX'],
    'API missing and another type changed' => ['missing-change', ['dns.issue.records_missing', 'dns.presentation.changed'], 'MX', 'A', 'api'],
    'UI missing and another type changed' => ['missing-change', ['dns.issue.records_missing', 'dns.presentation.changed'], 'MX', 'A', 'ui'],
    'out of sync only' => ['sync', ['dns.issue.nameservers_out_of_sync'], null],
    'change and out of sync' => ['change-sync', ['dns.issue.nameservers_out_of_sync', 'dns.presentation.added'], null],
    'old run without retained evidence' => ['old', ['dns.failed'], null],
]);

it('localizes DNS type lists for the recipient and controlled API headline', function (int $typeCount, string $locale, string $prefix, bool $missing): void {
    Mail::fake();
    $destination = NotificationDestination::factory()->create(['locale' => $locale]);
    acceptDnsRoute($destination, ['changes']);
    $check = acceptDnsCheck();
    $records = array_slice([
        acceptDnsAddress(),
        ['host' => 'www.example.com', 'ttl' => 300, 'class' => 'IN', 'type' => 'AAAA', 'ipv6' => '2001:db8::1'],
        acceptDnsMail(),
    ], 0, $typeCount);
    $cname = ['host' => 'www.example.com', 'ttl' => 300, 'class' => 'IN', 'type' => 'CNAME', 'target' => 'origin.example.com'];
    acceptDnsAnswers([$cname, ...$records]);
    acceptDnsPoll($check);
    $changed = array_slice([
        acceptDnsAddress('192.0.2.2'),
        ['host' => 'www.example.com', 'ttl' => 300, 'class' => 'IN', 'type' => 'AAAA', 'ipv6' => '2001:db8::2'],
        [...acceptDnsMail(), 'target' => 'mail2.example.com'],
    ], 0, $typeCount);
    acceptDnsAnswers([$cname, ...($missing ? [] : $changed)]);
    $run = acceptDnsPoll($check);
    $headline = $prefix.($missing
        ? ($locale === 'de' ? ' fehlen.' : ' are missing.')
        : ($locale === 'de' ? " geändert: $typeCount entfernt, $typeCount hinzugefügt." : " changed: $typeCount removed, $typeCount added."));

    // The worker's locale deliberately differs from the recipient's locale.
    app()->setLocale($locale === 'de' ? 'en' : 'de');
    app()->call(new DispatchNotifications()->handle(...));
    foreach (Delivery::query()->get() as $delivery) {
        app()->call(new SendNotification($delivery->id)->handle(...));
    }
    Mail::assertSentCount(1);
    $mail = Mail::sent(TemplateMail::class)->sole()->model;
    expect($mail->headline)->toBe($headline);
    expect($mail->subject)->toContain($prefix);
    echo $locale.' recipient: '.$mail->headline."\n";

    $owner = acceptDnsOwner();
    $attempt = CheckRunAttempt::query()->where('check_run_id', $run->id)->sole();
    $this->withToken($owner->createToken('dns-list-read', ['checks:read'])->plainTextToken)
        ->getJson('/api/runs/attempt:'.$attempt->id.'?locale='.$locale)
        ->assertJsonPath('result.headline', $headline)
        ->assertJsonPath('result.headline_parts.reasons.0.params.types', match ($typeCount) {
            1 => 'A', 2 => 'A, AAAA', 3 => 'A, AAAA, MX',
        })
        ->assertJsonPath('result.display_headline_untrusted', false);
})->with([
    'EN one' => [1, 'en', 'A records'],
    'EN two' => [2, 'en', 'A and AAAA records'],
    'EN three' => [3, 'en', 'A, AAAA and MX records'],
    'DE one' => [1, 'de', 'A-Einträge'],
    'DE two' => [2, 'de', 'A- und AAAA-Einträge'],
    'DE three' => [3, 'de', 'A-, AAAA- und MX-Einträge'],
])->with(['changed' => false, 'missing' => true]);

it('renders retained comma-separated DNS facts in activity after the run is pruned', function (string $locale, string $headline): void {
    $check = acceptDnsCheck();
    $event = Event::factory()->create([
        'check_id' => $check->id, 'site_id' => $check->site_id, 'type' => 'dns.records_changed',
        'to_result' => CheckResult::Warning,
        'payload' => ['types' => 'A, AAAA, MX', 'added' => 3, 'removed' => 0, 'summary' => 'dns.changed', 'summary_params' => []],
    ]);

    $presentation = app(CheckRunPresentations::class)->forEvent($event);

    expect(CheckHeadline::format(new CheckRunPresentationData($presentation->changes), $locale))->toBe($headline);
})->with([
    'en' => ['en', 'A, AAAA and MX records changed: 3 added.'],
    'de' => ['de', 'A-, AAAA- und MX-Einträge geändert: 3 hinzugefügt.'],
]);

it('publishes typed record lists in the public headline contract', function (): void {
    $this->getJson('/api/openapi.json')
        ->assertJsonPath('components.schemas.HeadlineParts.properties.reasons.items.properties.lists.type', 'object')
        ->assertJsonPath('components.schemas.HeadlineParts.properties.reasons.items.properties.lists.additionalProperties.type', 'array')
        ->assertJsonPath('components.schemas.HeadlineParts.properties.reasons.items.properties.lists.additionalProperties.items.type', 'string')
        ->assertJsonPath('components.schemas.HeadlineParts.properties.reasons.items.required', ['key', 'params', 'labels', 'untrusted_fields'])
        ->assertJsonPath('components.schemas.HeadlineParts.properties.changes.items.required', ['key', 'params', 'labels', 'untrusted_fields'])
        ->assertJsonPath('components.schemas.HeadlineParts.properties.details.items.required', ['key', 'params', 'labels', 'untrusted_fields']);
});
