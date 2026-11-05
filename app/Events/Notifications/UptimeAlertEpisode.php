<?php

declare(strict_types=1);

namespace Heptapod\Events\Notifications;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Models\Check;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Uptime notification episodes are separate from the incident list's open/resolved state. */
final class UptimeAlertEpisode
{
    public const string WaitingReason = 'recovery_checks_pending';

    public const string ReadyReason = 'recovery_checks_ready';

    public static function appliesTo(?Incident $incident): bool
    {
        return $incident instanceof Incident && $incident->alert_episode_started_at !== null && $incident->check?->type === CheckKey::Uptime;
    }

    public static function start(Incident $incident): CarbonImmutable
    {
        return $incident->alert_episode_started_at ?? throw new \LogicException('An alert episode requires its start time.');
    }

    public static function timing(Incident $incident): AlertDelay
    {
        return new AlertDelay(
            $incident->alert_after_minutes ?? throw new \LogicException('An alert episode requires its minute snapshot.'),
            $incident->alert_recovery_checks ?? throw new \LogicException('An alert episode requires its recovery snapshot.'),
            false,
        );
    }

    /** @return array{alert_episode_started_at: CarbonImmutable, alert_after_minutes: int, alert_recovery_checks: int}|array{} */
    public static function opening(Check $check, CarbonImmutable $firstFailure): array
    {
        if ($check->type !== CheckKey::Uptime) {
            return [];
        }
        $previousQuery = Incident::query()->where('check_id', $check->id);
        $previousQuery->getQuery()->orderByDesc('opened_at')->orderByDesc('id');
        $previous = $previousQuery->first();
        $started = $firstFailure;
        $timing = AlertDelay::for($check->site ?? throw new \LogicException('An uptime check requires its site.'), app(InstanceSettings::class));
        if ($previous instanceof Incident && self::appliesTo($previous) && $previous->resolved_at !== null
            && ! Event::query()->where('incident_id', $previous->id)->where('type', 'incident.resolved')->where('suppression_reason', 'monitoring_stopped')->getQuery()->exists()
            && ! self::recoveryConfirmed($previous, $firstFailure->subMicrosecond())) {
            $timing = self::timing($previous);
            $started = self::start($previous);
        }

        return ['alert_episode_started_at' => $started,
            'alert_after_minutes' => $timing->alertAfterMinutes, 'alert_recovery_checks' => $timing->recoveryChecks];
    }

    /** A muted cause change does not replace the episode's eligible opening alert. */
    public static function routingOpening(Incident $incident): Event
    {
        $query = Event::query()->where('incident_id', $incident->id);
        $query->getQuery()->whereIn('type', ['incident.opened', 'incident.cause_changed'])
            ->whereRaw("(type <> 'incident.cause_changed' OR NOT suppressed)")
            ->orderByDesc('id');

        return $query->firstOrFail();
    }

    /** Recorder-suppressed openings with no post-mute path keep the same policy for early and late rules. */
    public static function openingSuppressionExplanation(Incident $incident, Event $opening): ?string
    {
        if (! $opening->suppressed) {
            return null;
        }
        if ($opening->suppression_reason === 'snooze') {
            return 'notifications.opening_snoozed_no_catch_up';
        }
        $window = $incident->opened_during_maintenance_window_id === null ? null : MaintenancePeriod::query()->find($incident->opened_during_maintenance_window_id);

        return $opening->suppression_reason === 'maintenance' && $window !== null
            && ! $window->catch_up_at_end && config('notifications.renotify_intervals_seconds') === null
            ? 'notifications.preview_maintenance_catch_up_disabled' : null;
    }

    /** The original maintenance policy owns a late recipient, even after its catch-up scan finished. */
    public static function endedOpeningWindow(Incident $incident, CarbonImmutable $at): ?MaintenancePeriod
    {
        if ($incident->opened_during_maintenance_window_id === null) {
            return null;
        }
        $window = MaintenancePeriod::query()->find($incident->opened_during_maintenance_window_id);
        if ($window === null) {
            return null;
        }
        $left = DB::table('maintenance_site_exclusions')->where('maintenance_period_id', $window->id)
            ->where('site_id', $incident->site_id)->where('left_at', '<=', $at->format('Y-m-d H:i:s.uP'))->exists();

        return $window->ends_at->lessThanOrEqualTo($at) || $left ? $window : null;
    }

    public static function isOpeningEvent(Event $event): bool
    {
        return in_array($event->type, ['incident.opened', 'incident.cause_changed', 'maintenance.catch_up'], true)
            || ($event->type === 'incident.still_down' && ($event->payload['maintenance_first_alert'] ?? false) === true);
    }

    public static function alertDue(Incident $incident): CarbonImmutable
    {
        return self::start($incident)->addMinutes(self::timing($incident)->alertAfterMinutes - 1);
    }

    public static function notBefore(Event $event): ?CarbonImmutable
    {
        $incident = $event->incident;
        if ($incident === null || ! self::appliesTo($incident)) {
            return null;
        }
        $timing = self::timing($incident);
        if (self::isOpeningEvent($event)) {
            return self::alertDue($incident);
        }
        if ($event->type === 'incident.resolved') {
            return ($incident->resolved_at ?? throw new \LogicException('A recovery must have its resolution time.'))->addSeconds(
                ($timing->recoveryChecks - 1) * ($incident->check ?? throw new \LogicException('An episode requires its check.'))->interval_seconds,
            );
        }

        return null;
    }

    /** Latest R observations, not elapsed time: a missing run or an intervening failure cannot confirm recovery. */
    public static function recoveryConfirmed(Incident $incident, CarbonImmutable $until): bool
    {
        $from = $incident->resolved_at ?? throw new \LogicException('Recovery confirmation needs a resolved incident.');
        if ($until->lessThan($from)) {
            return false;
        }
        $required = self::timing($incident)->recoveryChecks;
        $results = DB::table('check_runs')->where('check_id', $incident->check_id)
            ->where('ended_at', '>=', $from->format('Y-m-d H:i:s.uP'))->where('ended_at', '<=', $until->format('Y-m-d H:i:s.uP'))
            ->orderByDesc('ended_at')->orderByDesc('id')->limit($required)->pluck('result');

        return $results->count() === $required && $results->every(static fn (mixed $result): bool => $result === CheckResult::Succeeded->value);
    }

    public static function laterIncident(Incident $incident): ?Incident
    {
        $query = Incident::query()->where('check_id', $incident->check_id)
            ->where('alert_episode_started_at', self::start($incident)->format('Y-m-d H:i:s.uP'))
            ->where('opened_at', '>=', $incident->opened_at->format('Y-m-d H:i:s.uP'))->where('id', '>', $incident->id);
        $query->getQuery()->orderByDesc('opened_at')->orderByDesc('id');

        return $query->first();
    }

    /** @return Builder<Delivery> */
    private static function openings(Incident $incident): Builder
    {
        $ids = DB::table('incidents')->select('id')->where('check_id', $incident->check_id)
            ->where('alert_episode_started_at', self::start($incident)->format('Y-m-d H:i:s.uP'));
        $events = DB::table('events')->select('id')->whereIn('incident_id', $ids)->whereIn('type', ['incident.opened', 'incident.cause_changed', 'incident.still_down']);
        $catchUps = DB::table('maintenance_catch_up_incidents')->select('event_id')->whereIn('incident_id', $ids);
        $query = Delivery::query();
        // One bounded event-ID relation lets PostgreSQL drive the delivery index; OR-ed subqueries scan the whole table.
        $query->getQuery()->whereIn('event_id', $events->union($catchUps));

        return $query;
    }

    public static function sentOpening(Incident $incident, ?int $destinationId = null): ?Delivery
    {
        $query = self::openings($incident)->where('state', DeliveryState::Sent->value);
        if ($destinationId !== null) {
            $query->where('destination_id', $destinationId);
        }

        $query->getQuery()->orderBy('sent_at')->orderBy('id');

        return $query->first();
    }

    /**
     * sentOpening() for many channels in one query: the channels whose episode opener was accepted.
     *
     * @param  list<int>  $destinationIds
     * @return array<int, true>
     */
    public static function sentOpeningChannels(Incident $incident, array $destinationIds): array
    {
        $query = self::openings($incident)->where('state', DeliveryState::Sent->value);
        $query->getQuery()->whereIntegerInRaw('destination_id', $destinationIds)->distinct();
        /** @var list<int> $ids */
        $ids = $query->getQuery()->pluck('destination_id')->all();

        return array_fill_keys($ids, true);
    }

    /**
     * latestOpening() for many channels in one query.
     *
     * @param  list<int>  $destinationIds
     * @return array<int, Delivery> keyed by channel id
     */
    public static function latestOpenings(Incident $incident, array $destinationIds): array
    {
        $query = self::openings($incident);
        $query->getQuery()->whereIntegerInRaw('destination_id', $destinationIds)
            ->selectRaw('DISTINCT ON (destination_id) deliveries.*')->orderBy('destination_id')->orderByDesc('id');
        $latest = [];
        foreach ($query->get() as $delivery) {
            $latest[$delivery->destination_id] = $delivery;
        }

        return $latest;
    }

    public static function latestOpening(Incident $incident, int $destinationId): ?Delivery
    {
        $query = self::openings($incident)->where('destination_id', $destinationId);
        $query->getQuery()->orderByDesc('id');

        return $query->first();
    }

    public static function sendingOpening(Incident $incident, int $destinationId, CarbonImmutable $at): ?Delivery
    {
        $query = self::openings($incident)->where('destination_id', $destinationId)->where('state', DeliveryState::Sending->value)->where('lease_until', '>', $at->format('Y-m-d H:i:s.uP'));
        $query->getQuery()->orderBy('id');

        return $query->first();
    }

    /** A continued incident carries the same due time; a short resolved interruption must not send a delayed opener. */
    public static function retirementReason(Delivery $delivery, Incident $incident): ?string
    {
        if ($incident->resolved_at === null) {
            return null;
        }
        if (self::laterIncident($incident) instanceof Incident) {
            return 'episode_continued';
        }
        if (! $delivery->openerRetiredBy($incident->resolved_at)) {
            return null;
        }
        $delayDue = self::alertDue($incident);

        return $incident->resolved_at->lessThan($delayDue) && $delivery->next_attempt_at->greaterThanOrEqualTo($delayDue)
            ? 'recovered_within_alert_delay' : 'incident_resolved';
    }
}
