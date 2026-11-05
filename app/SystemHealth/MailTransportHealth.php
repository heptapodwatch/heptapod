<?php

declare(strict_types=1);

namespace Heptapod\SystemHealth;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class MailTransportHealth extends Data
{
    public function __construct(
        public readonly ?string $lastSuccessAt,
        public readonly int $consecutiveFailures,
        public readonly ?string $failingSince,
        public readonly ?string $lastFailureAt,
        public readonly ?string $lastFailureDiagnosis,
    ) {}
}
