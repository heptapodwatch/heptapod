<?php

declare(strict_types=1);

namespace Heptapod\Addons\Performance;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class PerformanceMetrics extends Data
{
    public function __construct(
        public readonly float $average_response_time_in_ms,
        public readonly ?float $previous_average_response_time_in_ms,
        public readonly ?float $change_percentage,
        public readonly int $sample_count,
        public readonly int $previous_sample_count,
        public readonly int $consecutive_warning_samples = 0,
        public readonly int $consecutive_good_samples = 0,
    ) {}
}
