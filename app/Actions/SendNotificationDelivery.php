<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Contracts\Notifications\AlertNotification;
use Heptapod\Contracts\Notifications\ChannelRecipient;
use Heptapod\Contracts\Notifications\ChannelRegistry;
use Heptapod\Contracts\Notifications\DeliveryResult;
use Heptapod\Events\Notifications\NotificationMessage;
use Heptapod\Models\Delivery;
use Heptapod\SystemHealth\SystemIssueMailer;

final readonly class SendNotificationDelivery
{
    public function __construct(private ClaimNotificationDelivery $claim, private CompleteNotificationDelivery $complete, private ChannelRegistry $channels, private NotificationMessage $messages, private SystemIssueMailer $systemIssues, private SendClientMessage $clientMessages) {}

    /** The transport outcome, or null when the claim did not hand out the delivery. */
    public function __invoke(int $deliveryId): ?DeliveryResult
    {
        $delivery = ($this->claim)($deliveryId);
        if (! $delivery instanceof Delivery) {
            return null;
        }
        $destination = $delivery->destination()->getQuery()->firstOrFail();
        $event = $delivery->event()->getQuery()->firstOrFail();
        // A manual send goes where it was approved: only the snapshot routes it, never the current channel config.
        $recipient = $delivery->origin === 'manual'
            ? ChannelRecipient::fromSnapshot($destination->destinationConfig(), $delivery->recipient_snapshot ?? throw new \LogicException('A manual delivery requires its recipient snapshot.'))
            : ChannelRecipient::forChannel($destination->destinationConfig());
        $notification = match (true) {
            $event->system_issue_id !== null => $this->systemIssues->notification($event, $destination),
            str_starts_with($event->type, 'client_message.') => $this->clientMessages->notification($event),
            default => new AlertNotification($this->messages->build($event, $destination, $delivery->category), $delivery->event_id.':'.$destination->id),
        };
        // Never hold a transaction during provider I/O. A crash leaves a reclaimable lease.
        // At-least-once duplicate window: provider acceptance before the fenced ack
        // commits (including an expired lease). Providers without idempotency can resend.
        $result = $this->channels->send($destination->channel, $recipient, $notification);
        ($this->complete)($delivery->id, $delivery->claim_token ?? throw new \LogicException('Delivery claim has no token.'), $result);

        return $result;
    }
}
