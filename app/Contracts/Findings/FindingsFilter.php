<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Findings;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class FindingsFilter extends Data
{
    public function __construct(public readonly ?FindingStatus $status = null, public readonly bool $only_new_since = false) {}
}
