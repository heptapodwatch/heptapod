<?php

declare(strict_types=1);

namespace Heptapod\Addons\Domain;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class DomainMetrics extends Data
{
    public function __construct(
        public readonly ?string $expires_at,
        public readonly ?string $not_supported_reason,
    ) {}
}
