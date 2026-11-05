<?php

declare(strict_types=1);

namespace Heptapod\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Uptime color uses estimated failed seconds or an overlapping outage; check errors are not downtime.
 * Without observations or an outage, empty buckets are maintenance only with an overlapping interval, otherwise no_data.
 * Current pause/enable state must never be projected backwards into historical gaps.
 */
#[TypeScript]
enum BucketStatus: string
{
    case Failed = 'failed';
    case Interrupted = 'interrupted';
    case Errored = 'errored';
    case Warning = 'warning';
    case Ok = 'ok';
    case Maintenance = 'maintenance';
    case NoData = 'no_data';

    public static function forUptime(int $failedSeconds, bool $outage, int $errored, int $warning, int $succeeded, bool $maintenance = false): self
    {
        return match (true) {
            $outage || $failedSeconds >= 300 => self::Failed,
            $failedSeconds > 0 => self::Interrupted,
            $errored > 0 => self::Errored,
            $warning > 0 => self::Warning,
            $succeeded > 0 => self::Ok,
            $maintenance => self::Maintenance,
            default => self::NoData,
        };
    }
}
