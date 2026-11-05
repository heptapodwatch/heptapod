<?php

declare(strict_types=1);

namespace Heptapod\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum HealthStatus: string
{
    case Ok = 'ok';
    case Warn = 'warn';
    case Down = 'down';
    case NotConfigured = 'not-configured';
}
