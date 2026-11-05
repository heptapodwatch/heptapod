<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\Notifications\ChannelRecipient;
use Heptapod\Contracts\Notifications\DeliveryResult;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Models\Delivery;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\SystemIssue;
use Heptapod\SystemHealth\MailTransportRecord;
use Heptapod\SystemHealth\SystemIssueMailer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fenced completion of one transport probe (design §3.4). An accepted submission ends the issue episode: the delivery,
 * the resolution, the release of the other probe rows and the remaining recoveries commit together.
 *
 * The claim token fences completions, not sends: if the provider accepted the mail and the process stops before this
 * commit, the lease expires, the issue is still open and the next pass probes again. That recipient then gets the
 * recovery twice. This at-least-once window is accepted (design §3.4), as for SendNotificationDelivery.
 */
final readonly class CompleteSystemProbe
{
    public function __construct(private SystemIssueMailer $mailer, private MailTransportRecord $transport) {}

    /** @return 'accepted'|'rejected'|'failed'|'stale' */
    public function __invoke(int $deliveryId, string $claimToken, DeliveryResult $result): string
    {
        return DB::transaction(function () use ($deliveryId, $claimToken, $result): string {
            $at = CarbonImmutable::now('UTC');
            $issueId = DB::table('deliveries')->join('events', 'events.id', '=', 'deliveries.event_id')->where('deliveries.id', $deliveryId)->value('events.system_issue_id');
            if (! is_int($issueId)) {
                throw new \LogicException('A probe delivery must belong to a system issue.');
            }
            $issueQuery = SystemIssue::query()->whereKey($issueId);
            NotificationLocks::forUpdate($issueQuery->getQuery());
            $issue = $issueQuery->firstOrFail();
            // NotificationLocks: issue, then channels, then deliveries. An accepted probe also releases the other probe rows,
            // suppresses the opening's queued rows and inserts recoveries for every recipient (SystemIssueMailer::queueRecovery),
            // so all of those channels and rows are locked here first, in ascending id.
            $this->mailer->lockRecovery($issueId, $deliveryId);
            $query = Delivery::query()->whereKey($deliveryId)->where('state', DeliveryState::Sending->value)->where('claim_token', $claimToken);
            NotificationLocks::forUpdate($query->getQuery());
            $delivery = $query->first();
            if (! $delivery instanceof Delivery) {
                return 'stale';
            }
            $channel = NotificationDestination::query()->findOrFail($delivery->destination_id);
            if ($result->status === DeliveryState::Sent) {
                $delivery->update(['state' => DeliveryState::Sent, 'sent_at' => $at, 'last_reason' => null, 'last_diagnosis' => null, 'claim_token' => null, 'lease_until' => null,
                    'provider_message_id' => $result->providerMessageId]);
                $channel->update(['last_outcome' => 'accepted', 'last_outcome_diagnosis' => null, 'last_outcome_at' => $at]);
                DB::table('system_issues')->where('id', $issue->id)->whereNull('resolved_at')->update(['resolved_at' => $at]);
                Delivery::query()->where('event_id', $delivery->event_id)->where('last_reason', ClaimNotificationDelivery::SystemProbeReason)->where('suppressed', false)
                    ->whereKeyNot($delivery->id)->update(['state' => DeliveryState::Pending, 'last_reason' => null, 'next_attempt_at' => $at, 'claim_token' => null, 'lease_until' => null]);
                $this->mailer->queueRecovery($issue->refresh());
                Log::info('System issue resolved.', ['kind' => $issue->kind->value, 'issue_id' => $issue->id, 'by' => 'accepted_recovery_submission']);

                return 'accepted';
            }
            if ($result->recipientRejected()) {
                // Stated change (design §3.4): a rejected recipient ends this probe round, the run tries the next row.
                // The rejected address is kept so SystemTransportProbe re-arms the round once it changes or cools down.
                $address = ChannelRecipient::forChannel($channel->destinationConfig())->routeNotificationForMail();
                $delivery->update(['state' => DeliveryState::Failed, 'suppressed' => true, 'suppression_reason' => 'smtp_recipient_rejected', 'suppression_evaluated_at' => $at,
                    'last_reason' => $result->reason, 'last_diagnosis' => $result->diagnosis, 'claim_token' => null, 'lease_until' => null,
                    'recipient_snapshot' => $address === null ? null : ['address' => $address, 'name' => $channel->label, 'locale' => $channel->effectiveLocale()]]);
                $channel->update(['last_outcome' => 'rejected', 'last_outcome_diagnosis' => $result->diagnosis, 'last_outcome_at' => $at]);

                return 'rejected';
            }
            // Retry on the next evaluation pass, never through delivery backoff; a log-only mailer also lands here.
            $delivery->update(['state' => DeliveryState::Pending, 'last_reason' => ClaimNotificationDelivery::SystemProbeReason, 'last_diagnosis' => $result->diagnosis,
                'claim_token' => null, 'lease_until' => null]);
            $channel->update(['last_outcome' => $result->status === DeliveryState::Logged ? 'logged' : 'failed', 'last_outcome_diagnosis' => $result->diagnosis, 'last_outcome_at' => $at]);
            if ($result->reason === 'notifications.transport_failed') {
                $this->transport->recordFailure($at, $result->diagnosis ?? throw new \LogicException('A transport failure must carry a diagnosis.'));
            }

            return 'failed';
        });
    }
}
