<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\NotificationSuppression;
use Heptapod\Contracts\SuppressionSubject;
use Heptapod\Events\LiveSignal;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Events\Notifications\NotificationRecipients;
use Heptapod\Events\Notifications\UptimeAlertEpisode;
use Heptapod\Incidents\UptimeDependencies;
use Heptapod\Maintenance\MaintenanceTargets;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\MaintenancePeriod;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

final readonly class EmitMaintenanceCatchUps
{
    public function __construct(private NotificationRecipients $routing, private NotificationSuppression $suppression, private HandleDeferredUptimeOpening $deferredOpenings) {}

    public function __invoke(?int $windowId = null): void
    {
        $at = CarbonImmutable::now('UTC');
        $candidates = MaintenancePeriod::query();
        $timestamp = $at->format('Y-m-d H:i:s.uP');
        $candidates->getQuery()->when($windowId !== null, static fn (QueryBuilder $query): QueryBuilder => $query->where('maintenance_periods.id', $windowId))
            ->where('catch_up_at_end', true)->where('mute_alerts', true)
            ->whereNull('catch_up_processed_at')->where(static fn (QueryBuilder $due): QueryBuilder => $due
            ->where('ends_at', '<=', $timestamp)->orWhereExists(static fn (QueryBuilder $ex): QueryBuilder => $ex
            ->selectRaw('1')->from('maintenance_site_exclusions as exclusion')
            ->whereColumn('exclusion.maintenance_period_id', 'maintenance_periods.id')
            ->whereNull('exclusion.catch_up_processed_at')->where('exclusion.left_at', '<=', $timestamp)))
            ->where(static fn (QueryBuilder $eligible): QueryBuilder => $eligible
                ->whereNotExists(static fn (QueryBuilder $pending): QueryBuilder => self::pendingIncident($pending)->selectRaw('1'))
                ->orWhereExists(static function (QueryBuilder $ready) use ($timestamp): QueryBuilder {
                    self::pendingIncident($ready)->selectRaw('1')->join('sites as s', 's.id', '=', 'i.site_id')
                        ->where(static fn (QueryBuilder $due): QueryBuilder => $due
                            ->where('maintenance_periods.ends_at', '<=', $timestamp)
                            ->orWhereExists(static fn (QueryBuilder $exit): QueryBuilder => $exit->selectRaw('1')
                                ->from('maintenance_site_exclusions as site_exit')
                                ->whereColumn('site_exit.maintenance_period_id', 'maintenance_periods.id')
                                ->whereColumn('site_exit.site_id', 'i.site_id')->where('site_exit.left_at', '<=', $timestamp)))
                        ->whereNotExists(static fn (QueryBuilder $covering): QueryBuilder => $covering->selectRaw('1')
                            ->from('maintenance_periods as active')->where('active.mute_alerts', true)
                            ->where('active.starts_at', '<=', $timestamp)->where('active.ends_at', '>', $timestamp)
                            ->whereRaw(...MaintenanceTargets::coversSiteSql('active', 's', 't'))
                            ->whereNotExists(static fn (QueryBuilder $exit): QueryBuilder => $exit->selectRaw('1')
                                ->from('maintenance_site_exclusions as site_exit')
                                ->whereColumn('site_exit.maintenance_period_id', 'active.id')
                                ->whereColumn('site_exit.site_id', 's.id')->where('site_exit.left_at', '<=', $timestamp)));

                    return $ready;
                }))
            ->orderBy('ends_at')->limit(20);
        foreach ($candidates->get() as $candidate) {
            DB::transaction(function () use ($candidate, $at): void {
                // NotificationLocks: the window (exclusive, so the recorder cannot open an incident in it meanwhile), the
                // checks the catch-up events refer to, then the open incidents. No site lock: the inserts below only take
                // the site's key.
                NotificationLocks::maintenanceWindows([$candidate->id], exclusive: true);
                $window = MaintenancePeriod::query()->findOrFail($candidate->id);
                if ($window->catch_up_processed_at !== null) {
                    return;
                }
                $open = Incident::query()->with(['check', 'site']);
                $open->getQuery()->where('opened_during_maintenance_window_id', $window->id)
                    ->whereNull('resolved_at')->orderBy('site_id')->orderBy('id');
                $openIds = (clone $open)->get(['id', 'check_id']);
                NotificationLocks::checks($openIds->pluck('check_id'));
                NotificationLocks::incidents($openIds->pluck('id'));
                $incidents = $open->get();
                $remaining = false;
                foreach ($incidents->groupBy('site_id') as $siteIncidents) {
                    $first = $siteIncidents->first();
                    if ($first === null) {
                        throw new \LogicException('Catch-up site has no incidents.');
                    }
                    $siteId = $first->site_id;
                    $left = DB::table('maintenance_site_exclusions')->where('maintenance_period_id', $window->id)
                        ->where('site_id', $siteId)->where('left_at', '<=', $at->format('Y-m-d H:i:s.uP'))->first();
                    if ($window->ends_at->greaterThan($at) && $left === null) {
                        continue;
                    }
                    if (DB::table('maintenance_catch_up_sites')->where('maintenance_period_id', $window->id)
                        ->where('site_id', $siteId)->exists()) {
                        continue;
                    }
                    if (MaintenancePeriod::forSite($siteId)->getQuery()->where('mute_alerts', true)
                        ->where('starts_at', '<=', $at->format('Y-m-d H:i:s.uP'))
                        ->where('ends_at', '>', $at->format('Y-m-d H:i:s.uP'))->exists()) {
                        $remaining = true;

                        continue;
                    }
                    /** @var array<string, array{destination_id: int, incidents: array<int, Incident>, opening: Event, route: array{due: CarbonImmutable, digest: bool}}> $recipients */
                    $recipients = [];
                    foreach ($siteIncidents as $incident) {
                        // A grouped dependent is covered by its uptime parent's catch-up item.
                        if (UptimeDependencies::isGrouped($incident)
                            || $this->suppression->isSuppressed(new SuppressionSubject($incident->check_id), $at)->suppressed) {
                            continue;
                        }
                        $openingQuery = Event::query();
                        $openingQuery->getQuery()->where('incident_id', $incident->id)->where('type', 'incident.opened');
                        $opening = $openingQuery->firstOrFail();
                        $opening->setRelation('check', $incident->check);
                        $opening->setRelation('incident', $incident);
                        $opening->setRelation('site', $incident->site);
                        $opening->occurred_at = $at;
                        foreach ($this->routing->routes($opening, $at) as $destinationId => $route) {
                            $episode = UptimeAlertEpisode::appliesTo($incident);
                            if ($episode && UptimeAlertEpisode::sentOpening($incident, $destinationId) instanceof Delivery) {
                                continue;
                            }
                            $key = (string) $destinationId;
                            if ($episode) {
                                $key .= ':uptime:'.$incident->check_id.':'.UptimeAlertEpisode::start($incident)->format('U.u');
                            }
                            $recipients[$key]['destination_id'] = $destinationId;
                            $recipients[$key]['incidents'][$incident->id] = $incident;
                            if (! isset($recipients[$key]['route']) || $route['due']->greaterThan($recipients[$key]['route']['due'])) {
                                $recipients[$key]['route'] = $route;
                                $recipients[$key]['opening'] = $opening;
                            }
                        }
                    }
                    foreach ($recipients as $recipientKey => $recipient) {
                        $recipientId = $recipient['destination_id'];
                        if (! isset($recipient['opening'], $recipient['route'])) {
                            throw new \LogicException('Catch-up recipient is missing its route.');
                        }
                        $opening = $recipient['opening'];
                        $items = array_values($recipient['incidents']);
                        if ($items === []) {
                            throw new \LogicException('Catch-up recipient has no incidents.');
                        }
                        $this->admit($window, (string) $recipientKey, $recipientId, $opening, $items, $recipient['route'], $at);
                    }
                    DB::table('maintenance_catch_up_sites')->insert(['maintenance_period_id' => $window->id, 'site_id' => $siteId]);
                    if ($left !== null) {
                        DB::table('maintenance_site_exclusions')->where('id', $left->id)->update(['catch_up_processed_at' => $at]);
                    }
                }
                DB::table('maintenance_site_exclusions')->where('maintenance_period_id', $window->id)
                    ->where('left_at', '<=', $at->format('Y-m-d H:i:s.uP'))->whereNull('catch_up_processed_at')
                    ->whereNotIn('site_id', $incidents->pluck('site_id')->all())->update(['catch_up_processed_at' => $at]);
                if (! $remaining && $window->ends_at->lessThanOrEqualTo($at)) {
                    $window->update(['catch_up_processed_at' => $at]);
                }
            });
        }
    }

    /** Called by active-episode reconciliation with the site locked; recorder suppression remains unchanged. */
    public function reconcileEpisode(Incident $incident, Event $opening, CarbonImmutable $at): void
    {
        if (! $opening->suppressed || $opening->suppression_reason !== 'maintenance') {
            return;
        }
        $window = UptimeAlertEpisode::endedOpeningWindow($incident, $at);
        if (! $window instanceof MaintenancePeriod || (! $window->catch_up_at_end && config('notifications.renotify_intervals_seconds') === null)) {
            return;
        }
        foreach ($this->routing->routes($opening, $at) as $destinationId => $route) {
            if (UptimeAlertEpisode::sentOpening($incident, $destinationId) instanceof Delivery) {
                continue;
            }
            $key = $destinationId.':uptime:'.$incident->check_id.':'.UptimeAlertEpisode::start($incident)->format('U.u');
            $delivery = $this->admit($window, $key, $destinationId, $opening, [$incident], $route, $at);
            if ($delivery->wasRecentlyCreated) {
                ($this->deferredOpenings)($delivery, $delivery->event ?? throw new \LogicException('Catch-up delivery requires its event.'), $at);
            }
        }
    }

    /** @param non-empty-list<Incident> $items
     * @param  array{due: CarbonImmutable, digest: bool}  $route
     */
    private function admit(MaintenancePeriod $window, string $recipientKey, int $destinationId, Event $opening, array $items, array $route, CarbonImmutable $at): Delivery
    {
        $event = Event::query()->firstOrCreate(['check_id' => $opening->check_id,
            'dedupe_key' => ($window->catch_up_at_end ? 'maintenance:' : 'maintenance-first:').$window->id.':'.$recipientKey], [
                'site_id' => $opening->site_id, 'incident_id' => $items[0]->id, 'occurred_at' => $at,
                'type' => $window->catch_up_at_end ? 'maintenance.catch_up' : 'incident.still_down',
                'from_result' => $opening->from_result, 'to_result' => $opening->to_result,
                'cause' => $opening->cause, 'payload_version' => 1,
                'payload' => $window->catch_up_at_end
                    ? ['uptime_episode' => UptimeAlertEpisode::appliesTo($items[0]), 'count' => count($items), 'check_keys' => array_values(array_unique(array_map(
                        static fn (Incident $incident): string => ($incident->check ?? throw new \LogicException('Catch-up check is missing.'))->type->value, $items))),
                        'since' => min(array_map(static fn (Incident $incident): string => $incident->opened_at->toIso8601String(), $items))]
                    : ['maintenance_first_alert' => true],
                'suppressed' => false, 'suppression_evaluated_at' => $at, 'notification_routed_at' => $at,
            ]);
        if ($window->catch_up_at_end) {
            DB::table('maintenance_catch_up_incidents')->insertOrIgnore(array_map(static fn (Incident $incident): array => [
                'event_id' => $event->id, 'incident_id' => $incident->id,
            ], $items));
        }
        $delivery = Delivery::query()->firstOrCreate(['event_id' => $event->id, 'destination_id' => $destinationId], [
            'next_attempt_at' => $route['due'], 'digest' => $route['digest'],
            'suppression_evaluated_at' => $at]);
        if ($delivery->wasRecentlyCreated) {
            LiveSignal::forSite($opening->site ?? throw new \LogicException('Catch-up site is missing.'));
        }

        return $delivery;
    }

    private static function pendingIncident(QueryBuilder $query): QueryBuilder
    {
        return $query->from('incidents as i')->whereColumn('i.opened_during_maintenance_window_id', 'maintenance_periods.id')
            ->whereNull('i.resolved_at')->whereNotExists(static fn (QueryBuilder $sent): QueryBuilder => $sent->selectRaw('1')
            ->from('maintenance_catch_up_sites as processed')->whereColumn('processed.maintenance_period_id', 'maintenance_periods.id')
            ->whereColumn('processed.site_id', 'i.site_id'));
    }
}
