<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Carbon\CarbonImmutable;

interface DailyRollupReader
{
    /** @return list<array{bucket_start: CarbonImmutable, metric_averages: array<string, float>}> */
    public function daily(int $checkId, CarbonImmutable $from, CarbonImmutable $until): array;
}
