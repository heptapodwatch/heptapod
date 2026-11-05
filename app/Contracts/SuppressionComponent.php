<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum SuppressionComponent: string
{
    case Cron = 'cron';
    case ApplicationHealth = 'application_health';
}
