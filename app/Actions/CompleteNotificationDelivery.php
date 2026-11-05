<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\Notifications\DeliveryResult;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Enums\NotificationCategory;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\SystemIssue;
use Heptapod\SystemHealth\MailTransportRecord;
use Heptapod\SystemHealth\SystemIssueMailer;
use Illuminate\Support\Facades\DB;

final class CompleteNotificationDelivery
{
    public function __invoke(int $deliveryId, string $claimToken, DeliveryResult $result): bool
    {
        return DB::transaction(function () use ($deliveryId, $claimToken, $result): bool {
            $eventId = DB::table('deliveries')->where('id', $deliveryId)->value('event_id');
            if ($eventId === null) {
                return false;
            }
            if (! is_int($eventId)) {
                throw new \LogicException('A delivery event ID must be an integer.');
            }
            $event = Event::query()->find($eventId);
            if (! $event instanceof Event) {
                return false;
            }
            // A System opening locks its issue before the delivery, as the evaluator's resolution writes the issue row:
            // whichever commits second sees the other (an accepted opening, or a resolved issue) and queues the recovery.
            $issue = null;
            if ($event->system_issue_id !== null && $event->type === 'system.issue_opened') {
                $issueQuery = SystemIssue::query()->whereKey($event->system_issue_id);
                NotificationLocks::forUpdate($issueQuery->getQuery());
                $issue = $issueQuery->firstOrFail();
            }
            $sourceEventIds = [];
            if ($event->type === 'notification.digest') {
                $sourceIds = $event->payload['delivery_ids'] ?? null;
                if (! is_array($sourceIds) || array_any($sourceIds, static fn (mixed $id): bool => ! is_int($id))) {
                    throw new \LogicException('Digest source IDs are missing.');
                }
                /** @var list<int> $sourceEventIds */
                $sourceEventIds = DB::table('deliveries')->whereIn('id', $sourceIds)->pluck('event_id')->all();
            }
            $eventIds = [$eventId, ...$sourceEventIds];
            /** @var list<int> $openingIncidentIds */
            $openingIncidentIds = DB::table('events')->whereIn('id', $eventIds)->where('type', 'incident.opened')->whereNotNull('incident_id')->pluck('incident_id')->all();
            /** @var list<int> $catchUpIncidentIds */
            $catchUpIncidentIds = DB::table('maintenance_catch_up_incidents')->join('events', 'events.id', '=', 'maintenance_catch_up_incidents.event_id')
                ->whereIn('events.id', $eventIds)->where('events.type', 'maintenance.catch_up')->pluck('maintenance_catch_up_incidents.incident_id')->all();
            $openingIncidentIds = array_values(array_unique([...$openingIncidentIds, ...$catchUpIncidentIds]));
            $siteIds = DB::table('events')->whereIn('id', $eventIds)->whereNotNull('site_id')->pluck('site_id')
                ->merge(DB::table('incidents')->whereIn('id', $openingIncidentIds)->pluck('site_id'))->unique()->sort()->values();
            // NotificationLocks, top-down: sites, the incidents whose opening this send accepts (written below), the channel
            // (its last outcome is written below), then the delivery.
            NotificationLocks::sites($siteIds);
            NotificationLocks::incidents($openingIncidentIds);
            NotificationLocks::channels([NotificationLocks::channelOf($deliveryId)]);
            $query = Delivery::query()->whereKey($deliveryId)->where('state', DeliveryState::Sending->value)->where('claim_token', $claimToken);
            NotificationLocks::forUpdate($query->getQuery());
            $delivery = $query->first();
            if ($delivery === null) {
                return false;
            }
            $at = CarbonImmutable::now('UTC');
            $sent = $result->status === DeliveryState::Sent;
            $destination = $delivery->destination()->getQuery()->firstOrFail();
            $rejected = $destination->channel === 'mail' && $result->recipientRejected();
            $delivery->update(['state' => $result->status, 'claim_token' => null, 'lease_until' => null,
                'suppressed' => $rejected, 'suppression_reason' => $rejected ? 'smtp_recipient_rejected' : null,
                'suppression_evaluated_at' => $at,
                'sent_at' => $sent ? $at : null, 'last_reason' => $result->reason, 'last_diagnosis' => $result->diagnosis,
                'provider_message_id' => $result->providerMessageId,
                // System retries keep the fixed cadence system mail always had (design §3.4); alerts back off.
                'next_attempt_at' => $at->addSeconds($delivery->category === NotificationCategory::System
                    ? SystemIssueMailer::RecheckSeconds : min(3600, 60 * 2 ** min(6, $delivery->attempts - 1))),
            ]);
            // A one-off client address had its one attempt: whatever the outcome, it is terminal in this commit and its channel
            // is archived. The channel is already locked above, before this delivery (NotificationLocks).
            if (ClaimNotificationDelivery::isOneOffAttempt($delivery, $event)) {
                if (! in_array($result->status, [DeliveryState::Sent, DeliveryState::Logged], true)) {
                    $delivery->update(['suppressed' => true, 'suppression_reason' => ClaimNotificationDelivery::OneOffAttempted]);
                }
                app(ArchiveNotificationChannel::class)($destination, ArchiveNotificationChannel::Archived, null);
            }
            if ($destination->channel === 'mail') {
                $destination->update(['last_outcome' => $sent ? 'accepted' : ($result->status === DeliveryState::Logged ? 'logged' : ($rejected ? 'rejected' : 'failed')),
                    'last_outcome_diagnosis' => $result->diagnosis, 'last_outcome_at' => $at]);
                if ($result->reason === 'notifications.transport_failed' && ! $rejected) {
                    app(MailTransportRecord::class)->recordFailure($at, $result->diagnosis ?? throw new \LogicException('A transport failure must carry a diagnosis.'));
                }
            }
            if (in_array($result->status, [DeliveryState::Sent, DeliveryState::Logged], true) && $delivery->event()->getQuery()->firstOrFail()->type === 'notification.digest') {
                Delivery::query()->where('digest_event_id', $delivery->event_id)->where('suppressed', false)
                    ->update(['state' => $result->status, 'sent_at' => $sent ? $at : null]);
            }
            if ($sent && $issue instanceof SystemIssue && $issue->resolved_at !== null) {
                // The issue resolved while this opening was being sent: this recipient still needs its recovery.
                app(SystemIssueMailer::class)->queueRecoveryFor($issue, $delivery->destination_id);
            }
            if ($sent && $openingIncidentIds !== []) {
                DB::table('incidents')->whereIn('id', $openingIncidentIds)
                    ->whereRaw("NOT (evidence @> '{\"notification_opening_accepted\":true}'::jsonb)")
                    ->update(['evidence' => DB::raw("jsonb_set(evidence, '{notification_opening_accepted}', 'true'::jsonb, true)")]);
            }
            $delivery->announceChanges();

            return true;
        });
    }
}
