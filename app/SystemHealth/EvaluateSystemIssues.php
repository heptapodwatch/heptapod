<?php

declare(strict_types=1);

namespace Heptapod\SystemHealth;

use Carbon\CarbonImmutable;
use Heptapod\Actions\ClaimNotificationDelivery;
use Heptapod\Actions\CompleteNotificationDelivery;
use Heptapod\Actions\SendNotificationDelivery;
use Heptapod\Contracts\Mail\MailDeliveryMode;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Events\Notifications\UptimeAlertEpisode;
use Heptapod\Models\Delivery;
use Heptapod\Models\DispatcherHeartbeat;
use Heptapod\Models\Event;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\SystemIssue;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one place that decides whether the instance itself is unhealthy. Each kind has an open rule; recovery is the
 * open rule turning false (mail_transport_dead recovers only after an accepted message submission).
 * The database keeps at most one open issue per kind and scope; System deliveries in the ledger announce the opening
 * and the recovery once per recipient.
 */
final readonly class EvaluateSystemIssues
{
    public const string EvaluationKey = 'heptapod:system-issues:evaluation';

    /** Last success of the heptapod:evaluate-system-issues command; dispatcher fallback evaluations do not write it. */
    public const string CommandEvaluationKey = 'heptapod:system-issues:command-evaluation';

    /** Maintenance runs hourly: two failed runs in a row, or no success for three hours (a hung run), is a real failure. */
    public const int MaintenanceFailuresToOpen = 2;

    /** backup:run runs daily at 02:30 UTC (routes/console.php): one day plus two hours for the run itself. */
    public const int BackupStaleAfterSeconds = 26 * 3600;

    /** Heartbeats tick every minute (scheduler) and every 5 s (dispatcher); System health calls them down after 3 and 2 minutes. */
    public const int HeartbeatStaleAfterSeconds = 600;

    public const int MailFailuresToOpen = 3;

    public const int ChecksNotRunningAfterSeconds = 600;

    /** The dispatcher runs every minute and on every new event; ten minutes of waiting work is a stall, not a slow pass. */
    public const int NotificationsStalledAfterSeconds = 600;

    /**
     * The scheduler evaluates every 5 minutes; three missed evaluations mean it hangs or keeps failing: the dispatcher
     * evaluates instead and opens scheduler_stale.
     */
    public const int EvaluationStaleAfterSeconds = 900;

    /** A site deletion purges a site's history in minutes (PurgeDeletedSite); one still pending after an hour keeps failing. */
    public const int SiteDeletionStuckAfterSeconds = 3600;

    public function __construct(private CheckDataMaintenanceRecord $maintenance, private MailTransportRecord $transport, private SystemIssueMailer $mailer, private SystemTransportProbe $probe, private SendNotificationDelivery $send, private FinishedCheckAge $finishedChecks, private DiskSpaceIssues $diskSpace, private CheckFreshnessIssues $freshness, private MonitorOutageDetector $monitorOutage) {}

    public function __invoke(CarbonImmutable $now): void
    {
        try {
            $locks = Cache::store('redis')->getStore();
            if (! $locks instanceof LockProvider) {
                throw new \LogicException('The Redis cache store must provide locks.');
            }
            // The scheduler and a dispatcher can evaluate at the same time; one evaluation at a time keeps mails single.
            $locks->lock(self::EvaluationKey.':lock', 300)->block(60, function () use ($now): void {
                $this->transport->seedFromDeliveries();
                // A dead transport recovers only through one accepted real submission, never a handshake.
                $dead = SystemIssue::open()->where('kind', SystemIssueKind::MailTransportDead->value)->first();
                if ($dead instanceof SystemIssue) {
                    $this->probe->run($dead);
                }
                foreach (SystemIssueKind::cases() as $kind) {
                    if (! in_array($kind, [SystemIssueKind::DiskSpaceLow, SystemIssueKind::CheckNotFresh, SystemIssueKind::ChannelFailing], true)) {
                        $this->apply($kind, $now);
                    }
                }
                $paths = $this->diskSpace->paths();
                foreach ($paths as $path) {
                    $open = SystemIssue::open()->where('kind', SystemIssueKind::DiskSpaceLow->value)->where('scope', $path)->first();
                    $this->transition(SystemIssueKind::DiskSpaceLow, $now, $open, $this->diskSpace->detect($path), $path);
                }
                // A retired mount or workspace parent must recover through the same delivery lifecycle as a healthy path.
                $retiredIssues = SystemIssue::open()->where('kind', SystemIssueKind::DiskSpaceLow->value);
                $retiredIssues->getQuery()->whereNotIn('scope', $paths);
                foreach ($retiredIssues->get() as $retired) {
                    $this->transition(SystemIssueKind::DiskSpaceLow, $now, $retired, null, $retired->scope);
                }
                $this->applyCheckFreshness($now);
                $this->applyChannelFailing($now);
                $this->sendDue();
            });
        } catch (Throwable $exception) {
            // Fail loud: System health shows the last evaluation error until an evaluation succeeds.
            Cache::store('redis')->forever(self::EvaluationKey, ['evaluated_at' => $this->lastEvaluatedAt(), 'failed_at' => $now->toIso8601String(), 'error' => mb_strimwidth($exception->getMessage(), 0, 300, '…')]);
            Log::error('System issue evaluation failed.', ['exception' => $exception->getMessage()]);

            throw $exception;
        }
        Cache::store('redis')->forever(self::EvaluationKey, ['evaluated_at' => $now->toIso8601String(), 'failed_at' => null, 'error' => null]);
    }

    /**
     * System deliveries go out synchronously in this lock, as system mail always did: the alert about stopped workers
     * must not wait for a worker. At most DueBatch attempts per pass. A mail transport failure stops further mail in
     * this pass, and the batch is refilled with other channels only, so failing mail never starves them.
     */
    private function sendDue(): void
    {
        $attempts = 0;
        $includeMail = true;
        do {
            $refill = false;
            foreach ($this->mailer->due(SystemIssueMailer::DueBatch - $attempts, $includeMail) as $row) {
                if (! $includeMail && $row['channel'] === 'mail') {
                    continue;
                }
                $result = ($this->send)($row['id']);
                $attempts++;
                if ($includeMail && $row['channel'] === 'mail' && $result?->reason === 'notifications.transport_failed' && ! $result->recipientRejected()) {
                    $includeMail = false;
                    $refill = true;
                }
            }
        } while ($refill);
    }

    /** Read-only forecast used before the freshness migration; no issue or delivery is created. */
    public function suppressesCheckFreshness(CarbonImmutable $now): bool
    {
        return $this->schedulerStale($now) !== null || $this->checksNotRunning($now) !== null;
    }

    private function applyCheckFreshness(CarbonImmutable $now): void
    {
        $stale = $this->freshness->notFresh($now);
        $open = SystemIssue::open()->where('kind', SystemIssueKind::CheckNotFresh->value)->get()->keyBy('scope');
        $suppressed = SystemIssue::open()->getQuery()->whereIn('kind', [SystemIssueKind::ChecksNotRunning->value, SystemIssueKind::SchedulerStale->value])->exists();
        foreach ($stale as $checkId => $check) {
            $scope = (string) $checkId;
            if ($open->has($scope) || $suppressed) {
                continue;
            }
            if (! $check['suppressed']) {
                $this->transition(SystemIssueKind::CheckNotFresh, $now, null, $check['details'], $scope);
            }
        }
        foreach ($open as $scope => $issue) {
            if (! isset($stale[(int) $scope])) {
                $this->transition(SystemIssueKind::CheckNotFresh, $now, $issue, null, (string) $scope,
                    [...$issue->details, 'resolution' => $this->freshness->resolution($issue)]);
            }
        }
    }

    /**
     * A mail address can fail on its own (SMTP 4xx) while other mail is accepted, so mail channels count too. While
     * mail_transport_dead is open every address fails for the same reason: no new mail channel opens then.
     */
    private function applyChannelFailing(CarbonImmutable $now): void
    {
        $transportDead = SystemIssue::open()->where('kind', SystemIssueKind::MailTransportDead->value)->getQuery()->exists();
        $channels = NotificationDestination::query()->where('enabled', true)
            ->where('failing_since', '<=', $now->subSeconds(CompleteNotificationDelivery::GiveUpAfterSeconds));
        $channels->getQuery()->whereNull('removed_at')->orderBy('id');
        $failing = $channels->get()->keyBy('id');
        $open = SystemIssue::open()->where('kind', SystemIssueKind::ChannelFailing->value)->get()->keyBy('scope');
        foreach ($failing as $channelId => $channel) {
            if (! $open->has((string) $channelId) && (! $transportDead || $channel->channel !== 'mail')) {
                $this->transition(SystemIssueKind::ChannelFailing, $now, null,
                    ['channel' => $channel->label, 'failing_since' => $channel->failing_since?->toIso8601String()], (string) $channelId);
            }
        }
        foreach ($open as $scope => $issue) {
            if (! $failing->has((int) $scope)) {
                $this->transition(SystemIssueKind::ChannelFailing, $now, $issue, null, (string) $scope);
            }
        }
    }

    private function apply(SystemIssueKind $kind, CarbonImmutable $now): void
    {
        $open = SystemIssue::open()->where('kind', $kind->value)->where('scope', '')->first();
        $this->transition($kind, $now, $open, $this->detect($kind, $now, $open));
    }

    /**
     * @param  array<string, int|string|null>|null  $details
     * @param  array<string, int|string|null>|null  $resolvedDetails
     */
    private function transition(SystemIssueKind $kind, CarbonImmutable $now, ?SystemIssue $open, ?array $details, string $scope = '', ?array $resolvedDetails = null): void
    {
        $previousDetails = $open?->details;
        if ($previousDetails !== null) {
            ksort($previousDetails);
        }
        if ($details !== null) {
            ksort($details);
        }
        if ($details !== null && ! $open instanceof SystemIssue) {
            // The transition and its delivery rows commit together, so an interruption cannot lose an opening mail.
            DB::transaction(function () use ($kind, $details, $now, $scope): void {
                // The partial unique index makes a concurrent second opening for the same scope a no-op.
                $inserted = DB::table('system_issues')->insertOrIgnore(['kind' => $kind->value, 'scope' => $scope, 'details' => json_encode($details, JSON_THROW_ON_ERROR), 'opened_at' => $now]);
                $this->failAfterTransitionSeam($kind);
                if ($inserted === 1) {
                    $this->mailer->queueOpening(SystemIssue::open()->where('kind', $kind->value)->where('scope', $scope)->firstOrFail());
                }
            });
            Log::warning('System issue opened.', ['kind' => $kind->value, 'details' => $details]);
        } elseif (in_array($kind, [SystemIssueKind::DiskSpaceLow, SystemIssueKind::MailLogOnly, SystemIssueKind::MonitorOutage], true) && $details !== null && $previousDetails !== $details) {
            $open->update(['details' => $details]);
            Log::warning('System issue updated.', ['kind' => $kind->value, 'issue_id' => $open->id, 'details' => $details]);
        } elseif ($details === null && $open instanceof SystemIssue) {
            DB::transaction(function () use ($open, $kind, $now, $resolvedDetails): void {
                $open->update(['resolved_at' => $now, ...($resolvedDetails === null ? [] : ['details' => $resolvedDetails])]);
                $this->failAfterTransitionSeam($kind);
                $this->mailer->queueRecovery($open);
            });
            Log::info('System issue resolved.', ['kind' => $kind->value, 'issue_id' => $open->id]);
        }
    }

    /** Non-production failure seam (HEPTAPOD_DEV_FAIL_AFTER_TRANSITION=<kind>) between a transition and its delivery rows. */
    private function failAfterTransitionSeam(SystemIssueKind $kind): void
    {
        if (config('heptapod.system_issues.dev_fail_after_transition') === $kind->value) {
            throw new \RuntimeException('Dev seam: failure after the '.$kind->value.' transition, before its delivery rows.');
        }
    }

    /** @return array<string, int|string|null>|null the facts of an unhealthy condition, or null when healthy */
    private function detect(SystemIssueKind $kind, CarbonImmutable $now, ?SystemIssue $open): ?array
    {
        return match ($kind) {
            SystemIssueKind::CheckNotFresh, SystemIssueKind::DiskSpaceLow, SystemIssueKind::ChannelFailing => throw new \LogicException('Scoped issues require a scope.'),
            SystemIssueKind::MaintenanceFailed => $this->maintenanceFailed($now),
            SystemIssueKind::BackupFailed => $this->backupFailed(),
            SystemIssueKind::BackupStale => $this->backupStale($now),
            SystemIssueKind::SchedulerStale => $this->schedulerStale($now),
            SystemIssueKind::ChecksNotRunning => $this->checksNotRunning($now),
            SystemIssueKind::MailLogOnly => MailDeliveryMode::includesLocalTransport() ? ['mailer' => config()->string('mail.default'), 'mode' => MailDeliveryMode::logOnly() ? 'local' : 'mixed'] : null,
            SystemIssueKind::MailTransportDead => $this->mailTransportDead($now, $open instanceof SystemIssue),
            SystemIssueKind::NotificationsStalled => $this->notificationsStalled($now),
            SystemIssueKind::MonitorOutage => $this->monitorOutage->evaluateSystemIssue($now, $open?->details),
            SystemIssueKind::SiteDeletionStuck => $this->siteDeletionStuck($now),
        };
    }

    /** @return array<string, int|string|null>|null */
    private function siteDeletionStuck(CarbonImmutable $now): ?array
    {
        $stuck = DB::table('sites')->whereNotNull('marked_for_deletion_at')
            ->where('marked_for_deletion_at', '<', $now->subSeconds(self::SiteDeletionStuckAfterSeconds)->format('Y-m-d H:i:s.uP'));
        $since = (clone $stuck)->min('marked_for_deletion_at');
        if ($since !== null && ! is_string($since)) {
            throw new \UnexpectedValueException('A deletion request time must be a string.');
        }

        return $since === null ? null : ['sites' => $stuck->count(), 'pending_since' => CarbonImmutable::parse($since, 'UTC')->toIso8601String()];
    }

    /**
     * The oldest event the dispatcher has not routed, or the oldest delivery it should have handed out, waits too long.
     * A delivery in backoff is not due yet, so a dead channel alone never counts as a stall. A claim keeps next_attempt_at
     * and only renews the lease, so a send that throws after every claim still ages from the time it fell due.
     *
     * @return array<string, string|null>|null
     */
    private function notificationsStalled(CarbonImmutable $now): ?array
    {
        $cutoff = $now->subSeconds(self::NotificationsStalledAfterSeconds);
        $unrouted = Event::query();
        $unrouted->getQuery()->whereNull('notification_routed_at')->orderBy('id');
        $event = $unrouted->first(['occurred_at']);
        $due = $cutoff->format('Y-m-d H:i:s.uP');
        $outstanding = Delivery::query()->where('suppressed', false)->where('digest', false)
            ->where(function (Builder $query) use ($due): void {
                $query->where(function (Builder $q) use ($due): void {
                    $q->getQuery()->whereIn('state', [DeliveryState::Pending->value, DeliveryState::Failed->value])->where('next_attempt_at', '<=', $due);
                })->orWhere(fn (Builder $q) => $q->where('state', DeliveryState::Sending->value)->where('next_attempt_at', '<=', $due));
            });
        $outstanding->getQuery()->whereRaw('(last_reason IS NULL OR last_reason NOT IN (?, ?))', [UptimeAlertEpisode::WaitingReason, ClaimNotificationDelivery::SystemProbeReason])
            ->orderBy('next_attempt_at')->orderBy('id');
        $delivery = $outstanding->first(['next_attempt_at']);
        if (($event === null || $event->occurred_at->greaterThan($cutoff)) && $delivery === null) {
            return null;
        }

        return ['oldest_unrouted_event_at' => $event?->occurred_at->toIso8601String(), 'oldest_due_delivery_at' => $delivery?->next_attempt_at->toIso8601String()];
    }

    /** @return array<string, int>|null */
    private function checksNotRunning(CarbonImmutable $now): ?array
    {
        $age = $this->finishedChecks->seconds($now);

        return $age !== null && $age > self::ChecksNotRunningAfterSeconds ? ['check_age_seconds' => $age] : null;
    }

    /** @return array<string, int|string|null>|null */
    private function maintenanceFailed(CarbonImmutable $now): ?array
    {
        $health = $this->maintenance->read();
        // Before the first success, the instance age decides, so a first run that hangs is caught too.
        $reference = $health->lastSuccessAt ?? $this->instanceCreatedAt();
        $stale = $reference !== null
            && CarbonImmutable::parse($reference, 'UTC')->diffInSeconds($now) > CheckDataMaintenanceRecord::WarnAfterSecondsWithoutSuccess;

        return $health->consecutiveFailures >= self::MaintenanceFailuresToOpen || $stale
            ? ['consecutive_failures' => $health->consecutiveFailures, 'last_failure_at' => $health->lastFailureAt, 'last_success_at' => $health->lastSuccessAt]
            : null;
    }

    /** @return array<string, int|string|null>|null */
    private function backupFailed(): ?array
    {
        $last = DB::table('backup_runs')->whereIn('state', ['complete', 'failed'])->orderByDesc('id')->first(['state', 'disk', 'completed_at']);
        if ($last === null || $last->state !== 'failed') {
            return null;
        }
        if (! is_string($last->disk) && $last->disk !== null || ! is_string($last->completed_at) && $last->completed_at !== null) {
            throw new \UnexpectedValueException('Backup run disk and completion time must be strings.');
        }

        return ['failed_at' => $last->completed_at === null ? null : CarbonImmutable::parse($last->completed_at, 'UTC')->toIso8601String(), 'disk' => $last->disk];
    }

    /** @return array<string, int|string|null>|null */
    private function backupStale(CarbonImmutable $now): ?array
    {
        $lastSuccess = DB::table('backup_runs')->where('state', 'complete')->max('completed_at');
        if ($lastSuccess !== null && ! is_string($lastSuccess)) {
            throw new \UnexpectedValueException('Backup completion time must be a string.');
        }
        // Before the first backup ever ran, the instance age decides: a fresh install is not overdue yet.
        $reference = $lastSuccess ?? $this->instanceCreatedAt();
        if ($reference === null) {
            return null;
        }

        return CarbonImmutable::parse($reference, 'UTC')->diffInSeconds($now) > self::BackupStaleAfterSeconds
            ? ['last_success_at' => $lastSuccess === null ? null : CarbonImmutable::parse($lastSuccess, 'UTC')->toIso8601String(), 'threshold_hours' => intdiv(self::BackupStaleAfterSeconds, 3600)]
            : null;
    }

    /** @return array<string, int|string|null>|null */
    private function schedulerStale(CarbonImmutable $now): ?array
    {
        $scheduler = Cache::store('redis')->get(config()->string('heptapod.health.scheduler_cache_key'));
        if ($scheduler !== null && ! is_string($scheduler)) {
            throw new \UnexpectedValueException('Scheduler heartbeat must be an ISO-8601 string.');
        }
        $query = DispatcherHeartbeat::query();
        $query->getQuery()->orderByDesc('last_seen_at');
        $dispatcher = $query->first()?->last_seen_at;
        $stale = static fn (?CarbonImmutable $at): bool => ! $at instanceof CarbonImmutable || $at->diffInSeconds($now) > self::HeartbeatStaleAfterSeconds;
        $schedulerAt = $scheduler === null ? null : CarbonImmutable::parse($scheduler);
        $commandEvaluation = Cache::store('redis')->get(self::CommandEvaluationKey);
        if ($commandEvaluation !== null && ! is_string($commandEvaluation)) {
            throw new \UnexpectedValueException('Command evaluation time must be an ISO-8601 string.');
        }
        // Before the command ever succeeded (fresh install or upgrade), the last evaluation of any kind is the reference.
        $evaluationReference = $commandEvaluation ?? $this->lastEvaluatedAt();
        $evaluationStale = $evaluationReference !== null
            && CarbonImmutable::parse($evaluationReference)->diffInSeconds($now) > self::EvaluationStaleAfterSeconds;

        return $stale($schedulerAt) || $stale($dispatcher) || $evaluationStale
            ? ['scheduler_seen_at' => $schedulerAt?->toIso8601String(), 'dispatcher_seen_at' => $dispatcher?->toIso8601String(),
                'scheduler_evaluated_at' => $commandEvaluation]
            : null;
    }

    public function recordCommandEvaluation(CarbonImmutable $now): void
    {
        Cache::store('redis')->forever(self::CommandEvaluationKey, $now->toIso8601String());
    }

    private function instanceCreatedAt(): ?string
    {
        $createdAt = DB::table('users')->min('created_at');
        if ($createdAt !== null && ! is_string($createdAt)) {
            throw new \UnexpectedValueException('User creation time must be a string.');
        }

        return $createdAt;
    }

    /** @return array<string, int|string|null>|null */
    private function mailTransportDead(CarbonImmutable $now, bool $isOpen): ?array
    {
        $health = $this->transport->read();
        $quietSince = $health->lastSuccessAt ?? $health->failingSince;
        $dead = $health->consecutiveFailures >= self::MailFailuresToOpen && $quietSince !== null
            && CarbonImmutable::parse($quietSince)->diffInSeconds($now) > config()->integer('heptapod.system_issues.mail_dead_after_seconds');
        // Once open, it stays open until a submission succeeds (which resets the count), so an ongoing outage cannot flap.
        if ($isOpen ? $health->consecutiveFailures === 0 : ! $dead) {
            return null;
        }

        return ['failing_since' => $health->failingSince, 'consecutive_failures' => $health->consecutiveFailures,
            'last_success_at' => $health->lastSuccessAt, 'last_failure' => $health->lastFailureDiagnosis];
    }

    public function lastEvaluatedAt(): ?string
    {
        $stored = Cache::store('redis')->get(self::EvaluationKey);

        return is_array($stored) && is_string($stored['evaluated_at'] ?? null) ? $stored['evaluated_at'] : null;
    }
}
