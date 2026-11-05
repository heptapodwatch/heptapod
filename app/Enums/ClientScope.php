<?php

declare(strict_types=1);

namespace Heptapod\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum ClientScope: string
{
    case All = 'all';
    case Restricted = 'restricted';
}
