<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class SuppressionDecision extends Data
{
    public function __construct(
        public readonly bool $suppressed,
        public readonly ?string $reason,
        public readonly CarbonImmutable $evaluatedAt,
        /** Explicit outbox overrides can have an unknown source start. */
        public readonly ?CarbonImmutable $since = null,
    ) {
        if (! $suppressed && $since instanceof CarbonImmutable) {
            throw new \InvalidArgumentException('An unsuppressed event cannot have a suppression start.');
        }
        if ($suppressed !== ($reason !== null)) {
            throw new \InvalidArgumentException('A suppressed event must carry a reason.');
        }
    }
}
