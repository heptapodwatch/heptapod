<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Carbon\CarbonImmutable;
use Heptapod\Enums\IncidentResolutionReason;
use Heptapod\Events\LiveSignal;
use Heptapod\Events\Notifications\DeliveryAdmission;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Events\Notifications\NotificationRecipients;
use Heptapod\Events\Notifications\UptimeAlertEpisode;
use Heptapod\Incidents\UptimeDependencies;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\Site;
use Illuminate\Support\Facades\DB;

final readonly class PlanNotificationEvent
{
    public function __construct(private NotificationRecipients $recipients, private DeliveryAdmission $admission, private HandleDeferredUptimeOpening $deferredOpenings, private EmitMaintenanceCatchUps $catchUps) {}

    public function __invoke(int $eventId): void
    {
        $this->plan($eventId);
    }

    /** Only routing changes (design 2.2) can revisit a routed, still-active uptime opening. */
    public function reconcileActiveEpisode(int $incidentId): void
    {
        DB::transaction(function () use ($incidentId): void {
            $candidate = Incident::query()->find($incidentId);
            if ($candidate === null) {
                return;
            }
            // NotificationLocks: the check (catch-up events refer to it), the site, this incident (written below), then the
            // opening event and, in plan(), its channels.
            NotificationLocks::checks([$candidate->check_id]);
            NotificationLocks::sites([$candidate->site_id]);
            NotificationLocks::incidents([$candidate->id]);
            $incident = $candidate->fresh();
            if ($incident === null || $incident->routing_reconcile_requested_at === null) {
                return;
            }
            $requestedAt = $incident->routing_reconcile_requested_at;
            $site = $incident->site;
            $check = $incident->check;
            if ($incident->resolved_at === null && UptimeAlertEpisode::appliesTo($incident)
                && $site !== null && $check !== null
                && ! IncidentResolutionReason::forCheck($check, $site) instanceof IncidentResolutionReason) {
                $opening = UptimeAlertEpisode::routingOpening($incident);
                $locked = Event::query()->whereKey($opening->id);
                NotificationLocks::forUpdate($locked->getQuery());
                $opening = $locked->firstOrFail();
                $this->catchUps->reconcileEpisode($incident, $opening, CarbonImmutable::now('UTC'));
                $this->plan($opening->id, $opening->notification_routed_at !== null);
            }
            // A routing change saved while planning must keep its newer request for the next pass.
            Incident::query()->whereKey($incidentId)->where('routing_reconcile_requested_at', $requestedAt->format('Y-m-d H:i:s.uP'))
                ->update(['routing_reconcile_requested_at' => null]);
        });
    }

    private function plan(int $eventId, bool $reconciling = false): void
    {
        // A pending delivery is announced to its site's incident and site pages after commit; no send happens here.
        $plannedSite = null;
        DB::transaction(function () use ($eventId, $reconciling, &$plannedSite): void {
            // Follow UpdateSite's order: sites before events and deliveries. Re-read the
            // event under lock because deleting its site can detach it while we wait.
            $candidate = Event::query()->find($eventId);
            if ($candidate?->site_id !== null) {
                NotificationLocks::sites([$candidate->site_id]);
            }
            $query = Event::query()->whereKey($eventId);
            NotificationLocks::forUpdate($query->getQuery());
            $event = $query->first();
            if ($event === null || (! $reconciling && $event->notification_routed_at !== null)) {
                return;
            }
            $at = CarbonImmutable::now('UTC');
            if ($event->type !== 'notification.digest' && ($event->site_id === null
                || ($event->check_id === null && $event->check_type !== null)
                || (in_array($event->type, ['incident.still_down', 'incident.resolved'], true) && $event->incident_id === null))) {
                // Detached monitoring events remain audit facts, never new notification work.
                if ($event->notification_routed_at === null) {
                    $event->update(['notification_routed_at' => $at]);
                }

                return;
            }
            $reminderIncident = $event->type === 'incident.still_down'
                ? ($event->incident ?? throw new \LogicException('A reminder event must have an incident.')) : null;
            // Late uptime incidents can group dependent work that has not been sent yet.
            if (UptimeDependencies::isGrouped($event->incident) && ! $event->suppressed) {
                $event->update(['suppressed' => true, 'suppression_reason' => UptimeDependencies::SuppressionReason,
                    'suppression_evaluated_at' => $at]);
            }
            // NotificationLocks: channels are taken in ascending id, each before its delivery is inserted.
            $routes = $this->recipients->routes($event, $at);
            ksort($routes);
            if ($event->suppressed && $event->suppression_reason === UptimeDependencies::SuppressionReason) {
                foreach ($routes as $channelId => $route) {
                    Delivery::query()->firstOrCreate(['event_id' => $event->id, 'destination_id' => $channelId], [
                        'next_attempt_at' => $at, 'suppressed' => true, 'suppression_reason' => UptimeDependencies::SuppressionReason,
                        'suppression_evaluated_at' => $event->suppression_evaluated_at,
                    ]);
                }
            }
            // Recorder snapshots remain authoritative; due episode openers additionally recheck current mute boundaries.
            if (! $event->suppressed && ($reminderIncident === null || $reminderIncident->resolved_at === null)) {
                foreach ($routes as $channelId => $route) {
                    if ($reconciling && (Delivery::query()->where('event_id', $event->id)->where('destination_id', $channelId)->getQuery()->exists()
                        || UptimeAlertEpisode::sentOpening($event->incident ?? throw new \LogicException('Reconciliation requires an episode.'), $channelId) instanceof Delivery)) {
                        continue;
                    }
                    // Serialize planning with pause and archive so neither misses a delivery inserted with stale state.
                    NotificationLocks::channels([$channelId]);
                    $channel = NotificationDestination::query()->findOrFail($channelId);
                    if (! $channel->enabled || $channel->removed_at !== null) {
                        continue;
                    }
                    $decision = $this->admission->decide($event, $channelId, $route['due'], $at);
                    if ($decision === DeliveryAdmission::Retired) {
                        $plannedSite = $event->site;
                    }
                    if (in_array($decision, [DeliveryAdmission::Skip, DeliveryAdmission::Retired], true)) {
                        continue;
                    }
                    if ($decision !== DeliveryAdmission::Admit) {
                        Delivery::query()->firstOrCreate(['event_id' => $event->id, 'destination_id' => $channelId], [
                            'next_attempt_at' => $route['due'], 'suppressed' => true, 'suppression_reason' => $decision,
                            'suppression_evaluated_at' => $at,
                        ]);

                        continue;
                    }
                    $delivery = Delivery::query()->firstOrCreate(['event_id' => $event->id, 'destination_id' => $channelId], [
                        'next_attempt_at' => $route['due'], 'digest' => $route['digest'],
                        'suppression_evaluated_at' => $event->suppression_evaluated_at,
                    ]);
                    if ($delivery->wasRecentlyCreated) {
                        ($this->deferredOpenings)($delivery, $event, $at);
                        if ($event->site !== null) {
                            $plannedSite = $event->site;
                        }
                    }
                }
            }
            if ($event->notification_routed_at === null) {
                $event->update(['notification_routed_at' => $at]);
            }
        });
        if ($plannedSite instanceof Site) {
            LiveSignal::forSite($plannedSite);
        }
    }
}
