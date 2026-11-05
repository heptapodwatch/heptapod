<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum RunPhase: string
{
    case Scheduled = 'scheduled';
    case Queued = 'queued';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';
    case Late = 'late';
    case Skipped = 'skipped';
    case Cancelled = 'cancelled';
}
