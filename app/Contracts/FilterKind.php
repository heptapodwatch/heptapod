<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum FilterKind: string
{
    case Select = 'select';
    case Combobox = 'combobox';
    case Segmented = 'segmented';
    case Chip = 'chip';
    case Boolean = 'boolean';
    case Search = 'search';
    case Date = 'date';
}
