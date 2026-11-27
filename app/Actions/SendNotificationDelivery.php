<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\Notifications\ChannelRegistry;
use Heptapod\Contracts\Notifications\DeliveryResult;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Events\Notifications\DeliveryDecision;
use Heptapod\Events\Notifications\DeliveryDecisionKind;
use Heptapod\Events\Notifications\DeliveryPlan;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Models\Delivery;
use Illuminate\Support\Facades\DB;

final readonly class SendNotificationDelivery
{
    public function __construct(private ClaimNotificationDelivery $claim, private CompleteNotificationDelivery $complete, private ChannelRegistry $channels, private DeliveryPlan $plan) {}

    /** The transport outcome, or null when the claim did not hand out the delivery or the plan at the send stopped it. */
    public function __invoke(int $deliveryId): ?DeliveryResult
    {
        $claimed = ($this->claim)($deliveryId);
        if (! $claimed instanceof Delivery) {
            return null;
        }
        $token = $claimed->claim_token ?? throw new \LogicException('Delivery claim has no token.');
        $send = $this->decideAgain($claimed->id, $token);
        if ($send === null) {
            return null;
        }
        $destination = $send->destination ?? throw new \LogicException('A Send decision names its channel.');
        // Never hold a transaction during provider I/O. A crash leaves a reclaimable lease.
        // At-least-once duplicate window: provider acceptance before the fenced ack
        // commits (including an expired lease). Providers without idempotency can resend.
        $result = $this->channels->send($destination->channel, $send->recipient ?? throw new \LogicException('A Send decision names its recipient.'),
            $send->notification ?? throw new \LogicException('A Send decision carries its payload.'));
        ($this->complete)($claimed->id, $token, $result);

        return $result;
    }

    /**
     * DeliveryPlan once more, right before transmitting, under the claim's locks and on this worker's own claim: the
     * payload goes out only as the plan renders it now, to the channel exactly as the plan read it. Any other decision
     * is written instead (a digest whose items changed goes back to the claim) and nothing is sent.
     */
    private function decideAgain(int $deliveryId, string $token): ?DeliveryDecision
    {
        return DB::transaction(function () use ($deliveryId, $token): ?DeliveryDecision {
            if (! ClaimNotificationDelivery::lockSubject($deliveryId, skipBusyChannel: false)) {
                return null;
            }
            $query = Delivery::query()->whereKey($deliveryId)->where('state', DeliveryState::Sending->value)->where('claim_token', $token);
            NotificationLocks::forUpdate($query->getQuery());
            $delivery = $query->first();
            if (! $delivery instanceof Delivery) {
                return null;
            }
            $destination = $delivery->destination()->getQuery()->firstOrFail();
            $event = $delivery->event()->getQuery()->firstOrFail();
            $at = CarbonImmutable::now('UTC');
            $decision = $this->plan->decide($delivery, $destination, $event, $at, claimed: true);
            if ($decision->kind !== DeliveryDecisionKind::Send) {
                $this->plan->apply($delivery, $event, $decision, $at, claimed: true);

                return null;
            }
            $delivery->save();

            return $decision;
        });
    }
}
