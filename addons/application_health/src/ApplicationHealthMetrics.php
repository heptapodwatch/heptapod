<?php

declare(strict_types=1);

namespace Heptapod\Addons\ApplicationHealth;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ApplicationHealthMetrics extends Data
{
    public function __construct(
        public readonly int $succeeded,
        public readonly int $warning,
        public readonly int $failed,
        public readonly ?int $finished_at,
        public readonly ?float $response_time_in_seconds,
    ) {}
}
