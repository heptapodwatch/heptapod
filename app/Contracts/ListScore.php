<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One score (0–100) of a check's latest run, named by its translation key. */
#[TypeScript]
final class ListScore extends Data
{
    public function __construct(
        public readonly string $label_key,
        public readonly int $score,
    ) {}
}
