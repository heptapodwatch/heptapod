<?php

declare(strict_types=1);

namespace Heptapod\Checks;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class CheckSchedule
{
    /** Epoch-aligned slots keep each check's phase stable, independent of run duration. */
    public static function nextSlot(int $checkId, int $intervalSeconds, CarbonImmutable $after): CarbonImmutable
    {
        if ($checkId < 1 || $intervalSeconds < 1) {
            throw new InvalidArgumentException('Check IDs and schedule intervals must be positive.');
        }
        $phase = crc32((string) $checkId) % $intervalSeconds;
        $slot = (int) floor(($after->getTimestamp() - $phase) / $intervalSeconds) + 1;

        return CarbonImmutable::createFromTimestampUTC($phase + $slot * $intervalSeconds);
    }
}
