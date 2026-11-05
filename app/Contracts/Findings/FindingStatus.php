<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Findings;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum FindingStatus: string
{
    case Broken = 'broken';
    case NotVerifiable = 'not_verifiable';
    case Unchecked = 'unchecked';
    /** Collection time, URL or recursion caps describe evidence coverage, not a confirmed target failure. Excluded from broken findings and novelty. */
    case CoverageLimit = 'coverage_limit';
}
