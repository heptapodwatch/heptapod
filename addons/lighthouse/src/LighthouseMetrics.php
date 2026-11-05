<?php

declare(strict_types=1);

namespace Heptapod\Addons\Lighthouse;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class LighthouseMetrics extends Data
{
    public function __construct(
        public readonly int $performance_score,
        public readonly int $accessibility_score,
        public readonly int $best_practices_score,
        public readonly int $seo_score,
        public readonly ?int $progressive_web_app_score,
        public readonly ?float $first_contentful_paint_in_ms,
        public readonly ?float $speed_index_in_ms,
        public readonly ?float $largest_contentful_paint_in_ms,
        public readonly ?float $time_to_interactive_in_ms,
        public readonly ?float $total_blocking_time_in_ms,
        public readonly ?float $cumulative_layout_shift,
    ) {}
}
