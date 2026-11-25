<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Enums\ResponseTimeRange;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ResponseTimeSeriesData extends Data
{
    /**
     * @param  list<ResponseTimeBucketData>  $points  24h: 5-minute buckets from raw runs; 7d: hourly rollups; 30d-1y: UTC days; all: UTC weeks.
     * @param  float|null  $recent_average_ms  Weighted response time over the previous 15 minutes, not an average of bucket averages.
     */
    public function __construct(
        public readonly ResponseTimeRange $key,
        public readonly array $points,
        public readonly ?float $recent_average_ms,
    ) {}
}
