<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Schema;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum FieldType: string
{
    case String = 'string';
    case Integer = 'integer';
    case Number = 'numeric';
    case Boolean = 'boolean';
    case Object = 'object';
    case List = 'list';
    case Secret = 'secret';
}
