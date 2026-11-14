<?php

declare(strict_types=1);

namespace Heptapod\Enums;

use Heptapod\Checks\History\RetentionPolicy;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A group of tables that grows by one intake and is trimmed by one retention setting; Other is the rest of the database. */
#[TypeScript]
enum DatabaseDataClass: string
{
    case CheckResults = 'check_results';
    case Attempts = 'attempts';
    case HourlyRollups = 'hourly_rollups';
    case DailyRollups = 'daily_rollups';
    case History = 'history';
    case Other = 'other';

    /** @return list<string> Core tables; check results add the tables of every addon's RunPersistence::prunedTables(). */
    public function coreTables(): array
    {
        return match ($this) {
            self::CheckResults => ['check_runs'],
            self::Attempts => ['check_run_attempts'],
            self::HourlyRollups => ['check_run_rollups_hourly'],
            self::DailyRollups => ['check_run_rollups_daily'],
            self::History => ['events', 'deliveries', 'incidents', 'activity_log'],
            self::Other => [],
        };
    }

    /** @return array{0: string, 1: string}|null The table and indexed timestamp whose 24-hour row count is the daily intake. */
    public function intakeColumn(): ?array
    {
        return match ($this) {
            self::CheckResults => ['check_runs', 'ended_at'],
            self::Attempts => ['check_run_attempts', 'requested_at'],
            self::HourlyRollups => ['check_run_rollups_hourly', 'bucket_start'],
            self::DailyRollups => ['check_run_rollups_daily', 'bucket_start'],
            self::History => ['events', 'occurred_at'],
            self::Other => null,
        };
    }

    public function retentionVariable(): ?string
    {
        return match ($this) {
            self::CheckResults => 'MONITORING_RAW_RETENTION_DAYS',
            self::Attempts => 'MONITORING_ATTEMPT_RETENTION_DAYS',
            self::HourlyRollups => 'MONITORING_HOURLY_ROLLUP_RETENTION_DAYS',
            self::DailyRollups => 'MONITORING_DAILY_ROLLUP_RETENTION_DAYS',
            self::History => 'MONITORING_HISTORY_RETENTION_DAYS',
            self::Other => null,
        };
    }

    public function configuredDays(RetentionPolicy $policy): ?int
    {
        return match ($this) {
            self::CheckResults => $policy->rawDays,
            self::Attempts => $policy->attemptDays,
            self::HourlyRollups => $policy->hourlyDays,
            self::DailyRollups => $policy->dailyDays,
            self::History => $policy->historyDays,
            self::Other => null,
        };
    }

    /** The longest a row stays when maintenance actually deletes this class, or null when nothing deletes it yet. */
    public function enforcedDays(RetentionPolicy $policy): ?int
    {
        return match ($this) {
            // Raw results leave with their whole monthly partition, so a row stays up to one month past the setting.
            self::CheckResults => $policy->rawDays + 31,
            self::Attempts => $policy->attemptDays,
            self::HourlyRollups => $policy->hourlyDeletionEnabled ? $policy->hourlyDays : null,
            self::DailyRollups, self::History, self::Other => null,
        };
    }
}
