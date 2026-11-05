<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

final class PerformanceResponseTimeWindow
{
    public const int MaximumUptimeIntervalSeconds = 43200;

    /** The 23h cap cannot overlap the previous day's baseline UTC hour. */
    public static function seconds(?int $uptimeIntervalSeconds): int
    {
        return min(82800, max(3600, 2 * ($uptimeIntervalSeconds ?? 0)));
    }
}
