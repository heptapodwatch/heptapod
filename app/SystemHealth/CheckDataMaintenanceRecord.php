<?php

declare(strict_types=1);

namespace Heptapod\SystemHealth;

use Carbon\CarbonImmutable;
use Heptapod\Enums\HealthStatus;
use Illuminate\Support\Facades\Cache;
use UnexpectedValueException;

/**
 * Outcome of heptapod:maintain-check-data runs, kept in Redis beside the other health timestamps.
 * CheckDataMaintenance writes it while holding the maintenance advisory lock, so read-modify-write is safe across overlapping runs.
 */
final class CheckDataMaintenanceRecord
{
    private const string Key = 'heptapod:check-data-maintenance:outcome';

    /** The command runs hourly; two failed runs in a row, or no success for this long, need attention. */
    private const int WarnAfterConsecutiveFailures = 2;

    private const int WarnAfterSecondsWithoutSuccess = 3 * 3600;

    public function recordSuccess(CarbonImmutable $startedAt, float $durationSeconds): void
    {
        Cache::store('redis')->forever(self::Key, [
            ...$this->stored(),
            'last_success_at' => $startedAt->utc()->toIso8601String(),
            'last_success_seconds' => round($durationSeconds, 1),
            'consecutive_failures' => 0,
        ]);
    }

    /** @return int consecutive failures including this one */
    public function recordFailure(CarbonImmutable $startedAt): int
    {
        $stored = $this->stored();
        $failures = $stored['consecutive_failures'] + 1;
        Cache::store('redis')->forever(self::Key, [
            ...$stored,
            'last_failure_at' => $startedAt->utc()->toIso8601String(),
            'consecutive_failures' => $failures,
        ]);

        return $failures;
    }

    public function read(): CheckDataMaintenanceHealth
    {
        $stored = $this->stored();
        $lastSuccessAt = $stored['last_success_at'] === null ? null : CarbonImmutable::parse($stored['last_success_at']);
        $status = match (true) {
            ! $lastSuccessAt instanceof CarbonImmutable && $stored['last_failure_at'] === null => HealthStatus::NotConfigured,
            $stored['consecutive_failures'] >= self::WarnAfterConsecutiveFailures,
            ! $lastSuccessAt instanceof CarbonImmutable,
            $lastSuccessAt->diffInSeconds(CarbonImmutable::now()) > self::WarnAfterSecondsWithoutSuccess => HealthStatus::Warn,
            default => HealthStatus::Ok,
        };

        return new CheckDataMaintenanceHealth($status, $stored['last_success_at'], $stored['last_success_seconds'], $stored['last_failure_at'], $stored['consecutive_failures']);
    }

    /** @return array{last_success_at: string|null, last_success_seconds: float|null, last_failure_at: string|null, consecutive_failures: int} */
    private function stored(): array
    {
        $value = Cache::store('redis')->get(self::Key) ?? ['last_success_at' => null, 'last_success_seconds' => null, 'last_failure_at' => null, 'consecutive_failures' => 0];
        if (! is_array($value)
            || ! array_key_exists('last_success_at', $value) || ($value['last_success_at'] !== null && ! is_string($value['last_success_at']))
            || ! array_key_exists('last_success_seconds', $value) || ($value['last_success_seconds'] !== null && ! is_float($value['last_success_seconds']))
            || ! array_key_exists('last_failure_at', $value) || ($value['last_failure_at'] !== null && ! is_string($value['last_failure_at']))
            || ! isset($value['consecutive_failures']) || ! is_int($value['consecutive_failures'])) {
            throw new UnexpectedValueException('Check data maintenance outcome in the health cache is invalid.');
        }

        return $value;
    }
}
