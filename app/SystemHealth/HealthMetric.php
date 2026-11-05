<?php

declare(strict_types=1);

namespace Heptapod\SystemHealth;

use Heptapod\Enums\HealthStatus;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class HealthMetric extends Data
{
    /** @param 'no_sample'|'unlimited'|null $unavailableReason */
    public function __construct(
        public readonly HealthStatus $status,
        public readonly ?int $value,
        public readonly ?string $error = null,
        #[LiteralTypeScriptType("'no_sample' | 'unlimited' | null")]
        public readonly ?string $unavailableReason = null,
    ) {}
}
