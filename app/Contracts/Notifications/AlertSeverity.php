<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Notifications;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum AlertSeverity: string
{
    case Down = 'down';
    case Recovered = 'recovered';
    case Warning = 'warning';
    case Error = 'error';
    case Info = 'info';
}
