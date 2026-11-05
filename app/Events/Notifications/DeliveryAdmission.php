<?php

declare(strict_types=1);

namespace Heptapod\Events\Notifications;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\Notifications\AlertSeverity;
use Heptapod\Enums\IncidentResolutionReason;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Illuminate\Support\Facades\DB;

/**
 * Step 3 of routing (design §2.1): episode and opener guards for one real event and one eligible channel.
 * Only planning calls it; explanation exemplars are never admitted. Called with the site and event locked.
 */
final readonly class DeliveryAdmission
{
    public const string Admit = 'admit';

    public const string Skip = 'skip';

    /** Skipped after retiring the channel's pending opener; the site's pages must hear about it. */
    public const string Retired = 'retired';

    public function __construct(private NotificationEventTypes $types) {}

    /**
     * Admit, Skip, Retired, or a suppression reason the delivery is recorded with. Retiring a never-attempted opener of a
     * recovered episode updates that opener here, in the planner's transaction.
     */
    public function decide(Event $event, int $channelId, CarbonImmutable $due, CarbonImmutable $at): string
    {
        $episode = UptimeAlertEpisode::appliesTo($event->incident) ? $event->incident : null;
        if ($episode !== null && $event->type === 'incident.resolved'
            && IncidentResolutionReason::forCheck($episode->check ?? throw new \LogicException('An episode needs its check.'), $event->site ?? throw new \LogicException('An episode needs its site.')) instanceof IncidentResolutionReason) {
            return 'monitoring_stopped';
        }
        if ($episode !== null && UptimeAlertEpisode::isOpeningEvent($event)
            && UptimeAlertEpisode::sentOpening($episode, $channelId) instanceof Delivery) {
            return 'episode_already_alerted';
        }
        if ($event->type === 'incident.still_down') {
            if ($due->greaterThan($at)) {
                return self::Skip;
            }
            $reminderIncident = $event->incident ?? throw new \LogicException('A reminder event must have an incident.');
            $opened = $episode === null ? Delivery::alertedIncident($reminderIncident->id, $channelId) : UptimeAlertEpisode::sentOpening($episode, $channelId);
            if (! $opened instanceof Delivery && ($reminderIncident->opened_during_maintenance_window_id === null
                || DB::table('deliveries')->where('destination_id', $channelId)->where('suppressed', false)
                    ->whereIn('event_id', DB::table('maintenance_catch_up_incidents')->select('event_id')
                        ->where('incident_id', $reminderIncident->id))->exists())) {
                return self::Skip;
            }
            if ($opened?->sent_at !== null && $opened->sent_at->addHour()->greaterThan($at)) {
                return self::Skip;
            }
        }
        if ($event->type !== 'incident.resolved' && $this->types->severity($event) === AlertSeverity::Recovered
            && ! Delivery::recoveryEpisodeOpenings($event, $channelId)['kept']) {
            return self::Skip;
        }
        if ($event->type === 'incident.resolved') {
            $incident = $event->incident ?? throw new \LogicException('A recovery event must have an incident.');
            $opened = $episode === null ? Delivery::alertedIncident($incident->id, $channelId) : UptimeAlertEpisode::sentOpening($episode, $channelId);
            if (! $opened instanceof Delivery) {
                $pending = Delivery::latestIncidentOpening($incident, $channelId);
                if ($episode instanceof Incident && $pending instanceof Delivery) {
                    $retired = UptimeAlertEpisode::retirementReason($pending, $episode);
                    if ($retired !== null) {
                        $pending->update(['suppressed' => true, 'suppression_reason' => $retired, 'suppression_evaluated_at' => $at]);

                        return self::Retired;
                    }
                }
                if (! $pending instanceof Delivery || ($pending->attempts === 0 && $pending->next_attempt_at->greaterThan($event->occurred_at))) {
                    return self::Skip;
                }
            }
        }

        return self::Admit;
    }
}
