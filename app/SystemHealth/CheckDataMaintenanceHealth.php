<?php

declare(strict_types=1);

namespace Heptapod\SystemHealth;

use Heptapod\Enums\HealthStatus;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class CheckDataMaintenanceHealth extends Data
{
    public function __construct(
        public readonly HealthStatus $status,
        public readonly ?string $lastSuccessAt,
        public readonly ?float $lastSuccessSeconds,
        public readonly ?string $lastFailureAt,
        public readonly int $consecutiveFailures,
    ) {}
}
