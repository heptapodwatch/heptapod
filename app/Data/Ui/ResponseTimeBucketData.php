<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** p95 belongs to one check's stored bucket. Dashboard aggregates must use null, never average percentiles. */
#[TypeScript]
final class ResponseTimeBucketData extends Data
{
    public function __construct(
        public readonly string $time,
        public readonly ?float $average_ms,
        public readonly ?float $p95_ms,
        public readonly ?float $maximum_ms = null,
        public readonly int $failure_count = 0,
        public readonly int $error_count = 0,
        public readonly int $warning_count = 0,
    ) {}
}
