<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Actions\ArchiveNotificationChannel;
use Heptapod\Actions\ClaimNotificationDelivery;
use Heptapod\Actions\ClaimSystemProbe;
use Heptapod\Actions\CompleteNotificationDelivery;
use Heptapod\Actions\EnsureAccountChannel;
use Heptapod\Actions\SetNotificationDestinationEnabled;
use Heptapod\Addons\Channels\TemplateMail;
use Heptapod\Contracts\Notifications\ChannelRegistry;
use Heptapod\Contracts\Notifications\DeliveryResult;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Enums\NotificationMode;
use Heptapod\Jobs\SendNotification;
use Heptapod\Models\Delivery;
use Heptapod\Models\DispatcherHeartbeat;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\SystemIssue;
use Heptapod\Models\User;
use Heptapod\SystemHealth\EvaluateSystemIssues;
use Heptapod\SystemHealth\MailTransportRecord;
use Heptapod\SystemHealth\SystemIssueMailer;
use Heptapod\Testing\FakeSmtpTransport;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

require_once __DIR__.'/../Support/NotificationChannels.php';
require_once __DIR__.'/../Support/SystemIssueRecipients.php';

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-30T12:00:00Z'));
    // A private Redis namespace per test: the evaluator lock, its record and the mail transport outcome.
    $prefix = 'system-issue-delivery-test:'.Str::uuid().':';
    config(['mail.default' => 'smtp', 'database.redis.options.prefix' => $prefix, 'cache.prefix' => $prefix,
        'heptapod.system_issues.mail_dead_after_seconds' => 3600]);
    Redis::purge('default');
    Redis::purge('cache');
    Cache::purge('redis');
    Http::preventStrayRequests();
    // Only the issue under test opens: heartbeats are fresh and the last backup succeeded.
    Cache::store('redis')->forever(config('heptapod.health.scheduler_cache_key'), now()->toIso8601String());
    DispatcherHeartbeat::query()->create(['id' => '00000000-0000-0000-0000-000000000094', 'last_seen_at' => now()]);
});

afterEach(function (): void {
    foreach ([EvaluateSystemIssues::EvaluationKey, 'heptapod:mail-transport:outcome', config('heptapod.health.scheduler_cache_key')] as $key) {
        Cache::store('redis')->forget($key);
    }
});

function evaluateSystemIssues(): void
{
    test()->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
}

/** A mail transport that failed three times, the first over an hour ago: the next evaluation opens mail_transport_dead. */
function deadMailTransport(): SystemIssue
{
    $record = app(MailTransportRecord::class);
    foreach ([3, 2, 2] as $hours) {
        $record->recordFailure(now()->subHours($hours), 'SMTP 421 connect — refused');
    }
    evaluateSystemIssues();

    return SystemIssue::open()->where('kind', 'mail_transport_dead')->sole();
}

/** A team Slack channel with System on. */
function systemSlackChannel(): NotificationDestination
{
    $channel = NotificationDestination::factory()->create(['label' => 'Ops Slack', 'channel' => 'slack', 'locale' => 'en',
        'config' => app(ChannelRegistry::class)->get('slack')->configSchema()->validateConfig(['channel' => 'C123', 'token' => 'xoxb-system-token'])]);
    systemIssueCell($channel, NotificationMode::Immediate);

    return $channel;
}

/** The scheduler heartbeat goes stale: scheduler_stale opens (an ordinary kind). */
function staleScheduler(): SystemIssue
{
    Cache::store('redis')->forget(config('heptapod.health.scheduler_cache_key'));
    evaluateSystemIssues();

    return SystemIssue::open()->where('kind', 'scheduler_stale')->sole();
}

function freshScheduler(): void
{
    Cache::store('redis')->forever(config('heptapod.health.scheduler_cache_key'), now()->toIso8601String());
    DispatcherHeartbeat::query()->update(['last_seen_at' => now()]);
}

function mergeSystemIssuesPath(): string
{
    return 'database/migrations/2026_10_30_000002_merge_system_issue_deliveries.php';
}

/** The schema after 2026_10_30_000001 and before the merge: system issue mail in its own table, the Admin opt-in on users. */
function restoreSystemIssueDeliveries(): void
{
    DB::statement('ALTER TABLE events DROP CONSTRAINT events_site_required');
    DB::statement("ALTER TABLE events ADD CONSTRAINT events_site_required CHECK (type = 'notification.digest' OR (site_name IS NOT NULL AND site_url IS NOT NULL AND client_name IS NOT NULL AND client_id IS NOT NULL))");
    DB::statement('DROP INDEX events_system_issue_phase');
    Schema::table('events', fn (Blueprint $table) => $table->dropConstrainedForeignId('system_issue_id'));
    DB::statement('ALTER TABLE deliveries DROP CONSTRAINT deliveries_probe_round');
    DB::statement('DROP INDEX deliveries_system_unfinished');
    Schema::table('deliveries', function (Blueprint $table): void {
        $table->dropUnique(['event_id', 'destination_id', 'probe_round']);
        $table->dropColumn('probe_round');
        $table->unique(['event_id', 'destination_id']);
    });
    Schema::create('system_issue_deliveries', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('system_issue_id')->constrained()->cascadeOnDelete();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->string('phase', 10);
        $table->unsignedInteger('attempts')->default(0);
        $table->text('last_error')->nullable();
        $table->timestampTz('last_attempt_at', 6)->nullable();
        $table->timestampTz('delivered_at', 6)->nullable();
        $table->timestampTz('suppressed_at', 6)->nullable();
        $table->string('suppression_reason', 40)->nullable();
        $table->unique(['system_issue_id', 'user_id', 'phase']);
    });
    Schema::table('users', fn (Blueprint $table) => $table->boolean('system_issue_mails')->default(false));
    DB::table('migrations')->where('migration', basename(mergeSystemIssuesPath(), '.php'))->delete();
}

/** @param array<string, mixed> $attributes */
function oldSystemIssue(array $attributes): int
{
    return DB::table('system_issues')->insertGetId(['kind' => 'backup_failed', 'scope' => '', 'details' => '{}', 'opened_at' => now()->subHours(3), ...$attributes]);
}

/** @param array<string, mixed> $attributes */
function oldSystemIssueDelivery(int $issueId, User $user, string $phase, array $attributes = []): void
{
    DB::table('system_issue_deliveries')->insert(['system_issue_id' => $issueId, 'user_id' => $user->id, 'phase' => $phase, ...$attributes]);
}

/** @return array<string, mixed> */
function migratedSystemDelivery(int $issueId, string $type, User $user): array
{
    return (array) DB::table('deliveries')->join('events', 'events.id', '=', 'deliveries.event_id')
        ->join('notification_destinations as d', 'd.id', '=', 'deliveries.destination_id')
        ->where('events.system_issue_id', $issueId)->where('events.type', $type)->where('d.user_id', $user->id)
        ->sole(['deliveries.state', 'deliveries.attempts', 'deliveries.sent_at', 'deliveries.next_attempt_at', 'deliveries.last_reason', 'deliveries.last_diagnosis',
            'deliveries.suppressed', 'deliveries.suppression_reason', 'deliveries.origin', 'deliveries.category', 'd.account_mail', 'd.removed_at']);
}

it('moves every system issue delivery state into the ledger and drops the old table and opt-in column', function (): void {
    $owner = User::factory()->owner()->create();
    $admin = User::factory()->admin()->create();
    $member = User::factory()->member()->create();
    foreach ([$owner, $admin] as $user) {
        app(EnsureAccountChannel::class)($user);
    }
    restoreSystemIssueDeliveries();
    DB::table('users')->where('id', $admin->id)->update(['system_issue_mails' => true]);
    $resolved = oldSystemIssue(['resolved_at' => now()->subHour()]);
    $open = oldSystemIssue(['kind' => 'scheduler_stale']);
    $dead = oldSystemIssue(['kind' => 'mail_transport_dead']);
    $at = now()->subMinutes(10);
    // Resolved issue: a delivered opening and recovery, a suppressed opening, a pending recovery.
    oldSystemIssueDelivery($resolved, $owner, 'open', ['attempts' => 1, 'last_attempt_at' => now()->subHours(3), 'delivered_at' => now()->subHours(3)]);
    oldSystemIssueDelivery($resolved, $owner, 'recovery', ['attempts' => 1, 'last_attempt_at' => now()->subHour(), 'delivered_at' => now()->subHour()]);
    oldSystemIssueDelivery($resolved, $admin, 'open', ['suppressed_at' => now()->subHours(3), 'suppression_reason' => 'resolved_before_delivery']);
    oldSystemIssueDelivery($resolved, $admin, 'recovery');
    // Open issue: a failed opening (pending with an error) and a never attempted one for a recipient no longer eligible.
    oldSystemIssueDelivery($open, $owner, 'open', ['attempts' => 2, 'last_error' => 'SMTP 451 config — SMTP response rejected', 'last_attempt_at' => $at]);
    oldSystemIssueDelivery($open, $member, 'open');
    // An unresolved transport probe: its recovery row failed and stays a probe.
    oldSystemIssueDelivery($dead, $owner, 'recovery', ['attempts' => 3, 'last_error' => 'SMTP 421 connect — refused', 'last_attempt_at' => $at]);

    expect(Artisan::call('migrate', ['--path' => mergeSystemIssuesPath(), '--force' => true, '--no-interaction' => true]))->toBe(0);

    expect(migratedSystemDelivery($resolved, 'system.issue_opened', $owner))->toBe(['state' => 'sent', 'attempts' => 1, 'sent_at' => '2026-10-30 09:00:00+00',
        'next_attempt_at' => '2026-10-30 09:00:00+00', 'last_reason' => null, 'last_diagnosis' => null, 'suppressed' => false, 'suppression_reason' => null,
        'origin' => 'system', 'category' => 'system', 'account_mail' => true, 'removed_at' => null]);
    expect(migratedSystemDelivery($resolved, 'system.issue_resolved', $owner))->toMatchArray(['state' => 'sent', 'sent_at' => '2026-10-30 11:00:00+00']);
    expect(migratedSystemDelivery($resolved, 'system.issue_opened', $admin))->toMatchArray(['state' => 'pending', 'suppressed' => true, 'suppression_reason' => 'resolved_before_delivery']);
    expect(migratedSystemDelivery($resolved, 'system.issue_resolved', $admin))->toMatchArray(['state' => 'pending', 'attempts' => 0, 'suppressed' => false,
        'last_reason' => null, 'next_attempt_at' => '2026-10-30 12:00:00+00']);
    expect(migratedSystemDelivery($open, 'system.issue_opened', $owner))->toMatchArray(['state' => 'failed', 'attempts' => 2, 'suppressed' => false,
        'last_reason' => 'notifications.transport_failed', 'last_diagnosis' => 'SMTP 451 config — SMTP response rejected', 'next_attempt_at' => '2026-10-30 11:50:00+00']);
    // The member never had an account channel: one is created archived so the history keeps its recipient; claim suppresses the row.
    expect(migratedSystemDelivery($open, 'system.issue_opened', $member))->toMatchArray(['state' => 'pending', 'suppressed' => false, 'account_mail' => true,
        'removed_at' => '2026-10-30 12:00:00+00']);
    expect(migratedSystemDelivery($dead, 'system.issue_resolved', $owner))->toMatchArray(['state' => 'pending', 'attempts' => 3, 'suppressed' => false,
        'last_reason' => 'system_probe', 'last_diagnosis' => 'SMTP 421 connect — refused']);
    // One event per issue and phase; a probe recovery occurs at its first attempt and says so.
    expect(DB::table('events')->whereNotNull('system_issue_id')->orderBy('system_issue_id')->orderBy('type')
        ->get(['system_issue_id', 'type', 'occurred_at', 'payload', 'site_id', 'notification_routed_at'])->map(fn (object $row): array => (array) $row)->all())->toBe([
            ['system_issue_id' => $resolved, 'type' => 'system.issue_opened', 'occurred_at' => '2026-10-30 09:00:00+00', 'payload' => '{}', 'site_id' => null, 'notification_routed_at' => '2026-10-30 12:00:00+00'],
            ['system_issue_id' => $resolved, 'type' => 'system.issue_resolved', 'occurred_at' => '2026-10-30 11:00:00+00', 'payload' => '{"probe": false}', 'site_id' => null, 'notification_routed_at' => '2026-10-30 12:00:00+00'],
            ['system_issue_id' => $open, 'type' => 'system.issue_opened', 'occurred_at' => '2026-10-30 09:00:00+00', 'payload' => '{}', 'site_id' => null, 'notification_routed_at' => '2026-10-30 12:00:00+00'],
            ['system_issue_id' => $dead, 'type' => 'system.issue_resolved', 'occurred_at' => '2026-10-30 11:50:00+00', 'payload' => '{"probe": true}', 'site_id' => null, 'notification_routed_at' => '2026-10-30 12:00:00+00'],
        ]);
    expect(DB::table('deliveries')->count())->toBe(7);
    expect(Schema::hasTable('system_issue_deliveries'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'system_issue_mails'))->toBeFalse();
    $memberChannel = DB::table('notification_destinations')->where('user_id', $member->id)->sole();
    expect(json_decode((string) DB::table('activity_log')->where('event', 'notifications.migrated')->sole()->properties, true)['lines'])->toBe([
        "User #{$member->id}: account email channel #{$memberChannel->id} added archived to keep its system issue mail history.",
        'System issue mail: 7 deliveries moved into the delivery ledger (2 sent, 1 suppressed, 1 failed, 2 pending, 1 probe).',
    ]);
    // A second event for the same issue and phase is refused; a site-less event needs its system issue.
    expect(fn () => DB::transaction(fn () => DB::table('events')->insert(['type' => 'system.issue_opened', 'system_issue_id' => $open, 'occurred_at' => now(), 'payload' => '{}',
        'suppressed' => false, 'suppression_evaluated_at' => now()])))->toThrow(UniqueConstraintViolationException::class);
    expect(fn () => DB::transaction(fn () => DB::table('events')->insert(['type' => 'system.issue_opened', 'occurred_at' => now(), 'payload' => '{}',
        'suppressed' => false, 'suppression_evaluated_at' => now()])))->toThrow(QueryException::class, 'events_site_required');
});

it('requires the pre-upgrade backup for rollback of the system issue merge', function (): void {
    $migration = require base_path(mergeSystemIssuesPath());

    expect(fn () => $migration->down())->toThrow(LogicException::class,
        'System issue deliveries now live in the delivery ledger. Roll back by restoring the pre-upgrade backup (scripts/rollback.sh --restore-backup).');
});

it('opens to the Owner, an opted-in Admin and a team System channel and recovers only where the opening was sent', function (): void {
    Mail::mailer()->setSymfonyTransport($transport = new ArrayTransport);
    Http::fake(['https://slack.com/api/chat.postMessage' => Http::response(['ok' => true, 'ts' => '1.2'])]);
    $owner = systemIssueRecipient('Owner');
    $admin = systemIssueRecipient('Admin', true, ['locale' => 'de']);
    $quietAdmin = systemIssueRecipient('Admin');
    $member = systemIssueRecipient('Member', true);
    $slack = systemSlackChannel();
    $silentTeam = NotificationDestination::factory()->create();

    $issue = staleScheduler();

    expect(systemIssueDeliveries($issue->id, 'open')->orderBy('deliveries.destination_id')->pluck('deliveries.destination_id')->all())
        ->toBe([systemIssueAccountChannel($owner)->id, systemIssueAccountChannel($admin)->id, $slack->id]);
    expect(systemIssueDeliveries($issue->id, 'open')->where('deliveries.state', 'sent')->count())->toBe(3);
    expect(collect($transport->messages())->map(fn ($sent): string => $sent->getEnvelope()->getRecipients()[0]->getAddress())->sort()->values()->all())
        ->toBe(collect([$owner->email, $admin->email])->sort()->values()->all());
    Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->body(), 'The scheduler or the check dispatcher stopped'));
    expect(systemIssueDeliveries($issue->id, 'open')->whereIn('deliveries.destination_id', [systemIssueAccountChannel($quietAdmin)->id, systemIssueAccountChannel($member)->id, $silentTeam->id])->count())->toBe(0);

    // The Admin opts out before the recovery: the current eligibility rule applies (no recovery for them).
    systemIssueCell(systemIssueAccountChannel($admin), NotificationMode::Off);
    freshScheduler();
    evaluateSystemIssues();

    expect($issue->refresh()->resolved_at)->not->toBeNull();
    expect(systemIssueDeliveries($issue->id, 'recovery')->orderBy('deliveries.destination_id')->pluck('deliveries.destination_id')->all())
        ->toBe([systemIssueAccountChannel($owner)->id, $slack->id]);
    expect(systemIssueDeliveries($issue->id, 'recovery')->where('deliveries.state', 'sent')->count())->toBe(2);
});

it('suppresses openings still pending at resolution and never sends their recovery', function (): void {
    Mail::mailer()->setSymfonyTransport(new FakeSmtpTransport('MAIL FROM:<sender@example.test>', 451));
    $owner = systemIssueRecipient('Owner');
    $issue = staleScheduler();
    $opening = systemIssueDeliveries($issue->id, 'open')->sole();
    expect($opening->state)->toBe('failed')->and($opening->attempts)->toBe(1)
        // Ordinary System retries keep the fixed 300 s cadence instead of the alert backoff.
        ->and(CarbonImmutable::parse($opening->next_attempt_at)->equalTo(now()->addSeconds(300)))->toBeTrue();

    freshScheduler();
    evaluateSystemIssues();

    expect(systemIssueDeliveries($issue->id, 'open')->sole())->toMatchObject(['suppressed' => true, 'suppression_reason' => 'resolved_before_delivery']);
    expect(systemIssueDeliveries($issue->id, 'recovery')->count())->toBe(0);
    expect(systemIssueAccountChannel($owner)->refresh()->last_outcome)->toBe('failed');
});

it('sends System mail to an unverified opted-in Admin and an Owner with a paused account channel, and rechecks roles at claim', function (): void {
    Mail::mailer()->setSymfonyTransport($transport = new ArrayTransport);
    $owner = systemIssueRecipient('Owner');
    $admin = systemIssueRecipient('Admin', true, ['email_verified_at' => null]);
    $demoted = systemIssueRecipient('Admin', true);
    app(SetNotificationDestinationEnabled::class)(systemIssueAccountChannel($owner), false);
    // The Owner's stored cell is off: Owners are locked on whatever the cell says.
    systemIssueCell(systemIssueAccountChannel($owner), NotificationMode::Off);
    Mail::mailer()->setSymfonyTransport(new FakeSmtpTransport('MAIL FROM:<sender@example.test>', 451));

    $issue = staleScheduler();
    expect(systemIssueDeliveries($issue->id, 'open')->count())->toBe(3);
    // Between attempts the demoted Admin loses the role; the retry rechecks it at claim.
    $demoted->syncRoles(['Member']);
    Mail::mailer()->setSymfonyTransport($transport);
    $this->travel(301)->seconds();
    evaluateSystemIssues();

    $recipients = collect($transport->messages())->map(fn ($sent): string => $sent->getEnvelope()->getRecipients()[0]->getAddress())->sort()->values()->all();
    expect($recipients)->toBe(collect([$owner->email, $admin->email])->sort()->values()->all());
    expect(systemIssueDeliveries($issue->id, 'open')->where('deliveries.destination_id', systemIssueAccountChannel($demoted)->id)->sole())
        ->toMatchObject(['suppressed' => true, 'suppression_reason' => 'not_owner_or_opted_in_admin']);
});

it('keeps log-only System mail pending, rechecks it every 300 s and opens mail_log_only on non-mail channels only', function (): void {
    config(['mail.default' => 'log']);
    Http::fake(['https://slack.com/api/chat.postMessage' => Http::response(['ok' => true, 'ts' => '1.2'])]);
    systemIssueRecipient('Owner');
    $slack = systemSlackChannel();
    evaluateSystemIssues();
    $logOnly = SystemIssue::open()->where('kind', 'mail_log_only')->sole();
    expect(systemIssueDeliveries($logOnly->id, 'open')->pluck('deliveries.destination_id')->all())->toBe([$slack->id]);
    Mail::fake();

    $stale = staleScheduler();
    $mail = systemIssueDeliveries($stale->id, 'open')->where('deliveries.destination_id', '<>', $slack->id)->sole();
    expect($mail)->toMatchObject(['state' => 'pending', 'last_reason' => 'mail_log_only', 'attempts' => 0])
        ->and(CarbonImmutable::parse($mail->next_attempt_at)->equalTo(now()->addSeconds(300)))->toBeTrue();
    Mail::assertNothingSent();

    // The real transport arrives: the next pass after 300 s sends the waiting opening; MailLogOnly recovers without mail.
    config(['mail.default' => 'smtp']);
    $this->travel(300)->seconds();
    evaluateSystemIssues();
    expect(Delivery::query()->findOrFail($mail->id)->only(['state', 'last_reason', 'attempts']))->toBe(['state' => DeliveryState::Sent, 'last_reason' => null, 'attempts' => 1]);
    Mail::assertSent(TemplateMail::class, fn (TemplateMail $sent): bool => str_contains($sent->model->subject, 'The scheduler or the check dispatcher stopped'));
    expect($logOnly->refresh()->resolved_at)->not->toBeNull();
    expect(systemIssueDeliveries($logOnly->id, 'recovery')->pluck('deliveries.destination_id')->all())->toBe([$slack->id]);
});

it('clears the log-only reason when System mail is claimed, before completion', function (): void {
    config(['mail.default' => 'log']);
    systemIssueRecipient('Owner');
    $issue = staleScheduler();
    $mail = systemIssueDeliveries($issue->id, 'open')->sole();
    expect($mail->last_reason)->toBe('mail_log_only');

    config(['mail.default' => 'smtp']);
    $this->travel(300)->seconds();
    $claimed = app(ClaimNotificationDelivery::class)($mail->id);

    expect($claimed?->state)->toBe(DeliveryState::Sending);
    expect(Delivery::query()->findOrFail($mail->id)->last_reason)->toBeNull();
});

it('probes a dead transport only through ClaimSystemProbe and resolves it with the accepted recovery in one commit', function (): void {
    Mail::mailer()->setSymfonyTransport(new FakeSmtpTransport('MAIL FROM:<sender@example.test>', 421));
    Http::fake(['https://slack.com/api/chat.postMessage' => Http::response(['ok' => true, 'ts' => '1.2'])]);
    $owner = systemIssueRecipient('Owner');
    $admin = systemIssueRecipient('Admin', true);
    $slack = systemSlackChannel();

    $issue = deadMailTransport();
    // No mail opening for a dead transport; the team Slack channel hears about it.
    expect(systemIssueDeliveries($issue->id, 'open')->pluck('deliveries.destination_id')->all())->toBe([$slack->id]);
    evaluateSystemIssues();
    $probes = systemIssueDeliveries($issue->id, 'recovery')->orderBy('deliveries.destination_id')->get();
    expect($probes->pluck('destination_id')->all())->toBe([systemIssueAccountChannel($owner)->id, systemIssueAccountChannel($admin)->id]);
    // Transport failure: the first row stays a probe with its diagnosis, the run stops before the second.
    expect($probes->pluck('last_reason')->unique()->all())->toBe(['system_probe']);
    expect($probes->pluck('attempts')->all())->toBe([1, 0]);
    expect($probes[0]->last_diagnosis)->toBe('SMTP 421 config — SMTP response rejected');
    expect($issue->refresh()->resolved_at)->toBeNull();

    // Normal dispatch never sends probe rows.
    Mail::mailer()->setSymfonyTransport($transport = new ArrayTransport);
    dispatchNotifications();
    app()->call(new SendNotification($probes[1]->id)->handle(...));
    expect($transport->messages())->toHaveCount(0);
    expect(Delivery::query()->findOrFail($probes[1]->id)->attempts)->toBe(0);

    // The next evaluation retries; the accepted probe resolves the issue and releases the others in the same commit.
    $this->travel(5)->minutes();
    evaluateSystemIssues();
    expect($issue->refresh()->resolved_at?->toIso8601String())->toBe(now()->toIso8601String());
    $rows = systemIssueDeliveries($issue->id, 'recovery')->orderBy('deliveries.destination_id')->get()->keyBy('destination_id');
    expect($rows[systemIssueAccountChannel($owner)->id])->toMatchObject(['state' => 'sent', 'last_reason' => null, 'attempts' => 2]);
    expect($rows[systemIssueAccountChannel($admin)->id]->last_reason)->toBeNull();
    // The Admin's released row and the Slack recovery go out in the same pass; nobody gets the recovery twice.
    expect($rows[systemIssueAccountChannel($admin)->id]->state)->toBe('sent');
    expect($rows[$slack->id]->state)->toBe('sent');
    expect(collect($transport->messages())->map(fn ($sent): string => $sent->getEnvelope()->getRecipients()[0]->getAddress())->all())
        ->toBe([$owner->email, $admin->email]);
    evaluateSystemIssues();
    expect($transport->messages())->toHaveCount(2);
});

it('skips a rejected probe recipient for good and tries the next one', function (): void {
    $owner = systemIssueRecipient('Owner');
    $second = systemIssueRecipient('Owner');
    Mail::mailer()->setSymfonyTransport(new FakeSmtpTransport('MAIL FROM:<sender@example.test>', 421));
    $issue = deadMailTransport();
    $rejecting = new class($owner->email) implements TransportInterface
    {
        public array $accepted = [];

        public function __construct(private readonly string $rejected) {}

        public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
        {
            $to = $envelope?->getRecipients()[0]->getAddress() ?? Envelope::create($message)->getRecipients()[0]->getAddress();
            if ($to === $this->rejected) {
                return new FakeSmtpTransport('RCPT TO:<'.$to.'>', 550)->send($message, $envelope);
            }
            $this->accepted[] = $to;

            return new SentMessage($message, $envelope ?? Envelope::create($message));
        }

        public function __toString(): string
        {
            return 'smtp://rejecting';
        }
    };
    Mail::mailer()->setSymfonyTransport($rejecting);

    evaluateSystemIssues();

    expect(systemIssueDeliveries($issue->id, 'recovery')->where('deliveries.destination_id', systemIssueAccountChannel($owner)->id)->sole())
        ->toMatchObject(['suppressed' => true, 'suppression_reason' => 'smtp_recipient_rejected']);
    expect($rejecting->accepted)->toBe([$second->email]);
    expect($issue->refresh()->resolved_at)->not->toBeNull();
});

it('reclaims a probe whose lease expired and refuses a claim on a resolved issue', function (): void {
    systemIssueRecipient('Owner');
    Mail::mailer()->setSymfonyTransport(new FakeSmtpTransport('MAIL FROM:<sender@example.test>', 421));
    $issue = deadMailTransport();
    evaluateSystemIssues();
    $probe = systemIssueDeliveries($issue->id, 'recovery')->sole();
    // A crash after the claim leaves a sending row with a lease.
    $claimed = app(ClaimSystemProbe::class)($probe->id, $issue->id);
    expect($claimed?->state)->toBe(DeliveryState::Sending);
    expect(app(ClaimSystemProbe::class)($probe->id, $issue->id))->toBeNull();
    // The lease covers the send's 60 s timeout plus 30 s grace: still held after 89 s, free after 91 s.
    $this->travel(89)->seconds();
    expect(app(ClaimSystemProbe::class)($probe->id, $issue->id))->toBeNull();
    $this->travel(2)->seconds();
    expect(app(ClaimSystemProbe::class)($probe->id, $issue->id)?->attempts)->toBe(3);
    $issue->update(['resolved_at' => now()]);
    $this->travel(91)->seconds();
    expect(app(ClaimSystemProbe::class)($probe->id, $issue->id))->toBeNull();
});

it('never grants a probe lease on an archived channel, also when an expired lease is reclaimed', function (): void {
    $owner = systemIssueRecipient('Owner');
    $team = NotificationDestination::factory()->create(['label' => 'Ops mail']);
    systemIssueCell($team, NotificationMode::Immediate);
    Mail::mailer()->setSymfonyTransport(new FakeSmtpTransport('MAIL FROM:<sender@example.test>', 421));
    $issue = deadMailTransport();
    evaluateSystemIssues();
    $probe = systemIssueDeliveries($issue->id, 'recovery')->where('deliveries.destination_id', $team->id)->sole();
    // A probe holds a live lease when the channel is archived (archiving leaves live leases alone), then the worker crashes.
    expect(app(ClaimSystemProbe::class)($probe->id, $issue->id)?->state)->toBe(DeliveryState::Sending);
    app(ArchiveNotificationChannel::class)($team, ArchiveNotificationChannel::Archived, $owner);
    $this->travel(91)->seconds();

    expect(app(ClaimSystemProbe::class)($probe->id, $issue->id))->toBeNull();
    expect(Delivery::query()->findOrFail($probe->id)->only(['suppressed', 'suppression_reason', 'claim_token']))
        ->toBe(['suppressed' => true, 'suppression_reason' => 'destination_archived', 'claim_token' => null]);
});

/** An SMTP transport that rejects one recipient at RCPT and accepts every other one. */
function rejectingSmtp(string $rejected): TransportInterface
{
    return new class($rejected) implements TransportInterface
    {
        /** @var list<string> */
        public array $accepted = [];

        public function __construct(public string $rejected) {}

        public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
        {
            $envelope ??= Envelope::create($message);
            $to = $envelope->getRecipients()[0]->getAddress();
            if ($to === $this->rejected) {
                return new FakeSmtpTransport('RCPT TO:<'.$to.'>', 550)->send($message, $envelope);
            }
            $this->accepted[] = $to;

            return new SentMessage($message, $envelope);
        }

        public function __toString(): string
        {
            return 'smtp://rejecting';
        }
    };
}

it('re-arms the probe of the only recipient after its rejected address is fixed and resolves the dead transport', function (): void {
    $owner = systemIssueRecipient('Owner', attributes: ['email' => 'broken@example.test']);
    Mail::mailer()->setSymfonyTransport(new FakeSmtpTransport('MAIL FROM:<sender@example.test>', 421));
    $issue = deadMailTransport();
    Mail::mailer()->setSymfonyTransport($transport = rejectingSmtp('broken@example.test'));
    evaluateSystemIssues();
    $rejected = systemIssueDeliveries($issue->id, 'recovery')->sole();
    expect([$rejected->suppressed, $rejected->suppression_reason])->toBe([true, 'smtp_recipient_rejected']);
    // Same address, within the cool-down: no new probe, the issue stays open.
    $this->travel(5)->minutes();
    evaluateSystemIssues();
    expect(systemIssueDeliveries($issue->id, 'recovery')->count())->toBe(1);
    expect($issue->refresh()->resolved_at)->toBeNull();

    $owner->forceFill(['email' => 'fixed@example.test'])->save();
    $this->travel(5)->minutes();
    evaluateSystemIssues();

    expect($transport->accepted)->toBe(['fixed@example.test']);
    expect($issue->refresh()->resolved_at)->not->toBeNull();
    // The rejection stays in the history beside the accepted re-armed probe.
    expect(systemIssueDeliveries($issue->id, 'recovery')->orderBy('deliveries.probe_round')->select(['deliveries.probe_round', 'deliveries.state', 'deliveries.suppressed'])->get()
        ->map(fn (object $row): array => (array) $row)->all())->toBe([
            ['probe_round' => 0, 'state' => 'failed', 'suppressed' => true],
            ['probe_round' => 1, 'state' => 'sent', 'suppressed' => false],
        ]);
});

it('re-arms a rejected probe on the same address after an hour, not before', function (): void {
    systemIssueRecipient('Owner', attributes: ['email' => 'full@example.test']);
    Mail::mailer()->setSymfonyTransport(new FakeSmtpTransport('MAIL FROM:<sender@example.test>', 421));
    $issue = deadMailTransport();
    Mail::mailer()->setSymfonyTransport(rejectingSmtp('full@example.test'));
    evaluateSystemIssues();
    // The mailbox is repaired at once, but within the hour the unchanged address is not tried again.
    Mail::mailer()->setSymfonyTransport($transport = new ArrayTransport);
    $this->travel(3599)->seconds();
    freshScheduler();
    evaluateSystemIssues();
    expect(systemIssueDeliveries($issue->id, 'recovery')->count())->toBe(1);
    expect($transport->messages())->toHaveCount(0);

    $this->travel(1)->seconds();
    freshScheduler();
    evaluateSystemIssues();

    expect($transport->messages())->toHaveCount(1);
    expect($issue->refresh()->resolved_at)->not->toBeNull();
});

it('queues the recovery of an opening that was still sending when the evaluator resolved the issue', function (): void {
    $owner = systemIssueRecipient('Owner');
    Mail::mailer()->setSymfonyTransport(new FakeSmtpTransport('MAIL FROM:<sender@example.test>', 451));
    $issue = staleScheduler();
    $opening = systemIssueDeliveries($issue->id, 'open')->sole();
    // A notification worker claims the retry and is still talking to the provider...
    $this->travel(301)->seconds();
    $claimed = app(ClaimNotificationDelivery::class)($opening->id);
    expect($claimed?->state)->toBe(DeliveryState::Sending);
    // ...while the evaluator resolves the issue: the opening is neither pending nor sent yet.
    freshScheduler();
    evaluateSystemIssues();
    expect($issue->refresh()->resolved_at)->not->toBeNull();
    expect(systemIssueDeliveries($issue->id, 'recovery')->count())->toBe(0);

    // The provider accepted the opening: its fenced completion queues this recipient's recovery in the same commit.
    expect(app(CompleteNotificationDelivery::class)($opening->id, $claimed->claim_token, new DeliveryResult(DeliveryState::Sent)))->toBeTrue();
    expect(systemIssueDeliveries($issue->id, 'recovery')->pluck('deliveries.destination_id')->all())->toBe([systemIssueAccountChannel($owner)->id]);
    Mail::mailer()->setSymfonyTransport($transport = new ArrayTransport);
    evaluateSystemIssues();
    expect($transport->messages())->toHaveCount(1)
        ->and($transport->messages()[0]->getOriginalMessage()->getSubject())->toStartWith('[System recovered]');
});

/** A resolved issue whose recovery event exists; deliveries are added by the test. */
function resolvedIssueRecoveryEvent(): int
{
    $issue = SystemIssue::query()->create(['kind' => 'backup_failed', 'scope' => '', 'details' => [], 'opened_at' => now()->subHour(), 'resolved_at' => now()->subMinutes(30)]);

    return app(SystemIssueMailer::class)->event($issue, 'system.issue_resolved', now()->subMinutes(30), ['probe' => false])->id;
}

/** @param array<string, mixed> $attributes */
function systemRecoveryDelivery(int $eventId, NotificationDestination $channel, array $attributes = []): int
{
    return DB::table('deliveries')->insertGetId(['event_id' => $eventId, 'destination_id' => $channel->id, 'state' => 'pending',
        'next_attempt_at' => now(), 'suppression_evaluated_at' => now(), 'origin' => 'system', 'category' => 'system', ...$attributes]);
}

it('sends a newer recovery on a working channel while 25 older mail recoveries keep failing', function (): void {
    Mail::mailer()->setSymfonyTransport(new FakeSmtpTransport('MAIL FROM:<sender@example.test>', 421));
    Http::fake(['https://slack.com/api/chat.postMessage' => Http::response(['ok' => true, 'ts' => '1.2'])]);
    $eventId = resolvedIssueRecoveryEvent();
    foreach (range(1, 25) as $index) {
        $team = NotificationDestination::factory()->create(['label' => 'Team mail '.$index]);
        systemIssueCell($team, NotificationMode::Immediate);
        systemRecoveryDelivery($eventId, $team, ['next_attempt_at' => now()->subMinutes(10)]);
    }
    $slack = systemRecoveryDelivery($eventId, systemSlackChannel(), ['next_attempt_at' => now()->subMinute()]);

    $passes = 0;
    while (DB::table('deliveries')->where('id', $slack)->value('state') !== 'sent' && $passes < 3) {
        evaluateSystemIssues();
        $passes++;
        $this->travel(301)->seconds();
    }

    expect(DB::table('deliveries')->where('id', $slack)->value('state'))->toBe('sent');
    expect($passes)->toBe(1);
    // The failing transport is attempted once per pass, not once per waiting row.
    expect((int) DB::table('deliveries')->where('event_id', $eventId)->where('destination_id', '<>', DB::table('deliveries')->where('id', $slack)->value('destination_id'))->sum('attempts'))->toBe(1);
});

it('attempts at most twenty System deliveries per evaluation pass and the rest on the next pass', function (): void {
    Mail::mailer()->setSymfonyTransport($transport = new ArrayTransport);
    $eventId = resolvedIssueRecoveryEvent();
    foreach (range(1, 27) as $index) {
        $team = NotificationDestination::factory()->create(['label' => 'Team mail '.$index]);
        systemIssueCell($team, NotificationMode::Immediate);
        systemRecoveryDelivery($eventId, $team, ['next_attempt_at' => now()->subMinutes(30 - $index)]);
    }

    evaluateSystemIssues();
    expect($transport->messages())->toHaveCount(20);
    // Oldest due first: the seven newest wait for the next pass.
    expect(DB::table('deliveries')->where('event_id', $eventId)->where('state', 'pending')->orderBy('id')->pluck('id')->all())
        ->toBe(DB::table('deliveries')->where('event_id', $eventId)->orderBy('id')->pluck('id')->slice(20)->values()->all());

    evaluateSystemIssues();
    expect($transport->messages())->toHaveCount(27);
});

it('allows probe rounds above zero only on System deliveries, never on deliveries without a category', function (): void {
    $eventId = resolvedIssueRecoveryEvent();
    $channel = NotificationDestination::factory()->create();
    systemRecoveryDelivery($eventId, $channel);
    expect(systemRecoveryDelivery($eventId, $channel, ['probe_round' => 1]))->toBeInt();

    foreach ([null, 'warning'] as $category) {
        expect(fn () => DB::transaction(fn (): int => systemRecoveryDelivery($eventId, $channel, ['probe_round' => 2, 'category' => $category])))
            ->toThrow(QueryException::class, 'deliveries_probe_round');
    }
});
