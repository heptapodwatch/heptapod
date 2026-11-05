<?php

declare(strict_types=1);

namespace Heptapod\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum SiteIconBacking: string
{
    case None = 'none';
    case LightBackingInDark = 'light-backing-in-dark';
    case DarkBackingInLight = 'dark-backing-in-light';
}
