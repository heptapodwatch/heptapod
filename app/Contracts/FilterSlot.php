<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum FilterSlot: string
{
    case Bar = 'bar';
    case HistoryRange = 'history-range';
    case History = 'history';
}
