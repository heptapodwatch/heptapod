<?php

declare(strict_types=1);

namespace Heptapod\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum ResponseTimeRange: string
{
    case Hours24 = '24h';
    case Days7 = '7d';
    case Days30 = '30d';
    case Days90 = '90d';
}
