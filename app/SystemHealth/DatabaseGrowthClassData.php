<?php

declare(strict_types=1);

namespace Heptapod\SystemHealth;

use Heptapod\Enums\DatabaseDataClass;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class DatabaseGrowthClassData extends Data
{
    public function __construct(
        public readonly DatabaseDataClass $dataClass,
        public readonly int $bytes,
        public readonly int $in365DaysBytes,
        public readonly ?int $retentionDays,
        /** Whether maintenance deletes rows past the retention today; an unenforced setting does not stop growth. */
        public readonly bool $retentionEnforced,
        public readonly ?string $retentionVariable,
    ) {}
}
