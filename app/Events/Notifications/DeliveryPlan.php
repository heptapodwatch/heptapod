<?php

declare(strict_types=1);

namespace Heptapod\Events\Notifications;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\Notifications\ChannelRecipient;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Enums\DeliveryDecisionKind;
use Heptapod\Enums\RecipientKind;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\NotificationDestination;
use Heptapod\Servers\ServerIssueMailer;
use Heptapod\SystemHealth\SystemIssueMailer;

/**
 * The one decision whether, when and what a delivery sends, from the current state of its channel, event, sources,
 * site, check, server and recipient. The claim asks it before handing a delivery out; the send asks it again under the
 * same locks right before transmitting and sends exactly the payload it returns, to exactly the channel snapshot it
 * read. The caller holds the locks of ClaimNotificationDelivery::lockSubject() and the delivery row.
 *
 * An archived channel stops every source; everything else is the rule of the event's source (AlertRule).
 */
final readonly class DeliveryPlan
{
    public function __construct(private SiteAlertRule $sites, private DigestRule $digests, private SystemIssueMailer $systemIssues, private ServerIssueMailer $serverIssues) {}

    /** @param bool $claimed false at the claim; true at the send, where the row is this worker's own Sending claim */
    public function decide(Delivery $delivery, NotificationDestination $destination, Event $event, CarbonImmutable $at, bool $claimed): DeliveryDecision
    {
        if ($destination->removed_at === null && $destination->recipient_kind === RecipientKind::User && $destination->user_id === null) {
            // The recipient CHECK refuses this row; deleting a user always archives its channels first (DeleteProfile).
            throw new \LogicException('Account channel without user must be archived.');
        }
        // Every origin, also manual: an archived channel never sends, including rows created after archiving.
        if ($destination->removed_at !== null) {
            return DeliveryDecision::suppress($destination->removed_reason === 'recipient_deleted' ? 'recipient_deleted' : 'destination_archived');
        }
        $rule = match (true) {
            $event->system_issue_id !== null => $this->systemIssues,
            $event->server_issue_id !== null => $this->serverIssues,
            $event->type === 'notification.digest' => $this->digests,
            default => $this->sites,
        };
        $decision = $rule->decide($delivery, $destination, $event, $at, $claimed);
        if ($decision->kind !== DeliveryDecisionKind::Send || ! $claimed) {
            return $decision;
        }
        // A manual send goes where it was approved: only the snapshot routes it, never the current channel config.
        $recipient = $delivery->origin === 'manual'
            ? ChannelRecipient::fromSnapshot($destination->destinationConfig(), $delivery->recipient_snapshot ?? throw new \LogicException('A manual delivery requires its recipient snapshot.'))
            : ChannelRecipient::forChannel($destination->destinationConfig());

        return $decision->withPayload($destination, $recipient, $rule->notification($delivery, $event, $destination));
    }

    /** Writes a decision other than Send on the locked row. At the send, a row that did not go out gives its attempt back. */
    public function apply(Delivery $delivery, Event $event, DeliveryDecision $decision, CarbonImmutable $at, bool $claimed): void
    {
        $returned = $claimed ? ['attempts' => max(0, $delivery->attempts - 1)] : [];
        $released = ['claim_token' => null, 'lease_until' => null];
        match ($decision->kind) {
            DeliveryDecisionKind::Send => throw new \LogicException('A Send decision starts sending; it is not applied.'),
            DeliveryDecisionKind::Suppress => $delivery->update([...$released, 'suppressed' => true, 'suppression_reason' => $decision->reason,
                // A suppressed row is final: one left Sending (this send, or a reclaimed lease) is Pending again, without a lease.
                'suppression_evaluated_at' => $at, ...($delivery->state === DeliveryState::Sending ? ['state' => DeliveryState::Pending] : []),
                ...($decision->clearReason ? ['last_reason' => null] : [])]),
            DeliveryDecisionKind::Hold => $delivery->update($decision->keepState && ! $claimed && $delivery->state !== DeliveryState::Sending
                ? ['next_attempt_at' => $decision->until ?? $delivery->next_attempt_at]
                : [...$released, ...$returned, 'state' => DeliveryState::Pending, ...($decision->until instanceof CarbonImmutable ? ['next_attempt_at' => $decision->until] : []),
                    ...($decision->setReason ? ['last_reason' => $decision->reason] : [])]),
            DeliveryDecisionKind::Rebuild => $delivery->update([...$released, ...$returned, 'state' => DeliveryState::Pending, 'next_attempt_at' => $at]),
            DeliveryDecisionKind::Moved => $returned === [] ? null : $delivery->update($returned),
        };
        // A suppressed digest takes the items still waiting in it along (an archived or paused channel, nothing left).
        if ($decision->kind === DeliveryDecisionKind::Suppress && $event->type === 'notification.digest') {
            $sources = Delivery::query()->where('state', DeliveryState::Pending->value)->where('suppressed', false);
            $sources->getQuery()->where('digest_event_id', $event->id);
            $sources->update(['suppressed' => true, 'suppression_reason' => $decision->reason, 'suppression_evaluated_at' => $at]);
        }
        $delivery->announceChanges();
    }
}
