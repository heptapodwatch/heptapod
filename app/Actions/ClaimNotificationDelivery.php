<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\Notifications\AlertSeverity;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Enums\IncidentResolutionReason;
use Heptapod\Enums\NotificationCategory;
use Heptapod\Enums\RecipientKind;
use Heptapod\Events\Notifications\NotificationEventTypes;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Events\Notifications\NotificationTiming;
use Heptapod\Events\Notifications\UptimeAlertEpisode;
use Heptapod\Incidents\UptimeDependencies;
use Heptapod\Jobs\SendNotification;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\NotificationSchedule;
use Heptapod\Models\User;
use Heptapod\SystemHealth\SystemIssueMailer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class ClaimNotificationDelivery
{
    public const string SystemProbeReason = 'system_probe';

    /** A one-off client address already had its one attempt (SendClientMessage). */
    public const string OneOffAttempted = 'one_off_attempted';

    /** Only the delivery to the exact channel its client message names as one-off; every other delivery keeps its retries. */
    public static function isOneOffAttempt(Delivery $delivery, Event $event): bool
    {
        return $delivery->origin === 'manual' && str_starts_with($event->type, 'client_message.')
            && ($event->payload['one_off_channel_id'] ?? null) === $delivery->destination_id;
    }

    /**
     * The one move of an eligible delivery to Sending: a fresh fencing token, the send lease and one more attempt. The
     * caller has locked the delivery FOR UPDATE in its open transaction and checked eligibility; this takes no lock.
     * Attributes the caller changed on the model beforehand are written in the same update.
     */
    public static function startSending(Delivery $delivery, CarbonImmutable $at): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('A delivery moves to Sending only inside the transaction that locked it.');
        }
        $delivery->update(['state' => DeliveryState::Sending, 'claim_token' => (string) Str::uuid(),
            'lease_until' => $at->addSeconds(SendNotification::LeaseSeconds), 'attempts' => $delivery->attempts + 1]);
    }

    public function __construct(private NotificationTiming $timing, private NotificationEventTypes $types, private HandleDeferredUptimeOpening $deferredOpenings) {}

    public function __invoke(int $deliveryId): ?Delivery
    {
        return DB::transaction(function () use ($deliveryId): ?Delivery {
            // NotificationLocks, top-down: the event's check (a catch-up event may be inserted below), its site (a digest: the
            // sites of its sources), its incident, then the channel. A busy channel never holds the delivery worker: this
            // delivery waits for the next pass.
            $subject = DB::table('deliveries')->join('events', 'events.id', '=', 'deliveries.event_id')->where('deliveries.id', $deliveryId)
                ->first(['events.id as event_id', 'events.type', 'events.check_id', 'events.site_id', 'events.incident_id', 'events.payload', 'deliveries.destination_id']);
            if ($subject === null) {
                return null;
            }
            /** @var \stdClass&object{event_id: int, type: string, check_id: int|null, site_id: int|null, incident_id: int|null, payload: string, destination_id: int} $subject */
            $siteIds = [$subject->site_id];
            if ($subject->type === 'notification.digest') {
                /** @var array{delivery_ids?: list<int>} $payload */
                $payload = json_decode($subject->payload, true, flags: JSON_THROW_ON_ERROR);
                $siteIds = DB::table('deliveries as sources')->join('events', 'events.id', '=', 'sources.event_id')
                    ->whereIn('sources.id', $payload['delivery_ids'] ?? [])->pluck('events.site_id')->all();
            }
            // Deferring an episode opening points the delivery at a muting window of its site (HandleDeferredUptimeOpening);
            // a window shortened meanwhile wakes its deliveries under the window lock, so those window keys come first.
            if ($subject->incident_id !== null && $subject->site_id !== null) {
                NotificationLocks::maintenanceWindowKeys(HandleDeferredUptimeOpening::mutingWindows($subject->site_id, CarbonImmutable::now('UTC'))->getQuery()->pluck('id'));
            }
            NotificationLocks::checks([$subject->check_id]);
            NotificationLocks::sites($siteIds);
            NotificationLocks::incidents([$subject->incident_id]);
            if (! NotificationLocks::channels([$subject->destination_id], skipLocked: true)) {
                return null;
            }
            // Recording also holds the site lock. Capture time after acquiring it so
            // recovery confirmation includes every run committed while this claim waited.
            $at = CarbonImmutable::now('UTC');
            $query = Delivery::awaitingDelivery()->whereKey($deliveryId)->where('digest', false)
                ->where(function (Builder $query) use ($at): void {
                    $query->where(function (Builder $q) use ($at): void {
                        $q->getQuery()->whereIn('state', [DeliveryState::Pending->value, DeliveryState::Failed->value])->where('next_attempt_at', '<=', $at->format('Y-m-d H:i:s.uP'));
                    })->orWhere(fn (Builder $q) => $q->where('state', DeliveryState::Sending->value)->where('lease_until', '<=', $at->format('Y-m-d H:i:s.uP')));
                });
            // System probe rows are claimed only by ClaimSystemProbe; recovery waits are woken by their own scans.
            $query->getQuery()->whereRaw('(last_reason IS NULL OR last_reason NOT IN (?, ?))', [UptimeAlertEpisode::WaitingReason, self::SystemProbeReason]);
            NotificationLocks::forUpdateSkipLocked($query->getQuery());
            $delivery = $query->first();
            if ($delivery === null) {
                return null;
            }
            $destination = $delivery->destination()->getQuery()->firstOrFail();
            $event = $delivery->event()->getQuery()->firstOrFail();
            // A one-off client address gets exactly one attempt. An earlier attempt that never completed (a crash after the
            // provider accepted, an exception, an expired lease) ends here instead of sending again. The channel is locked above.
            if ($delivery->attempts > 0 && self::isOneOffAttempt($delivery, $event)) {
                $delivery->update(['suppressed' => true, 'suppression_reason' => self::OneOffAttempted, 'suppression_evaluated_at' => $at,
                    'claim_token' => null, 'lease_until' => null]);
                app(ArchiveNotificationChannel::class)($destination, ArchiveNotificationChannel::Archived, null);
                $delivery->announceChanges();

                return null;
            }
            if ($destination->removed_at === null && $destination->recipient_kind === RecipientKind::User && $destination->user_id === null) {
                // The recipient CHECK refuses this row; deleting a user always archives its channels first (DeleteProfile).
                throw new \LogicException('Account channel without user must be archived.');
            }
            // Every origin, also manual: an archived channel never sends, including rows created after archiving.
            $removed = $destination->removed_at === null ? null : ($destination->removed_reason === 'recipient_deleted' ? 'recipient_deleted' : 'destination_archived');
            if ($removed !== null) {
                $delivery->update(['suppressed' => true, 'suppression_reason' => $removed, 'suppression_evaluated_at' => $at,
                    'claim_token' => null, 'lease_until' => null]);
                if ($event->type === 'notification.digest') {
                    $sources = Delivery::query()->where('state', DeliveryState::Pending->value)->where('suppressed', false);
                    $sources->getQuery()->where('digest_event_id', $event->id);
                    $sources->update(['suppressed' => true, 'suppression_reason' => $removed, 'suppression_evaluated_at' => $at]);
                }
                $delivery->announceChanges();

                return null;
            }
            if ($event->system_issue_id !== null) {
                return $this->claimSystem($delivery, $destination, $at);
            }
            $recipient = $destination->user_id === null ? null : User::query()->findOrFail($destination->user_id);
            if ($event->type !== 'notification.digest' && ($event->site_id === null
                || ($event->check_id === null && $event->check_type !== null)
                || (in_array($event->type, ['incident.still_down', 'incident.resolved'], true) && $event->incident_id === null))) {
                $delivery->update(['suppressed' => true, 'suppression_reason' => $event->site_id === null ? 'site_deleted' : 'monitoring_stopped', 'suppression_evaluated_at' => $at,
                    'claim_token' => null, 'lease_until' => null]);
                $delivery->announceChanges();

                return null;
            }
            if (UptimeDependencies::isGrouped($event->incident)) {
                $delivery->update(['suppressed' => true, 'suppression_reason' => UptimeDependencies::SuppressionReason,
                    'suppression_evaluated_at' => $at, 'claim_token' => null, 'lease_until' => null]);
                $delivery->announceChanges();

                return null;
            }
            $episode = UptimeAlertEpisode::appliesTo($event->incident) ? $event->incident : null;
            if ($episode !== null && UptimeAlertEpisode::isOpeningEvent($event)) {
                $sent = UptimeAlertEpisode::sentOpening($episode, $delivery->destination_id);
                $retired = $sent instanceof Delivery ? 'episode_already_alerted' : UptimeAlertEpisode::retirementReason($delivery, $episode);
                if ($retired !== null) {
                    $delivery->update(['suppressed' => true, 'suppression_reason' => $retired, 'suppression_evaluated_at' => $at,
                        'claim_token' => null, 'lease_until' => null]);
                    $delivery->announceChanges();

                    return null;
                }
                if (($this->deferredOpenings)($delivery, $event, $at)) {
                    return null;
                }
                $sending = UptimeAlertEpisode::sendingOpening($episode, $delivery->destination_id, $at);
                if ($sending instanceof Delivery) {
                    $delivery->update(['state' => DeliveryState::Pending, 'next_attempt_at' => $sending->lease_until,
                        'claim_token' => null, 'lease_until' => null]);
                    $delivery->announceChanges();

                    return null;
                }
            }
            $resolvedAt = $event->incident?->resolved_at;
            if ($episode === null && $event->type === 'incident.opened' && $resolvedAt !== null
                && (isset($event->payload['uptime_parent_incident_id']) || $delivery->openerRetiredBy($resolvedAt))) {
                $delivery->update(['suppressed' => true, 'suppression_reason' => 'incident_resolved', 'suppression_evaluated_at' => $at]);
                $delivery->announceChanges();

                return null;
            }
            // Recoveries of catch-up incidents are only planned after the catch-up was accepted.
            if ($event->type === 'maintenance.catch_up'
                && ! DB::table('maintenance_catch_up_incidents')->join('incidents', 'incidents.id', '=', 'maintenance_catch_up_incidents.incident_id')
                    ->where('maintenance_catch_up_incidents.event_id', $event->id)->whereNull('incidents.resolved_at')->exists()) {
                $delivery->update(['suppressed' => true, 'suppression_reason' => 'incident_resolved', 'suppression_evaluated_at' => $at]);
                $delivery->announceChanges();

                return null;
            }
            $recovery = $this->componentRecovery($event);
            if ($recovery instanceof Event && $delivery->openerRetiredBy($recovery->occurred_at)) {
                $delivery->update(['suppressed' => true, 'suppression_reason' => 'recovered_before_send', 'suppression_evaluated_at' => $at]);
                $delivery->announceChanges();

                return null;
            }
            if ($event->type === 'notification.digest') {
                $sourceIds = $event->payload['delivery_ids'] ?? null;
                if (! is_array($sourceIds) || array_any($sourceIds, static fn (mixed $id): bool => ! is_int($id))) {
                    throw new \LogicException('Digest source IDs are missing.');
                }
                $sources = Delivery::query()->with('event.site');
                $sources->getQuery()->whereIn('id', $sourceIds);
                $sites = [];
                $included = [];
                $excludedReasons = [];
                foreach ($sources->get() as $source) {
                    if ($source->suppressed) {
                        $excludedReasons[] = $source->suppression_reason === 'site_unfollowed'
                            ? $source->suppression_reason : 'digest_sources_unavailable';

                        continue;
                    }
                    $sourceEvent = $source->event ?? throw new \LogicException('Digest source event is missing.');
                    $site = $sourceEvent->site;
                    if ($site === null || $sourceEvent->check_id === null) {
                        $reason = $site === null ? 'site_deleted' : 'monitoring_stopped';
                        $excludedReasons[] = $reason;
                        if ($source->state === DeliveryState::Pending) {
                            $source->update(['suppressed' => true, 'suppression_reason' => $reason, 'suppression_evaluated_at' => $at]);
                        }

                        continue;
                    }
                    if ($recipient !== null && $recipient->disabled_at === null && (! $recipient->hasAnyRole(['Owner', 'Admin', 'Member']) || ! $recipient->canAccessClient($site->client_id))) {
                        $excludedReasons[] = 'personal_site_access_revoked';
                        if ($source->state === DeliveryState::Pending) {
                            $source->update(['suppressed' => true, 'suppression_reason' => 'personal_site_access_revoked', 'suppression_evaluated_at' => $at]);
                            $source->announceChanges();
                        }

                        continue;
                    }
                    $included[] = $source->id;
                    $sites[$site->id] = $site->displayName();
                }
                if ($included === []) {
                    $reason = count(array_unique($excludedReasons)) === 1 ? $excludedReasons[0] : 'digest_sources_unavailable';
                    $delivery->update(['suppressed' => true, 'suppression_reason' => $reason, 'suppression_evaluated_at' => $at]);
                    $delivery->announceChanges();

                    return null;
                }
                if (count($included) !== count($sourceIds)) {
                    $shown = array_map(static fn (string $label): string => mb_strlen($label, 'UTF-8') > 80
                        ? mb_substr($label, 0, 79, 'UTF-8').'…' : $label, array_slice(array_values($sites), 0, 20));
                    $event->update(['payload' => [...$event->payload, 'count' => count($included), 'sites' => implode(', ', $shown),
                        'remaining_sites' => count($sites) - count($shown), 'delivery_ids' => $included]]);
                }
            }
            if ($event->site !== null) {
                if ($recipient !== null && $recipient->disabled_at === null && (! $recipient->hasAnyRole(['Owner', 'Admin', 'Member']) || ! $recipient->canAccessClient($event->site->client_id))) {
                    $delivery->update(['suppressed' => true, 'suppression_reason' => 'personal_site_access_revoked', 'suppression_evaluated_at' => $at]);
                    $delivery->announceChanges();

                    return null;
                }
                // Client channels follow site.client_id: a moved site no longer reaches its old client.
                if ($destination->recipient_kind === RecipientKind::Client && $destination->client_id !== $event->site->client_id) {
                    $delivery->update(['suppressed' => true, 'suppression_reason' => 'client_site_moved', 'suppression_evaluated_at' => $at]);
                    $delivery->announceChanges();

                    return null;
                }
            }
            if (in_array($event->type, ['incident.still_down', 'maintenance.catch_up'], true) && $event->site_id !== null
                && MaintenancePeriod::forSite($event->site_id)->getQuery()->where('mute_alerts', true)
                    ->where('starts_at', '<=', $at->format('Y-m-d H:i:s.uP'))
                    ->where('ends_at', '>', $at->format('Y-m-d H:i:s.uP'))->exists()) {
                $delivery->update(['suppressed' => true, 'suppression_reason' => 'maintenance', 'suppression_evaluated_at' => $at]);
                $delivery->announceChanges();

                return null;
            }
            if ($event->type === 'incident.still_down') {
                $incident = $event->incident ?? throw new \LogicException('A reminder event must have an incident.');
                if ($incident->resolved_at !== null || ($incident->snoozed_until !== null && $incident->snoozed_until->greaterThan($at))) {
                    $delivery->update(['suppressed' => true, 'suppression_reason' => $incident->resolved_at !== null ? 'incident_resolved' : 'incident_snoozed',
                        'suppression_evaluated_at' => $at]);
                    $delivery->announceChanges();

                    return null;
                }
            }
            // System mail on account channels goes to the account address regardless of pause and verification (design 3.4).
            $accountSystem = $destination->account_mail && $delivery->category === NotificationCategory::System;
            if (! $destination->enabled && $delivery->origin !== 'manual' && ! $accountSystem) {
                $delivery->update(['suppressed' => true, 'suppression_reason' => 'destination_disabled', 'suppression_evaluated_at' => $at]);
                $delivery->announceChanges();

                return null;
            }
            if (! $accountSystem && $destination->user_id !== null && $destination->user?->disabled_at !== null) {
                $delivery->update(['suppressed' => true, 'suppression_reason' => 'personal_user_disabled', 'suppression_evaluated_at' => $at]);
                $delivery->announceChanges();

                return null;
            }
            if (! $accountSystem && ! $destination->addressVerified()) {
                $delivery->update(['state' => DeliveryState::Pending, 'next_attempt_at' => $at->addHour(), 'claim_token' => null, 'lease_until' => null,
                    'last_reason' => 'personal_email_unverified']);
                $delivery->announceChanges();

                return null;
            }
            if ($event->type !== 'incident.resolved' && $this->types->severity($event) === AlertSeverity::Recovered) {
                $openings = Delivery::recoveryEpisodeOpenings($event, $delivery->destination_id);
                if (! $openings['kept']) {
                    $delivery->update(['suppressed' => true, 'suppression_reason' => 'warning_opener_not_sent', 'suppression_evaluated_at' => $at]);
                    $delivery->announceChanges();

                    return null;
                }
                if (! $openings['sent']) {
                    $delivery->update(['next_attempt_at' => $at->addMinute()]);
                    $delivery->announceChanges();

                    return null;
                }
            }
            if ($event->type === 'incident.resolved') {
                if ($episode !== null) {
                    $check = $episode->check ?? throw new \LogicException('An episode recovery requires its check.');
                    $site = $event->site ?? throw new \LogicException('An episode recovery requires its site.');
                    if (IncidentResolutionReason::forCheck($check, $site) instanceof IncidentResolutionReason) {
                        $delivery->update(['suppressed' => true, 'suppression_reason' => 'monitoring_stopped',
                            'suppression_evaluated_at' => $at, 'last_reason' => null, 'claim_token' => null, 'lease_until' => null]);
                        $delivery->announceChanges();

                        return null;
                    }
                    if (UptimeAlertEpisode::laterIncident($episode) instanceof Incident) {
                        $delivery->update(['suppressed' => true, 'suppression_reason' => 'reopened_before_recovery_confirmed',
                            'suppression_evaluated_at' => $at, 'claim_token' => null, 'lease_until' => null]);
                        $delivery->announceChanges();

                        return null;
                    }
                    if (! UptimeAlertEpisode::recoveryConfirmed($episode, $at)) {
                        $delivery->update(['state' => DeliveryState::Pending, 'last_reason' => UptimeAlertEpisode::WaitingReason,
                            'claim_token' => null, 'lease_until' => null]);
                        $delivery->announceChanges();

                        return null;
                    }
                }
                $incidentId = $event->incident_id ?? throw new \LogicException('Recovery event must have an incident.');
                $incident = $event->incident ?? throw new \LogicException('A recovery event must have an incident.');
                $opened = ($episode === null ? Delivery::alertedIncident($incidentId, $delivery->destination_id)
                    : UptimeAlertEpisode::sentOpening($episode, $delivery->destination_id))
                    ?? Delivery::latestIncidentOpening($incident, $delivery->destination_id);
                if (! $opened instanceof Delivery) {
                    $delivery->update(['suppressed' => true, 'suppression_reason' => 'opening_not_delivered', 'suppression_evaluated_at' => $at]);
                    $delivery->announceChanges();

                    return null;
                }
                if ($opened->state === DeliveryState::Logged) {
                    $delivery->update(['suppressed' => true, 'suppression_reason' => 'opening_not_delivered', 'suppression_evaluated_at' => $at]);
                    $delivery->announceChanges();

                    return null;
                }
                if ($opened->state !== DeliveryState::Sent) {
                    $delivery->update(['next_attempt_at' => $at->addMinute()]);
                    $delivery->announceChanges();

                    return null;
                }
            }
            if ($episode !== null) {
                // Recheck only the immutable alert deadline. A routing change never revokes already-planned work.
                $nextAllowed = UptimeAlertEpisode::notBefore($event) ?? $at;
                if ($nextAllowed->greaterThan($at)) {
                    $delivery->update(['state' => DeliveryState::Pending, 'next_attempt_at' => $nextAllowed, 'claim_token' => null, 'lease_until' => null]);
                    $delivery->announceChanges();

                    return null;
                }
            }
            // A person chose to send a manual message now: it does not wait for quiet hours.
            if ($delivery->origin !== 'manual' && ! $this->types->critical($event)) {
                $due = $this->timing->afterQuietHours(NotificationSchedule::forChannel($destination), $at);
                if ($due->greaterThan($at)) {
                    $delivery->update(['state' => DeliveryState::Pending, 'next_attempt_at' => $due, 'claim_token' => null, 'lease_until' => null]);
                    $delivery->announceChanges();

                    return null;
                }
            }
            self::startSending($delivery, $at);
            $delivery->announceChanges();

            return $delivery;
        });
    }

    /**
     * System deliveries (design §3.4) skip site, access, maintenance and quiet hours. Eligibility is rechecked now;
     * account channels of Owners and opted-in Admins also skip pause and verification. Mail waits while the mailer only logs.
     */
    private function claimSystem(Delivery $delivery, NotificationDestination $destination, CarbonImmutable $at): ?Delivery
    {
        $ineligible = SystemIssueMailer::ineligibility($destination);
        if ($ineligible !== null) {
            $delivery->update(['suppressed' => true, 'suppression_reason' => $ineligible, 'suppression_evaluated_at' => $at, 'claim_token' => null, 'lease_until' => null]);

            return null;
        }
        if ($destination->channel === 'mail' && ! SystemIssueMailer::canDeliver()) {
            $delivery->update(['state' => DeliveryState::Pending, 'last_reason' => SystemIssueMailer::LogOnlyReason, 'next_attempt_at' => $at->addSeconds(SystemIssueMailer::RecheckSeconds),
                'claim_token' => null, 'lease_until' => null]);

            return null;
        }
        if ($delivery->last_reason === SystemIssueMailer::LogOnlyReason) {
            $delivery->last_reason = null;
        }
        self::startSending($delivery, $at);

        return $delivery;
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
