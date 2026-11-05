<?php

declare(strict_types=1);

namespace Heptapod\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum ClientAvatarSource: string
{
    case Uploaded = 'uploaded';
    case ChosenSite = 'chosen-site';
    case Automatic = 'automatic';
    case Initials = 'initials';
    case GenericInitials = 'generic-initials';
}
