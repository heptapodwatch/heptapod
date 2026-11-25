<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One check's bucket: its average and 95th percentile ("slow moments"); weekly buckets carry the slowest day's p95. */
#[TypeScript]
final class ResponseTimeBucketData extends Data
{
    public function __construct(
        public readonly string $time,
        public readonly ?float $average_ms,
        public readonly ?float $p95_ms,
        public readonly int $failure_count = 0,
        public readonly int $error_count = 0,
        public readonly int $warning_count = 0,
    ) {}
}
