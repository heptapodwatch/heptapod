<?php

declare(strict_types=1);

namespace Heptapod\Addons\ApplicationHealth;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ApplicationHealthEvidence extends Data
{
    /** @param list<ApplicationHealthComponent> $components */
    public function __construct(
        public readonly ?string $reason,
        public readonly ?int $http_status,
        public readonly ?string $redirect_host,
        public readonly array $components,
        public readonly ?string $failure_kind = null,
    ) {}
}
