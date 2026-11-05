<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum ExecutionMode: string
{
    case Poll = 'poll';
    case Push = 'push';
    case Derived = 'derived';
}
