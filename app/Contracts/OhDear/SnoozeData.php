<?php

declare(strict_types=1);

namespace Heptapod\Contracts\OhDear;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class SnoozeData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $starts_at,
        public readonly string $ends_at,
        public readonly string $snoozed_by,
        public readonly ?string $unsnoozed_by,
        public readonly string $source,
        public readonly string $created_at,
    ) {}
}
