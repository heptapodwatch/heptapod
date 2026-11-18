<?php

declare(strict_types=1);

namespace Heptapod\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum ImportSection: string
{
    case Sites = 'sites';
    case Maintenance = 'maintenance';
    case Cron = 'cron';
    case Channels = 'channels';

    public function label(): string
    {
        return __('import.section.'.$this->value);
    }
}
