<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Findings;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum FindingsUnavailableStatus: string
{
    case EvidenceExpired = 'evidence_expired';
    case Unsupported = 'unsupported';
    case NoDataset = 'no_dataset';
}
