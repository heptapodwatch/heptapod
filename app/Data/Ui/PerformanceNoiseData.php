<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class PerformanceNoiseData extends Data
{
    /**
     * @param  list<array{time: string, baseline: float, median: float, threshold: float, marked: bool}>  $points  The band edge combines the retained relative/p90 limit and the definition's minimum increase. Missing retained limits stay absent; p90 is never inferred.
     */
    public function __construct(
        public readonly int $minimum_increase_ms,
        public readonly array $points,
    ) {}
}
