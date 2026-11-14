<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\Notifications\AlertSeverity;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Contracts\NotificationSuppression;
use Heptapod\Contracts\SuppressionSubject;
use Heptapod\Events\Notifications\AlertConfirmation;
use Heptapod\Events\Notifications\NotificationEventTypes;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Events\Notifications\NotificationTiming;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\NotificationSchedule;
use Heptapod\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final readonly class EmitNotificationDigests
{
    public function __construct(private NotificationSuppression $suppression, private NotificationTiming $timing,
        private NotificationEventTypes $types) {}

    public function __invoke(): void
    {
        $this->suppressArchivedSources();
        $destinations = NotificationDestination::query()->where('enabled', true)
            ->whereHas('deliveries', function (Builder $query): void {
                $query->scopes('recurringDigest');
                $query->getQuery()->whereIn('state', [DeliveryState::Pending->value, DeliveryState::Sent->value]);
            });
        $destinations->getQuery()->whereNull('removed_at');
        foreach ($destinations->lazyById(100) as $candidate) {
            DB::transaction(function () use ($candidate): void {
                // NotificationLocks: this channel, then its source deliveries in ascending id (below).
                NotificationLocks::channels([$candidate->id]);
                $destination = NotificationDestination::query()->findOrFail($candidate->id);
                if ($destination->removed_at !== null) {
                    return;
                }
                $schedule = NotificationSchedule::forChannel($destination);
                $timezone = $schedule->timezone();
                $at = CarbonImmutable::now('UTC');
                $local = $at->setTimezone($timezone);
                $date = $local->toDateString();
                $weekdays = $schedule->effectiveDigestWeekdays();
                if ($weekdays !== null && ! in_array($local->dayOfWeekIso, $weekdays, true)) {
                    $remaining = Delivery::recurringDigest()->where('destination_id', $destination->id)->where('state', DeliveryState::Pending->value);
                    $remaining->getQuery()->whereNull('digest_event_id')->where('next_attempt_at', '<=', $at);
                    /** @var list<int> $eventIds */
                    $eventIds = $remaining->pluck('event_id')->all();
                    $remaining->getQuery()->update(['next_attempt_at' => $this->timing->nextDailyDigest($at, $weekdays, $schedule->digest_time, $timezone)]);
                    Delivery::announceEvents($eventIds);

                    return;
                }
                if ($at->lessThan($this->timing->nextDailyDigest($local->startOfDay()->subSecond(), $weekdays, $schedule->digest_time, $timezone))) {
                    return;
                }
                $query = Delivery::recurringDigest()->with(['event.site', 'event.check', 'event.incident'])->where('destination_id', $destination->id)
                    ->where(function (Builder $query) use ($date): void {
                        $query->getQuery()->whereNull('last_digest_local_date')->orWhere('last_digest_local_date', '<', $date);
                    })
                    ->where(function (Builder $query) use ($at, $local): void {
                        $query->where(function (Builder $pending) use ($at): void {
                            $pending->where('state', DeliveryState::Pending->value)->where('next_attempt_at', '<=', $at->format('Y-m-d H:i:s.uP'))
                                ->getQuery()->whereNull('digest_event_id');
                        })->orWhere(function (Builder $sent) use ($local): void {
                            $sent->where('state', DeliveryState::Sent->value)->where('sent_at', '<', $local->startOfDay()->utc()->format('Y-m-d H:i:s.uP'));
                        });
                    });
                NotificationLocks::forUpdate($query->getQuery()->orderBy('id'));
                $sources = $query->get();
                if ($sources->isEmpty()) {
                    return;
                }
                $included = [];
                $sites = [];
                $recipient = $destination->user_id === null ? null : User::query()->findOrFail($destination->user_id);
                foreach ($sources as $source) {
                    $event = $source->event ?? throw new \LogicException('Digest source event is missing.');
                    if ($event->site_id === null || $event->check_id === null) {
                        if ($source->state === DeliveryState::Pending) {
                            $source->update(['suppressed' => true, 'suppression_reason' => $event->site_id === null ? 'site_deleted' : 'monitoring_stopped', 'suppression_evaluated_at' => $at]);
                        }

                        continue;
                    }
                    // A digest carries only alerts their confirming run has confirmed; a pending one waits for the next digest.
                    $confirmation = $source->state === DeliveryState::Pending && AlertConfirmation::appliesTo($event)
                        ? AlertConfirmation::state($event) : AlertConfirmation::Confirmed;
                    if ($confirmation === AlertConfirmation::Pending) {
                        continue;
                    }
                    if ($confirmation === AlertConfirmation::Refuted) {
                        $source->update(['suppressed' => true, 'suppression_reason' => AlertConfirmation::SuppressionReason, 'suppression_evaluated_at' => $at]);
                        $source->announceChanges();

                        continue;
                    }
                    $decision = $this->suppression->isSuppressed(new SuppressionSubject($event->check_id ?? throw new \LogicException('Digest source check is missing.'), $event->component_type, $event->component_id), $at);
                    $severity = $this->types->severity($event);
                    $open = match ($severity) {
                        AlertSeverity::Warning => $event->check?->latest_run_result === CheckResult::Warning,
                        AlertSeverity::Error => $event->incident !== null && $event->incident->resolved_at === null && $event->check?->latest_run_result === CheckResult::Errored,
                        default => false,
                    };
                    $site = $event->site ?? throw new \LogicException('Digest source site is missing.');
                    if ($recipient !== null && $recipient->disabled_at === null && (! $recipient->hasAnyRole(['Owner', 'Admin', 'Member']) || ! $recipient->canAccessClient($site->client_id))) {
                        if ($source->state === DeliveryState::Pending) {
                            $source->update(['suppressed' => true, 'suppression_reason' => 'personal_site_access_revoked', 'suppression_evaluated_at' => $at]);
                            $source->announceChanges();
                        }

                        continue;
                    }
                    if ($decision->suppressed || ! $open) {
                        if ($source->state === DeliveryState::Pending) {
                            $source->update(['suppressed' => true, 'suppression_reason' => $decision->suppressed ? $decision->reason : 'digest_no_longer_open',
                                'suppression_evaluated_at' => $decision->evaluatedAt]);
                            $source->announceChanges();
                        }
                    } else {
                        $included[] = $source->id;
                        $sites[$site->id] = $site->displayName();
                    }
                }
                if ($included === []) {
                    return;
                }
                $shown = array_map(static fn (string $label): string => mb_strlen($label, 'UTF-8') > 80
                    ? mb_substr($label, 0, 79, 'UTF-8').'…' : $label, array_slice(array_values($sites), 0, 20));
                $digest = Event::query()->create(['type' => 'notification.digest', 'site_id' => null, 'occurred_at' => $at,
                    'payload' => ['count' => count($included), 'sites' => implode(', ', $shown), 'remaining_sites' => count($sites) - count($shown), 'delivery_ids' => $included],
                    'suppressed' => false, 'suppression_reason' => null,
                    'suppression_evaluated_at' => $at, 'notification_routed_at' => $at,
                ]);
                Delivery::query()->getQuery()->whereIn('id', $included)->update(['last_digest_local_date' => $date]);
                Delivery::query()->where('state', DeliveryState::Pending->value)->getQuery()->whereIn('id', $included)
                    ->update(['digest_event_id' => $digest->id]);
                Delivery::query()->create(['event_id' => $digest->id, 'destination_id' => $destination->id,
                    'next_attempt_at' => $at, 'suppression_evaluated_at' => $at, 'digest_local_date' => $date,
                    'digest_slot_at' => $at->startOfMinute()]);
            });
        }
    }

    /** Digest sources retained on an archived channel never send; the archive action ends recurrence with them. */
    private function suppressArchivedSources(): void
    {
        $archived = DB::table('notification_destinations')->select('id')->whereNotNull('removed_at');
        $sources = Delivery::recurringDigest()->where('state', DeliveryState::Pending->value);
        $sources->getQuery()->whereNull('digest_event_id')->whereIn('destination_id', $archived);
        foreach ($sources->with('destination')->lazyById(100) as $source) {
            $reason = $source->destination?->removed_reason === 'recipient_deleted' ? 'recipient_deleted' : 'destination_archived';
            $source->update(['suppressed' => true, 'suppression_reason' => $reason, 'suppression_evaluated_at' => CarbonImmutable::now('UTC')]);
            $source->announceChanges();
        }
    }
}
