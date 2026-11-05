<?php

declare(strict_types=1);

namespace Heptapod\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** What happened to one delivery, as the incident history and the client message history show it. */
#[TypeScript]
enum DeliveryOutcome: string
{
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Failed = 'failed';
    case Logged = 'logged';
    case Suppressed = 'suppressed';
    case Pending = 'pending';
    case Sending = 'sending';
}
