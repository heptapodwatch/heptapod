<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

interface DailyMetricHistoryProvider
{
    /** @return non-empty-list<string> Metric keys whose daily averages the check tab charts. */
    public function dailyHistoryMetrics(): array;
}
