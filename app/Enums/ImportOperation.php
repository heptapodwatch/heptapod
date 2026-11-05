<?php

declare(strict_types=1);

namespace Heptapod\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** What an import does, or would do on a dry run, with one source monitor. */
#[TypeScript]
enum ImportOperation: string
{
    case Create = 'create';
    case Update = 'update';
    case Partial = 'partial';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
