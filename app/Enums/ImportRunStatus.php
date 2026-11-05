<?php

declare(strict_types=1);

namespace Heptapod\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Where an import run stands: planned → queued → running → completed | incomplete | failed; incomplete/failed → queued on "Run again". */
#[TypeScript]
enum ImportRunStatus: string
{
    case Planned = 'planned';
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Incomplete = 'incomplete';
    case Failed = 'failed';
}
