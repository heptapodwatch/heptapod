<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Findings;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class FindingsPosition extends Data
{
    public function __construct(public readonly FindingsSourceGroup $group, public readonly int $position)
    {
        if ($position < 1) {
            throw new \InvalidArgumentException('Findings positions must be positive.');
        }
    }
}
