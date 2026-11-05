<?php

declare(strict_types=1);

namespace Heptapod\SystemHealth;

use Carbon\CarbonImmutable;
use Heptapod\Actions\SendNotificationDelivery;
use Heptapod\Contracts\Mail\MailDeliveryMode;
use Heptapod\Models\DispatcherHeartbeat;
use Heptapod\Models\SystemIssue;
use Illuminate\Contracts\Cache\LockProvider;
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

    /** Maintenance runs hourly: two failed runs in a row is a real failure, not one unlucky run. */
    public const int MaintenanceFailuresToOpen = 2;

    /** backup:run runs daily at 02:30 UTC (routes/console.php): one day plus two hours for the run itself. */
    public const int BackupStaleAfterSeconds = 26 * 3600;

    /** Heartbeats tick every minute (scheduler) and every 5 s (dispatcher); System health calls them down after 3 and 2 minutes. */
    public const int HeartbeatStaleAfterSeconds = 600;

    public const int MailFailuresToOpen = 3;

    public const int ChecksNotRunningAfterSeconds = 600;

    public function __construct(private CheckDataMaintenanceRecord $maintenance, private MailTransportRecord $transport, private SystemIssueMailer $mailer, private SystemTransportProbe $probe, private SendNotificationDelivery $send, private FinishedCheckAge $finishedChecks, private DiskSpaceIssues $diskSpace, private CheckFreshnessIssues $freshness) {}

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
                    if (! in_array($kind, [SystemIssueKind::DiskSpaceLow, SystemIssueKind::CheckNotFresh], true)) {
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

    private function apply(SystemIssueKind $kind, CarbonImmutable $now): void
    {
        $open = SystemIssue::open()->where('kind', $kind->value)->where('scope', '')->first();
        $this->transition($kind, $now, $open, $this->detect($kind, $now, $open instanceof SystemIssue));
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
        } elseif (in_array($kind, [SystemIssueKind::DiskSpaceLow, SystemIssueKind::MailLogOnly], true) && $details !== null && $previousDetails !== $details) {
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
    private function detect(SystemIssueKind $kind, CarbonImmutable $now, bool $isOpen): ?array
    {
        return match ($kind) {
            SystemIssueKind::CheckNotFresh, SystemIssueKind::DiskSpaceLow => throw new \LogicException('Scoped issues require a scope.'),
            SystemIssueKind::MaintenanceFailed => $this->maintenanceFailed(),
            SystemIssueKind::BackupFailed => $this->backupFailed(),
            SystemIssueKind::BackupStale => $this->backupStale($now),
            SystemIssueKind::SchedulerStale => $this->schedulerStale($now),
            SystemIssueKind::ChecksNotRunning => $this->checksNotRunning($now),
            SystemIssueKind::MailLogOnly => MailDeliveryMode::includesLocalTransport() ? ['mailer' => config()->string('mail.default'), 'mode' => MailDeliveryMode::logOnly() ? 'local' : 'mixed'] : null,
            SystemIssueKind::MailTransportDead => $this->mailTransportDead($now, $isOpen),
        };
    }

    /** @return array<string, int>|null */
    private function checksNotRunning(CarbonImmutable $now): ?array
    {
        $age = $this->finishedChecks->seconds($now);

        return $age !== null && $age > self::ChecksNotRunningAfterSeconds ? ['check_age_seconds' => $age] : null;
    }

    /** @return array<string, int|string|null>|null */
    private function maintenanceFailed(): ?array
    {
        $health = $this->maintenance->read();

        return $health->consecutiveFailures >= self::MaintenanceFailuresToOpen
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
        $reference = $lastSuccess ?? DB::table('users')->min('created_at');
        if ($reference === null) {
            return null;
        }
        if (! is_string($reference)) {
            throw new \UnexpectedValueException('Backup reference time must be a string.');
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

        return $stale($schedulerAt) || $stale($dispatcher)
            ? ['scheduler_seen_at' => $schedulerAt?->toIso8601String(), 'dispatcher_seen_at' => $dispatcher?->toIso8601String()]
            : null;
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

    private function lastEvaluatedAt(): ?string
    {
        $stored = Cache::store('redis')->get(self::EvaluationKey);

        return is_array($stored) && is_string($stored['evaluated_at'] ?? null) ? $stored['evaluated_at'] : null;
    }
}
