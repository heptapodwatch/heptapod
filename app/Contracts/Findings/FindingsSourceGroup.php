<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Findings;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum FindingsSourceGroup: string
{
    case Rows = 'rows';
    case Mixed = 'mixed';
    case Verification = 'verification';
}
