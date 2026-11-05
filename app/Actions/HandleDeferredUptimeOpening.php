<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Contracts\NotificationSuppression;
use Heptapod\Contracts\SuppressionSubject;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Events\Notifications\UptimeAlertEpisode;
use Heptapod\Jobs\SendNotification;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\MaintenancePeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Delayed openers recheck mute boundaries without changing the incident's opening facts. */
final readonly class HandleDeferredUptimeOpening
{
    public function __construct(private NotificationSuppression $suppression) {}

    /** Called with the site and delivery locked. True means this opener must not send. */
    public function __invoke(Delivery $delivery, Event $event, CarbonImmutable $at): bool
    {
        $incident = $event->incident;
        if ($incident === null || ! UptimeAlertEpisode::appliesTo($incident) || ! UptimeAlertEpisode::isOpeningEvent($event)
            || UptimeAlertEpisode::alertDue($incident)->greaterThan($at)) {
            return false;
        }
        $checkId = $event->check_id ?? throw new \LogicException('An episode opener requires its check.');
        $decision = $this->suppression->isSuppressed(new SuppressionSubject($checkId), $at);
        if ($decision->suppressed) {
            $timestamp = $at->format('Y-m-d H:i:s.uP');
            if ($decision->reason === 'maintenance') {
                $siteId = $event->site_id ?? throw new \LogicException('An episode opener requires its site.');
                $window = self::mutingWindows($siteId, $at)->firstOrFail();
                $until = $window->ends_at;
                $left = DB::table('maintenance_site_exclusions')->where('maintenance_period_id', $window->id)->where('site_id', $siteId)->value('left_at');
                if (is_string($left)) {
                    $until = min($until, CarbonImmutable::parse($left));
                }
                // The first window owns catch-up policy; overlapping windows only extend the wait.
                $delivery->deferred_maintenance_period_id ??= $window->id;
                $delivery->blocking_maintenance_period_id = $window->id;
            } elseif ($decision->reason === 'snooze') {
                $delivery->blocking_maintenance_period_id = null;
                $end = DB::table('check_snoozes')->where('check_id', $checkId)->whereNull('component_type')
                    ->where('starts_at', '<=', $timestamp)->where('ends_at', '>', $timestamp)
                    ->where(fn (QueryBuilder $query) => $query->whereNull('unsnoozed_at')->orWhere('unsnoozed_at', '>', $timestamp))
                    ->orderByDesc('ends_at')->value('ends_at');
                if (! is_string($end)) {
                    throw new \LogicException('An active check snooze requires its end time.');
                }
                $until = CarbonImmutable::parse($end);
            } else {
                throw new \LogicException('Unsupported delayed-opener suppression: '.$decision->reason);
            }
            $delivery->fill(['state' => DeliveryState::Pending, 'next_attempt_at' => $until, 'last_reason' => 'deferred_by_'.$decision->reason,
                'claim_token' => null, 'lease_until' => null])->save();
            $delivery->announceChanges();

            return true;
        }
        if ($this->isCatchUp($event)) {
            $delivery->update(['deferred_maintenance_period_id' => null, 'blocking_maintenance_period_id' => null, 'last_reason' => null]);
            $delivery->announceChanges();

            return false;
        }
        if ($delivery->deferred_maintenance_period_id !== null) {
            $window = MaintenancePeriod::query()->findOrFail($delivery->deferred_maintenance_period_id);
            $this->replaceWithCatchUp($delivery, $event, $window, $at);

            return true;
        }

        if ($delivery->last_reason === 'deferred_by_snooze') {
            $delivery->update(['last_reason' => null]);
            $delivery->announceChanges();
        }

        return false;
    }

    /**
     * The muting windows of a site at $at, the one that owns the deferral first. Deferring points the delivery at one of
     * them, so a claim takes their keys before the delivery ({@see NotificationLocks::maintenanceWindowKeys()}).
     *
     * @return Builder<MaintenancePeriod>
     */
    public static function mutingWindows(int $siteId, CarbonImmutable $at): Builder
    {
        $timestamp = $at->format('Y-m-d H:i:s.uP');
        $windows = MaintenancePeriod::forSite($siteId, $at);
        $windows->getQuery()->where('mute_alerts', true)->where('starts_at', '<=', $timestamp)->where('ends_at', '>', $timestamp)
            ->orderByDesc('catch_up_at_end')->orderBy('ends_at')->orderBy('id');

        return $windows;
    }

    /**
     * A shortened/disabled window wakes its indexed pending work; the normal dispatcher rechecks it. NotificationLocks: the
     * window, then its deliveries in ascending id (archive and claim lock deliveries in that order too).
     */
    public static function wakeWindow(int $windowId, CarbonImmutable $at, ?int $siteId = null): void
    {
        $query = DB::table('deliveries')->where('blocking_maintenance_period_id', $windowId)
            ->where('suppressed', false)->where('state', DeliveryState::Pending->value)->where('next_attempt_at', '>', $at->format('Y-m-d H:i:s.uP'));
        if ($siteId !== null) {
            $query->whereIn('event_id', DB::table('events')->select('id')->where('site_id', $siteId));
        }
        NotificationLocks::maintenanceWindows([$windowId]);
        $lockedIds = NotificationLocks::deliveries((clone $query)->pluck('id'));
        if ($lockedIds === []) {
            return;
        }
        // Re-evaluated after the locks: a delivery archived or claimed meanwhile no longer matches.
        $query->whereIn('id', $lockedIds)->update(['next_attempt_at' => $at->format('Y-m-d H:i:s.uP')]);
    }

    /**
     * Convert before deletion loses the setting; the caller deletes the window in the same transaction. A client or tag
     * window spans sites, so every lock the conversion and the deletion's referential actions need is taken up front in
     * NotificationLocks order: the checks a catch-up event refers to, the window itself (exclusive, so no new row refers
     * to it), the sites, the incidents (also every incident opened in the window: the deletion SETs NULL on them), the
     * channels, then the deliveries in ascending id (also every delivery that names the window).
     *
     * The checks come before the window because the recorder takes the window's key below its check. A pending opening
     * deferred by the window after the first read adds its check after the window; a deadlock on that check fails the
     * deletion with 40P01 and rolls it back. A recurrence locks its future windows before this runs: nothing refers to a
     * window before it starts, so those have no checks to take.
     */
    public function deletingWindow(MaintenancePeriod $window): void
    {
        $pending = static function () use ($window): Collection {
            $origins = DB::table('deliveries')->select('id')->where('deferred_maintenance_period_id', $window->id);
            $blockers = DB::table('deliveries')->select('id')->where('blocking_maintenance_period_id', $window->id);

            return DB::table('deliveries')->join('events', 'events.id', '=', 'deliveries.event_id')
                ->where('deliveries.suppressed', false)->where('deliveries.state', DeliveryState::Pending->value)->whereIn('deliveries.id', $origins->union($blockers))
                ->get(['deliveries.id', 'deliveries.destination_id', 'events.check_id', 'events.site_id', 'events.incident_id']);
        };
        NotificationLocks::checks($pending()->pluck('check_id'));
        NotificationLocks::maintenanceWindows([$window->id], exclusive: true);
        $candidates = $pending();
        $openedIn = DB::table('incidents')->where('opened_during_maintenance_window_id', $window->id)->pluck('id');
        $naming = DB::table('deliveries')->where('deferred_maintenance_period_id', $window->id)->select('id')
            ->union(DB::table('deliveries')->where('blocking_maintenance_period_id', $window->id)->select('id'))->pluck('id');
        NotificationLocks::checks($candidates->pluck('check_id'));
        NotificationLocks::sites($candidates->pluck('site_id'));
        NotificationLocks::incidents([...$candidates->pluck('incident_id'), ...$openedIn]);
        NotificationLocks::channels($candidates->pluck('destination_id'));
        $lockedIds = NotificationLocks::deliveries([...$candidates->pluck('id'), ...$naming]);
        $candidateIds = $candidates->pluck('id')->all();
        foreach ($lockedIds as $deliveryId) {
            if (! in_array($deliveryId, $candidateIds, true)) {
                continue;
            }
            $delivery = Delivery::query()->whereKey($deliveryId)->where('suppressed', false)->where('state', DeliveryState::Pending->value)->first();
            if ($delivery === null) {
                continue;
            }
            $event = $delivery->event ?? throw new \LogicException('A deferred opener requires its event.');
            $at = CarbonImmutable::now('UTC');
            $incident = $event->incident;
            if ($incident === null || ! UptimeAlertEpisode::appliesTo($incident)) {
                $delivery->update(['suppressed' => true, 'suppression_reason' => 'monitoring_stopped', 'suppression_evaluated_at' => $at]);
            } elseif ($delivery->deferred_maintenance_period_id !== $window->id) {
                // Only the blocking window is being deleted. The policy-origin window
                // still exists, so claim can re-evaluate it after this deletion commits.
                $delivery->update(['blocking_maintenance_period_id' => null,
                    'next_attempt_at' => max($at, UptimeAlertEpisode::alertDue($incident))]);
            } else {
                $retired = UptimeAlertEpisode::retirementReason($delivery, $incident);
                if ($retired !== null) {
                    $delivery->update(['suppressed' => true, 'suppression_reason' => $retired, 'suppression_evaluated_at' => $at]);
                } elseif ($this->isCatchUp($event)) {
                    $delivery->update(['deferred_maintenance_period_id' => null, 'blocking_maintenance_period_id' => null, 'last_reason' => null,
                        'next_attempt_at' => max($at, UptimeAlertEpisode::alertDue($incident))]);
                } else {
                    $this->replaceWithCatchUp($delivery, $event, $window, $at);
                }
            }
            $delivery->announceChanges();
        }
    }

    private function isCatchUp(Event $event): bool
    {
        return $event->type === 'maintenance.catch_up' || ($event->payload['maintenance_first_alert'] ?? false) === true;
    }

    private function replaceWithCatchUp(Delivery $delivery, Event $opening, MaintenancePeriod $window, CarbonImmutable $at): void
    {
        $incident = $opening->incident ?? throw new \LogicException('A deferred opener requires its incident.');
        $key = $window->id.':'.$delivery->destination_id.':uptime:'.$incident->check_id.':'.UptimeAlertEpisode::start($incident)->format('U.u');
        $catchUp = Event::query()->firstOrCreate(['check_id' => $incident->check_id,
            'dedupe_key' => ($window->catch_up_at_end ? 'maintenance:' : 'maintenance-first:').$key], [
                'site_id' => $opening->site_id, 'incident_id' => $incident->id, 'occurred_at' => $at,
                'type' => $window->catch_up_at_end ? 'maintenance.catch_up' : 'incident.still_down',
                'from_result' => $opening->from_result, 'to_result' => $opening->to_result, 'cause' => $opening->cause,
                'payload_version' => 1, 'payload' => $window->catch_up_at_end
                    ? ['uptime_episode' => true, 'count' => 1, 'check_keys' => ['uptime'], 'since' => $incident->opened_at->toIso8601String()]
                    : ['maintenance_first_alert' => true],
                'suppressed' => false, 'suppression_evaluated_at' => $at, 'notification_routed_at' => $at,
            ]);
        if ($window->catch_up_at_end) {
            DB::table('maintenance_catch_up_incidents')->insertOrIgnore(['event_id' => $catchUp->id, 'incident_id' => $incident->id]);
        }
        $catchUpDelivery = Delivery::query()->firstOrCreate(['event_id' => $catchUp->id, 'destination_id' => $delivery->destination_id], [
            'next_attempt_at' => max($at, UptimeAlertEpisode::alertDue($incident)), 'suppression_evaluated_at' => $at,
            'digest' => $delivery->digest,
        ]);
        if ($catchUpDelivery->wasRecentlyCreated) {
            DB::afterCommit(static fn () => SendNotification::dispatch($catchUpDelivery->id));
        }
        $delivery->update(['suppressed' => true, 'suppression_reason' => 'superseded_by_maintenance_catch_up',
            'suppression_evaluated_at' => $at, 'deferred_maintenance_period_id' => null, 'blocking_maintenance_period_id' => null, 'claim_token' => null, 'lease_until' => null]);
        $delivery->announceChanges();
    }
}
