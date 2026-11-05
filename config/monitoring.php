<?php

declare(strict_types=1);

$retentionDays = static function (string $key, int $default): int {
    $value = env($key, $default);
    if (is_bool($value) || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
        throw new LogicException($key.' must be a positive integer.');
    }

    return (int) $value;
};

return [
    'raw_retention_days' => $retentionDays('MONITORING_RAW_RETENTION_DAYS', 30),
    'attempt_retention_days' => $retentionDays('MONITORING_ATTEMPT_RETENTION_DAYS', 7),
    'hourly_rollup_retention_days' => $retentionDays('MONITORING_HOURLY_ROLLUP_RETENTION_DAYS', 92),
    'daily_rollup_retention_days' => $retentionDays('MONITORING_DAILY_ROLLUP_RETENTION_DAYS', 730),
    'history_retention_days' => $retentionDays('MONITORING_HISTORY_RETENTION_DAYS', 365),
    'failed_job_retention_days' => $retentionDays('MONITORING_FAILED_JOB_RETENTION_DAYS', 7),
    // Enable only after reviewing the dry-run counts on the production instance.
    'hourly_rollup_deletion_enabled' => env('MONITORING_HOURLY_ROLLUP_DELETION_ENABLED', false),
];
