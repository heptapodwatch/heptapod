<?php

declare(strict_types=1);

namespace Heptapod\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Who a notification channel belongs to. Not the Owner role: a recipient is the team, one user or one client. */
#[TypeScript]
enum RecipientKind: string
{
    case Team = 'team';
    case User = 'user';
    case Client = 'client';
}
