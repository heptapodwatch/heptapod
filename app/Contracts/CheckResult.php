<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum CheckResult: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Warning = 'warning';
    case Failed = 'failed';
    case Errored = 'errored-or-timed-out';
}
