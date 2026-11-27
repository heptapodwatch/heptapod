<?php

declare(strict_types=1);

namespace Heptapod\Events\Notifications;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\Notifications\ChannelRecipient;
use Heptapod\Enums\DeliveryDecisionKind;
use Heptapod\Models\NotificationDestination;
use Illuminate\Notifications\Notification;

/**
 * What DeliveryPlan decided for one delivery. A hold keeps or replaces last_reason ($reason with $setReason) and moves
 * next_attempt_at unless $until is null; $keepState holds a pending or failed row in its state (a short recheck).
 * At the send, a Send carries the rendered payload and the exact channel snapshot it is addressed to.
 */
final readonly class DeliveryDecision
{
    private function __construct(
        public DeliveryDecisionKind $kind,
        public ?string $reason = null,
        public ?CarbonImmutable $until = null,
        public bool $setReason = false,
        public bool $keepState = false,
        public bool $clearReason = false,
        public ?NotificationDestination $destination = null,
        public ?ChannelRecipient $recipient = null,
        public ?Notification $notification = null,
    ) {}

    public static function send(): self
    {
        return new self(DeliveryDecisionKind::Send);
    }

    public function withPayload(NotificationDestination $destination, ChannelRecipient $recipient, Notification $notification): self
    {
        return new self(DeliveryDecisionKind::Send, destination: $destination, recipient: $recipient, notification: $notification);
    }

    public static function suppress(string $reason, bool $clearReason = false): self
    {
        return new self(DeliveryDecisionKind::Suppress, $reason, clearReason: $clearReason);
    }

    public static function hold(?CarbonImmutable $until, ?string $reason = null, bool $setReason = false, bool $keepState = false): self
    {
        return new self(DeliveryDecisionKind::Hold, $reason, $until, $setReason, $keepState);
    }

    public static function rebuild(): self
    {
        return new self(DeliveryDecisionKind::Rebuild);
    }

    public static function moved(): self
    {
        return new self(DeliveryDecisionKind::Moved);
    }
}
