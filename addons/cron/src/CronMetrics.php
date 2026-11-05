<?php

declare(strict_types=1);

namespace Heptapod\Addons\Cron;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class CronMetrics extends Data
{
    public function __construct(
        public readonly int $succeeded,
        public readonly int $warning,
        public readonly int $failed,
        public readonly int $pending,
    ) {}
}
