<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Notifications;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * deliveries.state, matching the deliveries_state_check constraint. A channel reports only Sent, Logged or Failed
 * (DeliveryResult); Pending and Sending belong to the delivery engine's claim cycle.
 */
#[TypeScript]
enum DeliveryState: string
{
    case Pending = 'pending';
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Logged = 'logged';

    /**
     * The value as a quoted SQL literal for raw SQL: fixed per case, never user input.
     *
     * @return literal-string
     */
    public function sql(): string
    {
        return match ($this) {
            self::Pending => "'pending'", self::Sending => "'sending'", self::Sent => "'sent'", self::Failed => "'failed'", self::Logged => "'logged'",
        };
    }
}
