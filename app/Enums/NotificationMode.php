<?php

declare(strict_types=1);

namespace Heptapod\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One grid cell: how a channel hears about one category. */
#[TypeScript]
enum NotificationMode: string
{
    case Immediate = 'immediate';
    case Digest = 'digest';
    case Off = 'off';
}
