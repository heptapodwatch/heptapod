<?php

declare(strict_types=1);

namespace Heptapod\Events\Notifications;

use Carbon\CarbonImmutable;
use Heptapod\Actions\HandleDeferredUptimeOpening;
use Heptapod\Actions\SendClientMessage;
use Heptapod\Contracts\Notifications\AlertNotification;
use Heptapod\Contracts\Notifications\AlertSeverity;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Enums\IncidentCause;
use Heptapod\Enums\IncidentResolutionReason;
use Heptapod\Enums\RecipientKind;
use Heptapod\Incidents\UptimeDependencies;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\NotificationSchedule;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;

/**
 * The send-time rule of a site alert (and a client message): its site, check and incident still exist, who may hear about
 * the site, uptime episodes, confirmation, recoveries, maintenance, verification and quiet hours.
 */
final readonly class SiteAlertRule implements AlertRule
{
    public function __construct(private NotificationTiming $timing, private NotificationEventTypes $types, private HandleDeferredUptimeOpening $deferredOpenings,
        private NotificationMessage $messages, private SendClientMessage $clientMessages) {}

    public function decide(Delivery $delivery, NotificationDestination $channel, Event $event, CarbonImmutable $at, bool $claimed): DeliveryDecision
    {
        if ($event->site_id === null || ($event->check_id === null && $event->check_type !== null)
            || (in_array($event->type, ['incident.still_down', 'incident.resolved'], true) && $event->incident_id === null)) {
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
        $access = self::access($event, $channel, $delivery);
        if ($access instanceof DeliveryDecision) {
            return $access;
        }
        if (in_array($event->type, ['incident.still_down', 'maintenance.catch_up'], true)
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
        if (! $channel->addressVerified()) {
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
            $due = $this->timing->afterQuietHours(NotificationSchedule::forChannel($channel), $at);
            if ($due->greaterThan($at)) {
                return DeliveryDecision::hold($due);
            }
        }

        return DeliveryDecision::send();
    }

    public function notification(Delivery $delivery, Event $event, NotificationDestination $channel): Notification
    {
        return str_starts_with($event->type, 'client_message.') ? $this->clientMessages->notification($event)
            : new AlertNotification($this->messages->build($event, $channel, $delivery->category, foldedRecovery: $delivery->folded_with_recovery), $delivery->event_id.':'.$channel->id);
    }

    /**
     * Who may hear about a site event, and a channel that is paused or whose user is disabled; a digest (no site) asks
     * only the channel part. Sequencing (episodes, confirmation, quiet hours, a recovery waiting for its opening) stays in
     * decide().
     */
    public static function access(Event $event, NotificationDestination $channel, Delivery $delivery): ?DeliveryDecision
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
        if (! $channel->enabled && $delivery->origin !== 'manual') {
            return DeliveryDecision::suppress('destination_disabled');
        }
        if ($channel->user_id !== null && $recipient?->disabled_at !== null) {
            return DeliveryDecision::suppress('personal_user_disabled');
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
        if (str_starts_with($opening->type, 'incident.') || $opening->type === 'maintenance.catch_up'
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
