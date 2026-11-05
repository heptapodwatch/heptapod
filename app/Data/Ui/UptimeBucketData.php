<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Enums\BucketStatus;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class UptimeBucketData extends Data
{
    public function __construct(
        public readonly string $date,
        public readonly ?float $uptime,
        public readonly int $succeeded_count,
        public readonly int $warning_count,
        public readonly int $failed_count,
        public readonly int $errored_count,
        public readonly BucketStatus $status,
        public readonly int $incident_count,
        public readonly int $failed_seconds = 0,
    ) {}
}
