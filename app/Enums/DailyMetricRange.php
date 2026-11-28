<?php

declare(strict_types=1);

namespace Heptapod\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum DailyMetricRange: string
{
    case Days30 = '30d';
    case Days90 = '90d';
    case Year1 = '1y';
    case All = 'all';
}
