<?php

declare(strict_types=1);

namespace Heptapod\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum IncidentCause: string
{
    case Failed = 'failed';
    case Errored = 'errored';
}
