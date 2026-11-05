<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Enums\IncidentCause;
use Heptapod\Events\EventOutbox;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Events\Notifications\UptimeAlertEpisode;
use Heptapod\Models\Check;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\MaintenancePeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final readonly class EmitIncidentReminders
{
    public function __construct(private EventOutbox $outbox, private CheckRegistry $registry) {}

    public function __invoke(): void
    {
        $intervals = config('notifications.renotify_intervals_seconds');
        if ($intervals === null) {
            return;
        }
        if (! is_array($intervals) || ! array_is_list($intervals) || $intervals === []) {
            throw new \LogicException('Re-notify intervals must be a non-empty list of positive seconds or null.');
        }
        foreach ($intervals as $interval) {
            if (! is_int($interval) || $interval < 1) {
                throw new \LogicException('Re-notify intervals must be positive seconds.');
            }
        }
        $query = Incident::query();
        $query->whereHas('check', static fn (Builder $checks): Builder => $checks->where('type', CheckKey::Uptime));
        $query->getQuery()->whereNull('resolved_at')->where('cause', IncidentCause::Failed->value);
        foreach ($query->lazyById(100) as $candidate) {
            DB::transaction(function () use ($candidate, $intervals): void {
                // Reminders lock the uptime check before its incident; they never acquire the site lock.
                $checks = Check::query()->whereKey($candidate->check_id);
                NotificationLocks::forUpdate($checks->getQuery());
                $check = $checks->first();
                $incidents = Incident::query()->whereKey($candidate->id);
                NotificationLocks::forUpdate($incidents->getQuery()->whereNull('resolved_at'));
                $incident = $incidents->first();
                $site = $check?->site;
                if ($check === null || $incident === null || $site === null || $check->type !== CheckKey::Uptime || $incident->cause !== IncidentCause::Failed || $check->latest_run_result !== CheckResult::Failed || ! $check->enabled || $this->registry->availability($check->type)->reason !== null || $site->paused_at !== null || $site->marked_for_deletion_at !== null) {
                    return;
                }
                $at = CarbonImmutable::now('UTC');
                if (($incident->snoozed_until !== null && $incident->snoozed_until->greaterThan($at))
                    || MaintenancePeriod::forSite($site->id)->getQuery()->where('mute_alerts', true)
                        ->where('starts_at', '<=', $at->format('Y-m-d H:i:s.uP'))
                        ->where('ends_at', '>', $at->format('Y-m-d H:i:s.uP'))->exists()) {
                    return;
                }
                $events = Event::query()->where('incident_id', $incident->id);
                $events->getQuery()->whereIn('type', ['incident.opened', 'incident.cause_changed', 'incident.still_down']);
                $last = $events->latest('occurred_at')->first();
                $openingQuery = Delivery::query()->where('state', DeliveryState::Sent->value);
                $openingEvents = DB::table('events')->select('id')->where('incident_id', $incident->id)->whereIn('type', ['incident.opened', 'incident.still_down']);
                $catchUps = DB::table('maintenance_catch_up_incidents')->select('event_id')->where('incident_id', $incident->id);
                $openingQuery->getQuery()->whereIn('event_id', $openingEvents->union($catchUps))->orderBy('sent_at');
                $episode = UptimeAlertEpisode::appliesTo($incident);
                $opening = $episode ? UptimeAlertEpisode::sentOpening($incident) : $openingQuery->first();
                if ($opening?->sent_at === null) {
                    $window = $incident->opened_during_maintenance_window_id === null ? null : MaintenancePeriod::query()->find($incident->opened_during_maintenance_window_id);
                    if ($window === null || $window->catch_up_at_end) {
                        return;
                    }
                    if ($episode) {
                        if (($last?->payload['maintenance_first_alert'] ?? false) === true
                            || max($window->ends_at, UptimeAlertEpisode::alertDue($incident))->greaterThan($at)) {
                            return;
                        }
                        $this->outbox->record($check, $at, 'incident.still_down', $check->latest_run_result, $check->latest_run_result,
                            ['maintenance_first_alert' => true], $incident, $incident->cause, 'maintenance-first:'.$incident->id);

                        return;
                    }
                }
                $since = $last->occurred_at ?? $incident->opened_at;
                if ($opening?->sent_at !== null) {
                    $since = max($since, $opening->sent_at);
                }
                // Only the sequence position matters; the final interval repeats for the rest of the incident.
                $reminders = Event::query()->where('incident_id', $incident->id)->where('type', 'incident.still_down');
                $reminders->getQuery()->whereRaw("NOT (payload @> '{\"maintenance_first_alert\":true}'::jsonb)")->orderByDesc('occurred_at')->limit(count($intervals) - 1);
                $position = count($reminders->get(['id'])->all());
                if ($since->addSeconds($intervals[$position])->greaterThan($at)) {
                    return;
                }
                $this->outbox->record($check, $at, 'incident.still_down', $check->latest_run_result, $check->latest_run_result,
                    [], $incident, $incident->cause, 'reminder:'.$incident->id.':'.$since->format('U.u'));
            });
        }
    }
}
