<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Findings;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum FindingsUnavailableReason: string
{
    case NotRetained = 'not_retained';
    case NoObservation = 'no_observation';
    case ComparisonLimit = 'comparison_limit';
    case LegacyMetadataMissing = 'legacy_metadata_missing';
    case Incomparable = 'incomparable';
}
