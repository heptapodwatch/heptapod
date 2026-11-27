<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\Notifications\AlertNotification;
use Heptapod\Contracts\Notifications\ChannelRecipient;
use Heptapod\Contracts\Notifications\ChannelRegistry;
use Heptapod\Contracts\Notifications\DeliveryResult;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Events\Notifications\DeliveryEligibility;
use Heptapod\Events\Notifications\DeliveryRefusal;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Events\Notifications\NotificationMessage;
use Heptapod\Models\Delivery;
use Heptapod\Models\NotificationDestination;
use Heptapod\Servers\ServerIssueMailer;
use Heptapod\SystemHealth\SystemIssueMailer;
use Illuminate\Support\Facades\DB;

final readonly class SendNotificationDelivery
{
    public function __construct(private ClaimNotificationDelivery $claim, private CompleteNotificationDelivery $complete, private ChannelRegistry $channels, private NotificationMessage $messages, private SystemIssueMailer $systemIssues, private ServerIssueMailer $serverIssues, private SendClientMessage $clientMessages, private DeliveryEligibility $eligibility) {}

    /** The transport outcome, or null when the claim did not hand out the delivery or the channel may no longer receive it. */
    public function __invoke(int $deliveryId): ?DeliveryResult
    {
        $claimed = ($this->claim)($deliveryId);
        if (! $claimed instanceof Delivery) {
            return null;
        }
        $checked = $this->recheck($claimed);
        if ($checked === null) {
            return null;
        }
        [$delivery, $destination] = $checked;
        $event = $delivery->event()->getQuery()->firstOrFail();
        // A manual send goes where it was approved: only the snapshot routes it, never the current channel config.
        $recipient = $delivery->origin === 'manual'
            ? ChannelRecipient::fromSnapshot($destination->destinationConfig(), $delivery->recipient_snapshot ?? throw new \LogicException('A manual delivery requires its recipient snapshot.'))
            : ChannelRecipient::forChannel($destination->destinationConfig());
        $notification = match (true) {
            $event->system_issue_id !== null => $this->systemIssues->notification($event, $destination),
            $event->server_issue_id !== null => $this->serverIssues->notification($event, $destination),
            str_starts_with($event->type, 'client_message.') => $this->clientMessages->notification($event),
            default => new AlertNotification($this->messages->build($event, $destination, $delivery->category, foldedRecovery: $delivery->folded_with_recovery), $delivery->event_id.':'.$destination->id),
        };
        // Never hold a transaction during provider I/O. A crash leaves a reclaimable lease.
        // At-least-once duplicate window: provider acceptance before the fenced ack
        // commits (including an expired lease). Providers without idempotency can resend.
        $result = $this->channels->send($destination->channel, $recipient, $notification);
        ($this->complete)($delivery->id, $delivery->claim_token ?? throw new \LogicException('Delivery claim has no token.'), $result);

        return $result;
    }

    /**
     * Deliveries re-check eligibility at send time: under the channel's row lock, the channel as it is now must still pass
     * DeliveryEligibility (for a digest, with every item it carries), and the send uses exactly the configuration checked
     * here, never one saved after it. A refused delivery is suppressed or held; a digest whose items changed goes back to
     * the claim, which rebuilds it from the items that still pass.
     *
     * @return array{Delivery, NotificationDestination}|null
     */
    private function recheck(Delivery $claimed): ?array
    {
        return DB::transaction(function () use ($claimed): ?array {
            // NotificationLocks: the channel, then the delivery (fenced by the claim's token).
            NotificationLocks::channels([$claimed->destination_id]);
            $query = Delivery::query()->whereKey($claimed->id)->where('state', DeliveryState::Sending->value)->where('claim_token', $claimed->claim_token);
            NotificationLocks::forUpdate($query->getQuery());
            $delivery = $query->first();
            if (! $delivery instanceof Delivery) {
                return null;
            }
            $destination = NotificationDestination::query()->findOrFail($delivery->destination_id);
            $event = $delivery->event()->getQuery()->firstOrFail();
            $at = CarbonImmutable::now('UTC');
            $refusal = $this->eligibility->refusal($event, $destination, $delivery, $at);
            if ($refusal instanceof DeliveryRefusal) {
                DeliveryEligibility::refuse($delivery, $refusal, $at);

                return null;
            }
            if ($event->type === 'notification.digest') {
                $sources = Delivery::query()->with('event.site');
                $sources->getQuery()->whereIn('id', is_array($event->payload['delivery_ids'] ?? null) ? $event->payload['delivery_ids'] : []);
                foreach ($sources->get() as $source) {
                    $sourceEvent = $source->event ?? throw new \LogicException('Digest source event is missing.');
                    if ($this->eligibility->refusal($sourceEvent, $destination, $source, $at) instanceof DeliveryRefusal) {
                        $delivery->update(['state' => DeliveryState::Pending, 'next_attempt_at' => $at, 'claim_token' => null, 'lease_until' => null]);
                        $delivery->announceChanges();

                        return null;
                    }
                }
            }

            return [$delivery, $destination];
        });
    }
}
