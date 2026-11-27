<?php

declare(strict_types=1);

namespace Heptapod\Events\Notifications;

use Carbon\CarbonImmutable;
use Heptapod\Actions\HandleDeferredUptimeOpening;
use Heptapod\Actions\SendClientMessage;
use Heptapod\Contracts\Notifications\AlertNotification;
use Heptapod\Contracts\Notifications\AlertSeverity;
use Heptapod\Contracts\Notifications\ChannelRecipient;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Enums\DeliveryDecisionKind;
use Heptapod\Enums\IncidentCause;
use Heptapod\Enums\IncidentResolutionReason;
use Heptapod\Enums\NotificationCategory;
use Heptapod\Enums\RecipientKind;
use Heptapod\Incidents\UptimeDependencies;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\NotificationSchedule;
use Heptapod\Servers\ServerIssueMailer;
use Heptapod\SystemHealth\SystemIssueMailer;
use Illuminate\Support\Facades\DB;

/**
 * The one decision whether, when and what a delivery sends, from the current state of its channel, event, sources,
 * site, check, server and recipient. The claim asks it before handing a delivery out; the send asks it again under the
 * same locks right before transmitting and sends exactly the payload it returns, to exactly the channel snapshot it
 * read. The caller holds the locks of ClaimNotificationDelivery::lockSubject() and the delivery row.
 */
final readonly class DeliveryPlan
{
    public function __construct(private NotificationTiming $timing, private NotificationEventTypes $types, private HandleDeferredUptimeOpening $deferredOpenings,
        private ServerIssueMailer $serverIssues, private SystemIssueMailer $systemIssues, private NotificationMessage $messages, private SendClientMessage $clientMessages) {}

    /** @param bool $claimed false at the claim; true at the send, where the row is this worker's own Sending claim */
    public function decide(Delivery $delivery, NotificationDestination $destination, Event $event, CarbonImmutable $at, bool $claimed): DeliveryDecision
    {
        $decision = $this->guards($delivery, $destination, $event, $at, $claimed);
        if ($decision->kind !== DeliveryDecisionKind::Send || ! $claimed) {
            return $decision;
        }
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

        return $decision->withPayload($destination, $recipient, $notification);
    }

    /** Writes a decision other than Send on the locked row. At the send, a row that did not go out gives its attempt back. */
    public function apply(Delivery $delivery, Event $event, DeliveryDecision $decision, CarbonImmutable $at, bool $claimed): void
    {
        $returned = $claimed ? ['attempts' => max(0, $delivery->attempts - 1)] : [];
        $released = ['claim_token' => null, 'lease_until' => null];
        match ($decision->kind) {
            DeliveryDecisionKind::Send => throw new \LogicException('A Send decision starts sending; it is not applied.'),
            DeliveryDecisionKind::Suppress => $delivery->update([...$released, 'suppressed' => true, 'suppression_reason' => $decision->reason,
                'suppression_evaluated_at' => $at, ...($claimed ? ['state' => DeliveryState::Pending] : []), ...($decision->clearReason ? ['last_reason' => null] : [])]),
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

    /**
     * Why one digest item may not go to this channel now; null when it may. Digest assembly (EmitNotificationDigests),
     * the digest's claim and its send all filter items with it.
     */
    public function sourceDecision(Delivery $source, Event $event, NotificationDestination $channel, CarbonImmutable $at): ?DeliveryDecision
    {
        if ($source->suppressed) {
            return DeliveryDecision::suppress($source->suppression_reason === 'site_unfollowed' ? 'site_unfollowed' : 'digest_sources_unavailable');
        }
        if ($event->server_issue_id !== null) {
            return $this->serverIssues->refusal($event, $channel, $at);
        }
        if ($event->site === null || $event->check_id === null) {
            return DeliveryDecision::suppress($event->site === null ? 'site_deleted' : 'monitoring_stopped');
        }

        return $this->access($event, $channel, $source);
    }

    private function guards(Delivery $delivery, NotificationDestination $destination, Event $event, CarbonImmutable $at, bool $claimed): DeliveryDecision
    {
        if ($destination->removed_at === null && $destination->recipient_kind === RecipientKind::User && $destination->user_id === null) {
            // The recipient CHECK refuses this row; deleting a user always archives its channels first (DeleteProfile).
            throw new \LogicException('Account channel without user must be archived.');
        }
        // Every origin, also manual: an archived channel never sends, including rows created after archiving.
        if ($destination->removed_at !== null) {
            return DeliveryDecision::suppress($destination->removed_reason === 'recipient_deleted' ? 'recipient_deleted' : 'destination_archived');
        }
        if ($event->system_issue_id !== null) {
            return $this->system($delivery, $destination, $at);
        }
        if ($event->server_issue_id !== null) {
            return $this->serverIssues->refusal($event, $destination, $at) ?? $this->serverIssues->plan($delivery, $destination, $event, $at);
        }
        if ($event->type !== 'notification.digest' && ($event->site_id === null
            || ($event->check_id === null && $event->check_type !== null)
            || (in_array($event->type, ['incident.still_down', 'incident.resolved'], true) && $event->incident_id === null))) {
            return DeliveryDecision::suppress($event->site_id === null ? 'site_deleted' : 'monitoring_stopped');
        }
        if (UptimeDependencies::isGrouped($event->incident)) {
            return DeliveryDecision::suppress(UptimeDependencies::SuppressionReason);
        }
        $episode = UptimeAlertEpisode::appliesTo($event->incident) ? $event->incident : null;
        if ($episode !== null && UptimeAlertEpisode::isOpeningEvent($event)) {
            $sent = UptimeAlertEpisode::sentOpening($episode, $delivery->destination_id);
            $retired = $sent instanceof Delivery ? 'episode_already_alerted' : UptimeAlertEpisode::retirementReason($delivery, $episode);
            if ($retired !== null) {
                return DeliveryDecision::suppress($retired);
            }
            if (($this->deferredOpenings)($delivery, $event, $at)) {
                return DeliveryDecision::moved();
            }
            $sending = UptimeAlertEpisode::sendingOpening($episode, $delivery->destination_id, $at, except: $delivery->id);
            if ($sending instanceof Delivery) {
                return DeliveryDecision::hold($sending->lease_until);
            }
            // Keep an already-attempted opener's format fixed so a receiver that accepted Down and deduplicates
            // retries never loses its separate Recovered alert; fold only before the first attempt (or on retry of an already-folded opener).
            if ($event->cause !== IncidentCause::Errored && $episode->resolved_at !== null
                && ($delivery->attempts === 0 || $delivery->folded_with_recovery)) {
                $check = $episode->check ?? throw new \LogicException('An episode opener requires its check.');
                $site = $event->site ?? throw new \LogicException('An episode opener requires its site.');
                if (IncidentResolutionReason::forCheck($check, $site) instanceof IncidentResolutionReason) {
                    return DeliveryDecision::suppress('monitoring_stopped', clearReason: true);
                }
                if (! UptimeAlertEpisode::recoveryConfirmed($episode, $at)) {
                    return DeliveryDecision::hold(null, UptimeAlertEpisode::WaitingReason, setReason: true);
                }
                $delivery->folded_with_recovery = true;
                if ($delivery->last_reason === UptimeAlertEpisode::ReadyReason) {
                    $delivery->last_reason = null;
                }
            }
        }
        $confirmation = AlertConfirmation::appliesTo($event) ? AlertConfirmation::state($event) : AlertConfirmation::Confirmed;
        if ($confirmation === AlertConfirmation::Refuted) {
            return DeliveryDecision::suppress(AlertConfirmation::SuppressionReason);
        }
        if ($confirmation === AlertConfirmation::Pending) {
            // The confirming run is late or still running: look again in a minute.
            return DeliveryDecision::hold($at->addMinute());
        }
        $resolvedAt = $event->incident?->resolved_at;
        if ($episode === null && $event->type === 'incident.opened' && $resolvedAt !== null
            && (isset($event->payload['uptime_parent_incident_id']) || $delivery->openerRetiredBy($resolvedAt))) {
            return DeliveryDecision::suppress('incident_resolved');
        }
        // Recoveries of catch-up incidents are only planned after the catch-up was accepted.
        if ($event->type === 'maintenance.catch_up'
            && ! DB::table('maintenance_catch_up_incidents')->join('incidents', 'incidents.id', '=', 'maintenance_catch_up_incidents.incident_id')
                ->where('maintenance_catch_up_incidents.event_id', $event->id)->whereNull('incidents.resolved_at')->exists()) {
            return DeliveryDecision::suppress('incident_resolved');
        }
        $recovery = $this->componentRecovery($event);
        if ($recovery instanceof Event && $delivery->openerRetiredBy($recovery->occurred_at)) {
            return DeliveryDecision::suppress('recovered_before_send');
        }
        $access = $this->access($event, $destination, $delivery);
        if ($access instanceof DeliveryDecision) {
            return $access;
        }
        if ($event->type === 'notification.digest') {
            $digest = $this->digest($event, $destination, $at, $claimed);
            if ($digest instanceof DeliveryDecision) {
                return $digest;
            }
        }
        if (in_array($event->type, ['incident.still_down', 'maintenance.catch_up'], true) && $event->site_id !== null
            && MaintenancePeriod::forSite($event->site_id)->getQuery()->where('mute_alerts', true)
                ->where('starts_at', '<=', $at->format('Y-m-d H:i:s.uP'))
                ->where('ends_at', '>', $at->format('Y-m-d H:i:s.uP'))->exists()) {
            return DeliveryDecision::suppress('maintenance');
        }
        if ($event->type === 'incident.still_down') {
            $incident = $event->incident ?? throw new \LogicException('A reminder event must have an incident.');
            if ($incident->resolved_at !== null || ($incident->snoozed_until !== null && $incident->snoozed_until->greaterThan($at))) {
                return DeliveryDecision::suppress($incident->resolved_at !== null ? 'incident_resolved' : 'incident_snoozed');
            }
        }
        if (! $this->accountSystem($destination, $delivery) && ! $destination->addressVerified()) {
            return DeliveryDecision::hold($at->addHour(), 'personal_email_unverified', setReason: true);
        }
        if ($event->type !== 'incident.resolved' && $this->types->severity($event) === AlertSeverity::Recovered) {
            $openings = Delivery::recoveryEpisodeOpenings($event, $delivery->destination_id);
            if (! $openings['kept']) {
                return DeliveryDecision::suppress('warning_opener_not_sent');
            }
            if (! $openings['sent']) {
                return DeliveryDecision::hold($at->addMinute(), keepState: true);
            }
        }
        if ($event->type === 'incident.resolved') {
            $recoveryDecision = $this->incidentRecovery($delivery, $event, $episode, $at);
            if ($recoveryDecision instanceof DeliveryDecision) {
                return $recoveryDecision;
            }
        }
        if ($episode !== null) {
            // Recheck only the immutable alert deadline. A routing change never revokes already-planned work.
            $nextAllowed = UptimeAlertEpisode::notBefore($event) ?? $at;
            if ($nextAllowed->greaterThan($at)) {
                return DeliveryDecision::hold($nextAllowed);
            }
        }
        // A person chose to send a manual message now: it does not wait for quiet hours.
        if ($delivery->origin !== 'manual' && ! $this->types->critical($event)) {
            $due = $this->timing->afterQuietHours(NotificationSchedule::forChannel($destination), $at);
            if ($due->greaterThan($at)) {
                return DeliveryDecision::hold($due);
            }
        }

        return DeliveryDecision::send();
    }

    /**
     * Who may hear about a site event, and a channel that is paused or whose user is disabled. Sequencing (episodes,
     * confirmation, quiet hours, a recovery waiting for its opening) stays in guards().
     */
    private function access(Event $event, NotificationDestination $channel, Delivery $delivery): ?DeliveryDecision
    {
        $site = $event->site;
        $recipient = $channel->user;
        if ($site !== null) {
            if ($recipient !== null && $recipient->disabled_at === null && (! $recipient->hasAnyRole(['Owner', 'Admin', 'Member']) || ! $recipient->canAccessClient($site->client_id))) {
                return DeliveryDecision::suppress('personal_site_access_revoked');
            }
            // Client channels follow site.client_id: a moved site no longer reaches its old client.
            if ($channel->recipient_kind === RecipientKind::Client && $channel->client_id !== $site->client_id) {
                return DeliveryDecision::suppress('client_site_moved');
            }
            if ($channel->recipient_kind === RecipientKind::Team && $channel->scope_client_id !== null && $channel->scope_client_id !== $site->client_id) {
                return DeliveryDecision::suppress('channel_client_scope');
            }
        }
        $accountSystem = $this->accountSystem($channel, $delivery);
        if (! $channel->enabled && $delivery->origin !== 'manual' && ! $accountSystem) {
            return DeliveryDecision::suppress('destination_disabled');
        }
        if (! $accountSystem && $channel->user_id !== null && $recipient?->disabled_at !== null) {
            return DeliveryDecision::suppress('personal_user_disabled');
        }

        return null;
    }

    /** System mail on account channels goes to the account address regardless of pause and verification (design 3.4). */
    private function accountSystem(NotificationDestination $channel, Delivery $delivery): bool
    {
        return $channel->account_mail && $delivery->category === NotificationCategory::System;
    }

    /**
     * System deliveries (design §3.4) skip site, access, maintenance and quiet hours; account channels of Owners and
     * opted-in Admins also skip pause and verification. Mail waits while the mailer only logs.
     */
    private function system(Delivery $delivery, NotificationDestination $destination, CarbonImmutable $at): DeliveryDecision
    {
        $ineligible = SystemIssueMailer::ineligibility($destination);
        if ($ineligible !== null) {
            return DeliveryDecision::suppress($ineligible);
        }
        if ($destination->channel === 'mail' && ! SystemIssueMailer::canDeliver()) {
            return DeliveryDecision::hold($at->addSeconds(SystemIssueMailer::RecheckSeconds), SystemIssueMailer::LogOnlyReason, setReason: true);
        }
        if ($delivery->last_reason === SystemIssueMailer::LogOnlyReason) {
            $delivery->last_reason = null;
        }

        return DeliveryDecision::send();
    }

    /**
     * The digest's items, filtered with sourceDecision(). At the claim an item that may not go is suppressed or, while
     * held, returned to the pool, and the payload is narrowed to the items that remain; at the send any change sends
     * the digest back to the claim. Null when the digest may go as it is.
     */
    private function digest(Event $digest, NotificationDestination $destination, CarbonImmutable $at, bool $claimed): ?DeliveryDecision
    {
        $sourceIds = $digest->payload['delivery_ids'] ?? null;
        if (! is_array($sourceIds) || array_any($sourceIds, static fn (mixed $id): bool => ! is_int($id))) {
            throw new \LogicException('Digest source IDs are missing.');
        }
        $sources = Delivery::query()->with('event.site');
        $sources->getQuery()->whereIn('id', $sourceIds)->orderBy('id');
        $labels = [];
        $included = [];
        $excludedReasons = [];
        $changes = [];
        foreach ($sources->get() as $source) {
            $sourceEvent = $source->event ?? throw new \LogicException('Digest source event is missing.');
            $refusal = $this->sourceDecision($source, $sourceEvent, $destination, $at);
            if ($refusal instanceof DeliveryDecision) {
                $excludedReasons[] = $refusal->reason ?? 'digest_sources_unavailable';
                if (! $source->suppressed && $source->state === DeliveryState::Pending) {
                    // A held item leaves this digest and waits for one after its hold.
                    $changes[] = [$source, $refusal->kind === DeliveryDecisionKind::Hold
                        ? ['digest_event_id' => null, 'next_attempt_at' => $refusal->until ?? $at, 'last_reason' => $refusal->reason]
                        : ['suppressed' => true, 'suppression_reason' => $refusal->reason, 'suppression_evaluated_at' => $at]];
                }

                continue;
            }
            $included[] = $source->id;
            if ($sourceEvent->server_issue_id !== null) {
                $server = $sourceEvent->server_name ?? throw new \LogicException('A server issue event needs its server name.');
                $labels['server:'.$server] = $server;
            } else {
                $site = $sourceEvent->site ?? throw new \LogicException('A digest item must keep its site.');
                $labels[$site->id] = $site->displayName();
            }
        }
        // The claim keeps the payload's order of items, so the digest is unchanged exactly when every item stays.
        $unchanged = $changes === [] && count($included) === count($sourceIds);
        if ($claimed) {
            return $unchanged ? null : DeliveryDecision::rebuild();
        }
        foreach ($changes as [$source, $attributes]) {
            $source->update($attributes);
            $source->announceChanges();
        }
        if ($included === []) {
            return DeliveryDecision::suppress(count(array_unique($excludedReasons)) === 1 ? $excludedReasons[0] : 'digest_sources_unavailable');
        }
        if (! $unchanged) {
            $shown = array_map(static fn (string $label): string => mb_strlen($label, 'UTF-8') > 80
                ? mb_substr($label, 0, 79, 'UTF-8').'…' : $label, array_slice(array_values($labels), 0, 20));
            $digest->update(['payload' => [...$digest->payload, 'count' => count($included), 'sites' => implode(', ', $shown),
                'remaining_sites' => count($labels) - count($shown), 'delivery_ids' => $included]]);
        }

        return null;
    }

    private function incidentRecovery(Delivery $delivery, Event $event, ?Incident $episode, CarbonImmutable $at): ?DeliveryDecision
    {
        if ($episode instanceof Incident) {
            $check = $episode->check ?? throw new \LogicException('An episode recovery requires its check.');
            $site = $event->site ?? throw new \LogicException('An episode recovery requires its site.');
            if (IncidentResolutionReason::forCheck($check, $site) instanceof IncidentResolutionReason) {
                return DeliveryDecision::suppress('monitoring_stopped', clearReason: true);
            }
            if (UptimeAlertEpisode::laterIncident($episode) instanceof Incident) {
                return DeliveryDecision::suppress('reopened_before_recovery_confirmed');
            }
            if (! UptimeAlertEpisode::recoveryConfirmed($episode, $at)) {
                return DeliveryDecision::hold(null, UptimeAlertEpisode::WaitingReason, setReason: true);
            }
        }
        $incidentId = $event->incident_id ?? throw new \LogicException('Recovery event must have an incident.');
        $incident = $event->incident ?? throw new \LogicException('A recovery event must have an incident.');
        $opened = ($episode === null ? Delivery::alertedIncident($incidentId, $delivery->destination_id)
            : UptimeAlertEpisode::sentOpening($episode, $delivery->destination_id))
            ?? Delivery::latestIncidentOpening($incident, $delivery->destination_id);
        if (! $opened instanceof Delivery || $opened->state === DeliveryState::Logged) {
            return DeliveryDecision::suppress('opening_not_delivered');
        }
        if ($opened->state === DeliveryState::Sent && $opened->folded_with_recovery) {
            return DeliveryDecision::suppress(UptimeAlertEpisode::FoldedSuppressionReason, clearReason: true);
        }

        return $opened->state === DeliveryState::Sent ? null : DeliveryDecision::hold($at->addMinute(), keepState: true);
    }

    /** The recovery that ends the episode of a warning or component opener: the next success of the same check component. */
    private function componentRecovery(Event $opening): ?Event
    {
        if (str_starts_with($opening->type, 'incident.') || in_array($opening->type, ['maintenance.catch_up', 'notification.digest'], true)
            || $opening->check_id === null || $this->types->severity($opening) === AlertSeverity::Recovered) {
            return null;
        }
        $next = Event::query()->where('check_id', $opening->check_id)->where('type', $opening->type)
            ->where('component_type', $opening->component_type)->where('component_id', $opening->component_id);
        $next->where(Delivery::succeeded(...));
        $next->getQuery()->where('occurred_at', '>=', $opening->occurred_at->format('Y-m-d H:i:s.uP'))
            ->where('id', '>', $opening->id)->orderBy('occurred_at')->orderBy('id');
        $recovery = $next->first();

        return $recovery instanceof Event && $this->types->severity($recovery) === AlertSeverity::Recovered ? $recovery : null;
    }
}
