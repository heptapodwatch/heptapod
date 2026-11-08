<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Checks\DatabaseClock;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Enums\IncidentResolutionReason;
use Heptapod\Events\EventOutbox;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Models\Check;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

final readonly class ResolveInactiveCheckIncidents
{
    public function __construct(private EventOutbox $outbox, private DatabaseClock $clock) {}

    /**
     * Ends the check's open incident without a recovery: for $reason, else for the reason its monitoring has stopped.
     *
     * @return list<int>
     */
    public function __invoke(Check $check, ?IncidentResolutionReason $reason = null, ?User $actor = null): array
    {
        return DB::transaction(function () use ($check, $reason, $actor): array {
            // NotificationLocks: the check and its site (exclusive), then the incidents and deliveries below (lockWork).
            // Callers that resolve several checks (PauseSite, DeleteSite) take lockWork for all of them first.
            NotificationLocks::checks([], exclusive: [$check->id]);
            $locked = Check::query()->find($check->id);
            // A repair candidate can be hard-deleted before its check lock is acquired.
            if (! $locked instanceof Check) {
                return [];
            }
            NotificationLocks::sites([$locked->site_id], exclusive: true);
            $site = Site::query()->findOrFail($locked->site_id);
            $reason ??= IncidentResolutionReason::forCheck($locked, $site);
            if (! $reason instanceof IncidentResolutionReason) {
                return [];
            }
            // TODO: S4 moves this to the app clock (C10–C13).
            $at = $this->clock->now();
            self::lockWork([$locked->id]);
            app(CloseWaitingUptimeRecoveries::class)($locked->id, $at);
            $incidents = Incident::query()->where('check_id', $locked->id);
            $incidents->getQuery()->whereNull('resolved_at')->orderBy('id')->limit(1);
            $ids = [];
            foreach ($incidents->get() as $incident) {
                $incident->update(['resolved_at' => $at]);
                $this->outbox->record($locked, $at, 'incident.resolved', $locked->latest_run_result, $locked->latest_run_result,
                    ['summary' => $reason->labelKey(), 'summary_params' => ['resolution_key' => $reason->labelKey()], 'resolution_reason' => $reason->value],
                    $incident, $incident->cause, 'monitoring-stopped:'.$incident->id, suppressionReason: 'monitoring_stopped');
                $audit = activity('audit')->performedOn($incident)->event('incident.resolved')
                    ->withProperties(['reason' => $reason->value, 'origin' => self::auditOrigin(), 'check_id' => $locked->id, 'site_id' => $site->id]);
                if ($actor instanceof User) {
                    $audit->causedBy($actor);
                } elseif (app()->runningInConsole()) {
                    $audit->causedByAnonymous();
                }
                $audit->log('incident.resolved');
                $ids[] = $incident->id;
            }
            if ($ids !== []) {
                // An open episode has no recovery event; its new administrative resolution is already suppressed.
                $alertTypes = ['incident.opened', 'incident.cause_changed', 'incident.still_down'];
                $events = Event::query();
                $events->getQuery()->whereIn('incident_id', $ids)->whereNull('notification_routed_at')->where('suppressed', false)
                    ->whereIn('type', $alertTypes);
                foreach ($events->lazyById(100) as $event) {
                    $event->update(['suppressed' => true, 'suppression_reason' => 'monitoring_stopped', 'suppression_evaluated_at' => $at]);
                }
                $deliveries = Delivery::query()->where('suppressed', false);
                $deliveries->getQuery()->whereIn('state', [DeliveryState::Pending->value, DeliveryState::Failed->value])
                    ->whereIn('event_id', DB::table('events')->whereIn('incident_id', $ids)->whereIn('type', $alertTypes)->select('id'));
                foreach ($deliveries->lazyById(100) as $delivery) {
                    $delivery->update(['suppressed' => true, 'suppression_reason' => 'monitoring_stopped', 'suppression_evaluated_at' => $at]);
                }
            }
            $locked->update(['consecutive_failures' => 0, 'failing_since' => null]);

            return $ids;
        });
    }

    /**
     * NotificationLocks below the checks and sites the caller holds: the open incidents of $checkIds, then in one ascending
     * pass every delivery a resolution of them may write (the queued rows of their events: waiting recoveries and alerts),
     * together with $alsoDeliveries (ids the caller writes in the same transaction). Resolving check after check would
     * otherwise lock one check's deliveries before the next check's incidents and lower delivery ids.
     *
     * @param  list<int>  $checkIds
     */
    public static function lockWork(array $checkIds, ?QueryBuilder $alsoDeliveries = null): void
    {
        NotificationLocks::incidents(DB::table('incidents')->whereIn('check_id', $checkIds)->whereNull('resolved_at')->pluck('id'));
        $queued = DB::table('deliveries')->select('id')->where('suppressed', false)->whereIn('state', [DeliveryState::Pending->value, DeliveryState::Failed->value])
            ->whereIn('event_id', DB::table('events')->select('id')->whereIn('check_id', $checkIds));
        NotificationLocks::deliveriesMatching($alsoDeliveries instanceof QueryBuilder ? $queued->union($alsoDeliveries) : $queued);
    }

    public static function auditOrigin(): string
    {
        return app()->runningInConsole() ? 'command' : (request()->is('api/*') ? 'api' : 'ui');
    }
}
