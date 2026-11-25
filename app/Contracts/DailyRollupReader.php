<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Carbon\CarbonImmutable;

interface DailyRollupReader
{
    /**
     * UTC weeks from Monday, from the first week with data until the week holding the day before `until`; empty weeks
     * have empty maps. Averages merge sums and counts; `metric_p95_max` is the slowest day's p95.
     *
     * @param  list<string>  $metrics
     * @return list<array{bucket_start: CarbonImmutable, metric_averages: array<string, float>, metric_p95_max: array<string, float>}>
     */
    public function weekly(int $checkId, array $metrics, CarbonImmutable $from, CarbonImmutable $until): array;
}
