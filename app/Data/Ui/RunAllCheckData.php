<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Contracts\CheckKey;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class RunAllCheckData extends Data
{
    public function __construct(
        public readonly int $check_id,
        public readonly CheckKey $check_type,
        public readonly string $queue,
        public readonly int $maximum_seconds,
        public readonly bool $available,
    ) {}
}
