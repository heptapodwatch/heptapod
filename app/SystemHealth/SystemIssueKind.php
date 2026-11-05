<?php

declare(strict_types=1);

namespace Heptapod\SystemHealth;

use Heptapod\Enums\HealthStatus;
use Heptapod\Models\User;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A condition of the instance itself that Owners must hear about. Open and recovery rules live in EvaluateSystemIssues. */
#[TypeScript]
enum SystemIssueKind: string
{
    case CheckNotFresh = 'check_not_fresh';
    case DiskSpaceLow = 'disk_space_low';
    case MaintenanceFailed = 'maintenance_failed';
    case BackupFailed = 'backup_failed';
    case BackupStale = 'backup_stale';
    case SchedulerStale = 'scheduler_stale';
    case ChecksNotRunning = 'checks_not_running';
    case MailLogOnly = 'mail_log_only';
    case MailTransportDead = 'mail_transport_dead';

    /** @param array<string, int|string|null> $details */
    public function titleKey(array $details = []): string
    {
        return match ($this) {
            self::CheckNotFresh => 'system_issue.check_not_fresh.title',
            self::DiskSpaceLow => 'Disk space is low or cannot be measured',
            self::MaintenanceFailed => 'Check history maintenance failed twice in a row',
            self::BackupFailed => 'The last backup failed',
            self::BackupStale => 'No successful backup for more than 26 hours',
            self::SchedulerStale => 'The scheduler or the check dispatcher stopped',
            self::ChecksNotRunning => 'No check has finished for more than ten minutes',
            self::MailLogOnly => ($details['mode'] ?? 'local') === 'mixed' ? 'Some email may only be logged' : 'Email delivery is not configured',
            self::MailTransportDead => 'Email delivery is failing',
        };
    }

    /** @param array<string, int|string|null> $details */
    public function actionKey(array $details = []): string
    {
        return match ($this) {
            self::CheckNotFresh => 'system_issue.check_not_fresh.action',
            self::DiskSpaceLow => 'Check the path and its free space. Warning below 20%; critical below 10%.',
            self::MaintenanceFailed => 'Rollups and retention stop until a run succeeds. Read the scheduler log for the maintain-check-data command.',
            self::BackupFailed => 'Check the backup disk and its credentials, then start a backup in Settings › Updates.',
            self::BackupStale => 'Backups run daily at 02:30 UTC. Check that the scheduler runs and the backup disk is reachable.',
            self::SchedulerStale => 'Checks, maintenance and backups do not run. Restart the scheduler and dispatcher services.',
            self::ChecksNotRunning => 'Checks are due but no results arrive. Check that the Horizon worker service exists and is running.',
            self::MailLogOnly => ($details['mode'] ?? 'local') === 'mixed' ? 'Remove log and array members from the mail transport to guarantee real delivery attempts.' : 'Set MAIL_MAILER to an SMTP or API transport. Until then no email leaves this instance.',
            self::MailTransportDead => 'No email reaches anyone. Check the SMTP host, port and credentials. A recovery email follows when sending works again.',
        };
    }

    /** @return array{action_key: string, href: string} */
    public function navigationAction(?User $user): array
    {
        if ($this === self::MailLogOnly && $user?->hasRole('Owner') === true) {
            return ['action_key' => 'Open mail settings', 'href' => route('settings.instance.edit')];
        }
        if ($user?->hasAnyRole(['Owner', 'Admin']) === true) {
            return ['action_key' => 'Open System health', 'href' => route('settings.system.edit')];
        }

        return ['action_key' => 'Learn more', 'href' => $this === self::MailLogOnly
            ? 'https://heptapod.app/docs/notifications#email-setup'
            : 'https://heptapod.app/docs/deploy/hetzner'];
    }

    public function severity(): HealthStatus
    {
        return match ($this) {
            self::SchedulerStale, self::ChecksNotRunning, self::MailTransportDead => HealthStatus::Down,
            default => HealthStatus::Warn,
        };
    }

    /**
     * A log/array mailer and a dead transport cannot deliver the opening mail, so those kinds open on non-mail System
     * channels only. A recovered log-only mailer was never announced by mail, so its recovery is not mailed either.
     */
    public function mailsOnOpen(): bool
    {
        return ! in_array($this, [self::MailLogOnly, self::MailTransportDead], true);
    }
}
