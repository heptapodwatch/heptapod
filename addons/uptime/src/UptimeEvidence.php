<?php

declare(strict_types=1);

namespace Heptapod\Addons\Uptime;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class UptimeEvidence extends Data
{
    /** @param list<string> $issues */
    public function __construct(
        public readonly ?int $status_code,
        public readonly array $issues,
        public readonly ?string $failure_kind = null,
        public readonly ?int $timeout_seconds = null,
        public readonly ?string $effective_url = null,
        public readonly ?string $expected_url = null,
        public readonly ?string $required_string = null,
        public readonly ?string $absent_string = null,
    ) {}
}
