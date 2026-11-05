<?php

namespace Heptapod\Contracts;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum RunResult: string
{
    case Succeeded = 'succeeded';
    case Warning = 'warning';
    case Failed = 'failed';
    case Errored = 'errored-or-timed-out';

    public function ohDearValue(): string
    {
        return $this === self::Errored ? self::Failed->value : $this->value;
    }
}
