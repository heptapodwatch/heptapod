<?php

declare(strict_types=1);

namespace Heptapod\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The outcome of one notification group in an incident timeline: the deliveries' shared outcome, Mixed when they
 * differ, Unrecorded when the group has no delivery (a reminder that was never sent).
 */
#[TypeScript]
enum DeliveryGroupOutcome: string
{
    case Accepted = 'accepted';
    case Logged = 'logged';
    case Failed = 'failed';
    case Suppressed = 'suppressed';
    case Pending = 'pending';
    case Mixed = 'mixed';
    case Unrecorded = 'unrecorded';

    /**
     * The value as a quoted SQL literal for raw SQL: fixed per case, never user input.
     *
     * @return literal-string
     */
    public function sql(): string
    {
        return match ($this) {
            self::Accepted => "'accepted'", self::Logged => "'logged'", self::Failed => "'failed'", self::Suppressed => "'suppressed'",
            self::Pending => "'pending'", self::Mixed => "'mixed'", self::Unrecorded => "'unrecorded'",
        };
    }
}
