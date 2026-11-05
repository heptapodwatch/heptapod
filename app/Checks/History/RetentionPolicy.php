<?php

declare(strict_types=1);

namespace Heptapod\Checks\History;

use LogicException;

final readonly class RetentionPolicy
{
    public const int PerTableCap = 200000;

    private function __construct(
        public int $rawDays,
        public int $attemptDays,
        public int $hourlyDays,
        public int $dailyDays,
        public int $historyDays,
        public int $failedJobDays,
        public bool $hourlyDeletionEnabled,
    ) {}

    public static function fromConfig(): self
    {
        $values = [];
        foreach (['raw', 'attempt', 'hourly_rollup', 'daily_rollup', 'history', 'failed_job'] as $period) {
            $value = config('monitoring.'.$period.'_retention_days');
            if (! is_int($value) || $value < 1) {
                throw new LogicException('monitoring.'.$period.'_retention_days must be a positive integer.');
            }
            $values[] = $value;
        }
        [$raw, $attempt, $hourly, $daily, $history, $failedJob] = $values;
        if ($raw > $hourly || $hourly > $daily || $raw > $history || $hourly < 92 || $history < 93) {
            throw new LogicException('Retention requires raw <= hourly <= daily and raw <= history. The 90-local-day history view requires hourly_rollup_retention_days >= 92 and history_retention_days >= 93 (UTC+14 margin).');
        }
        $enabled = config('monitoring.hourly_rollup_deletion_enabled');
        if (! is_bool($enabled)) {
            throw new LogicException('monitoring.hourly_rollup_deletion_enabled must be a boolean.');
        }

        return new self($raw, $attempt, $hourly, $daily, $history, $failedJob, $enabled);
    }
}
