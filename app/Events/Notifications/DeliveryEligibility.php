<?php

declare(strict_types=1);

namespace Heptapod\Events\Notifications;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Enums\NotificationCategory;
use Heptapod\Enums\RecipientKind;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\NotificationDestination;
use Heptapod\Servers\ServerIssueMailer;
use Heptapod\SystemHealth\SystemIssueMailer;

/**
 * May this channel, as it is now, receive this event? The one rule the claim, digest assembly and the send itself apply,
 * so they cannot disagree: the send re-checks it under the channel's row lock and uses exactly the configuration it
 * checked. Sequencing (confirmation, episodes, quiet hours, a recovery waiting for its opening) stays with the claim.
 */
final readonly class DeliveryEligibility
{
    public function __construct(private ServerIssueMailer $servers) {}

    public function refusal(Event $event, NotificationDestination $channel, Delivery $delivery, CarbonImmutable $at): ?DeliveryRefusal
    {
        if ($channel->removed_at !== null) {
            return new DeliveryRefusal($channel->removed_reason === 'recipient_deleted' ? 'recipient_deleted' : 'destination_archived');
        }
        if ($event->system_issue_id !== null) {
            $reason = SystemIssueMailer::ineligibility($channel);

            return $reason === null ? null : new DeliveryRefusal($reason);
        }
        if ($event->server_issue_id !== null) {
            return $this->servers->refusal($event, $channel, $at);
        }
        $site = $event->site;
        $recipient = $channel->user;
        if ($site !== null) {
            if ($recipient !== null && $recipient->disabled_at === null && (! $recipient->hasAnyRole(['Owner', 'Admin', 'Member']) || ! $recipient->canAccessClient($site->client_id))) {
                return new DeliveryRefusal('personal_site_access_revoked');
            }
            // Client channels follow site.client_id: a moved site no longer reaches its old client.
            if ($channel->recipient_kind === RecipientKind::Client && $channel->client_id !== $site->client_id) {
                return new DeliveryRefusal('client_site_moved');
            }
            if ($channel->recipient_kind === RecipientKind::Team && $channel->scope_client_id !== null && $channel->scope_client_id !== $site->client_id) {
                return new DeliveryRefusal('channel_client_scope');
            }
        }
        // System mail on account channels goes to the account address regardless of pause and verification (design 3.4).
        $accountSystem = self::accountSystem($channel, $delivery);
        if (! $channel->enabled && $delivery->origin !== 'manual' && ! $accountSystem) {
            return new DeliveryRefusal('destination_disabled');
        }
        if (! $accountSystem && $channel->user_id !== null && $recipient?->disabled_at !== null) {
            return new DeliveryRefusal('personal_user_disabled');
        }

        return null;
    }

    public static function accountSystem(NotificationDestination $channel, Delivery $delivery): bool
    {
        return $channel->account_mail && $delivery->category === NotificationCategory::System;
    }

    /** Records the refusal on a delivery whose row the caller locked: suppressed, or pending until the hold ends. */
    public static function refuse(Delivery $delivery, DeliveryRefusal $refusal, CarbonImmutable $at): void
    {
        $delivery->update(['claim_token' => null, 'lease_until' => null, ...($refusal->retryAt instanceof CarbonImmutable
            ? ['state' => DeliveryState::Pending, 'next_attempt_at' => $refusal->retryAt, 'last_reason' => $refusal->reason]
            : ['suppressed' => true, 'suppression_reason' => $refusal->reason, 'suppression_evaluated_at' => $at])]);
        $delivery->announceChanges();
    }
}
