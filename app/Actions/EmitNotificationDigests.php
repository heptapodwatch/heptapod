<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\Notifications\AlertSeverity;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Contracts\NotificationSuppression;
use Heptapod\Contracts\SuppressionSubject;
use Heptapod\Enums\DeliveryDecisionKind;
use Heptapod\Events\Notifications\AlertConfirmation;
use Heptapod\Events\Notifications\DeliveryDecision;
use Heptapod\Events\Notifications\DigestRule;
use Heptapod\Events\Notifications\NotificationEventTypes;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Events\Notifications\NotificationTiming;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\NotificationSchedule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final readonly class EmitNotificationDigests
{
    /** Maximum digest destinations inspected per pass so digest scans cannot starve immediate alert delivery. */
    public const int Batch = 100;

    /** Maximum archived digest source rows suppressed per pass. */
    public const int ArchivedBatch = 100;

    private const string CursorCacheKey = 'heptapod:notifications:digests_after_id';

    private const string ArchivedMoreCacheKey = 'heptapod:notifications:digests_archived_more';

    public function __construct(private NotificationSuppression $suppression, private NotificationTiming $timing,
        private NotificationEventTypes $types, private DigestRule $digests) {}

    /** @return bool whether more digest work remains after this bounded pass */
    public function __invoke(bool $continuation = false): bool
    {
        $archivedMore = (! $continuation || Cache::get(self::ArchivedMoreCacheKey) === true)
            && $this->suppressArchivedSources();
        $after = Cache::get(self::CursorCacheKey);
        if ($continuation && ! is_int($after)) {
            return $archivedMore;
        }
        $afterId = is_int($after) ? $after : 0;
        $destinations = NotificationDestination::query()->where('enabled', true)
            ->whereHas('deliveries', function (Builder $query): void {
                $query->scopes('recurringDigest');
                $query->getQuery()->whereIn('state', [DeliveryState::Pending->value, DeliveryState::Sent->value]);
            });
        $destinations->getQuery()->whereNull('removed_at')
            ->where('notification_destinations.id', '>', $afterId)->orderBy('notification_destinations.id')
            ->limit(self::Batch + 1);
        $candidates = $destinations->get();
        $hasMore = $candidates->count() > self::Batch;
        $page = $candidates->take(self::Batch);
        foreach ($page as $candidate) {
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
                // One digest per channel and minute (its unique digest slot). A channel that already has this minute's digest
                // waits for the next pass instead of colliding, so it never stops the sweep for the channels after it. Digests
                // are created only here, under this channel's lock.
                if (DB::table('deliveries')->where('destination_id', $destination->id)->where('digest_slot_at', $at->startOfMinute()->format('Y-m-d H:i:s.uP'))->exists()) {
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
                        })->orWhere(function (Builder $sent) use ($at, $local): void {
                            // A recurring line on hold (a snoozed or switched-off server) waits until its hold ends.
                            $sent->where('state', DeliveryState::Sent->value)->where('sent_at', '<', $local->startOfDay()->utc()->format('Y-m-d H:i:s.uP'))
                                ->where('next_attempt_at', '<=', $at->format('Y-m-d H:i:s.uP'));
                        });
                    });
                NotificationLocks::forUpdate($query->getQuery()->orderBy('id'));
                $sources = $query->get();
                if ($sources->isEmpty()) {
                    return;
                }
                $included = [];
                $sites = [];
                $servers = [];
                foreach ($sources as $source) {
                    $event = $source->event ?? throw new \LogicException('Digest source event is missing.');
                    $refusal = $this->digests->itemDecision($source, $event, $destination, $at);
                    if ($refusal instanceof DeliveryDecision) {
                        // A waiting item that may never go is suppressed; a sent one that recurs stays: recurrence ends on
                        // terminal conditions (ServerIssueMailer::resolved, an archived channel). A held item (a snoozed or
                        // switched-off server) keeps its hold until it ends, waiting or recurring.
                        if ($refusal->kind === DeliveryDecisionKind::Suppress && $source->state === DeliveryState::Pending) {
                            $source->update(['suppressed' => true, 'suppression_reason' => $refusal->reason, 'suppression_evaluated_at' => $at]);
                            $source->announceChanges();
                        } elseif ($refusal->kind === DeliveryDecisionKind::Hold && $refusal->until instanceof CarbonImmutable) {
                            $source->update(['next_attempt_at' => $refusal->until, ...($source->state === DeliveryState::Pending ? ['last_reason' => $refusal->reason] : [])]);
                            $source->announceChanges();
                        }

                        continue;
                    }
                    if ($event->server_issue_id !== null) {
                        // A server issue stays in the digest while it is open (ServerIssueMailer::refusal refuses a resolved one).
                        $included[] = $source->id;
                        $servers[$event->server_issue_id] = $event->server_name ?? throw new \LogicException('A server issue event needs its server name.');

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
                $digest = Event::query()->create(['type' => 'notification.digest', 'site_id' => null, 'occurred_at' => $at,
                    'payload' => ['count' => count($included), ...DigestRule::labels($sites, $servers), 'delivery_ids' => $included],
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
        if ($hasMore) {
            $last = $page->last();
            if ($last instanceof NotificationDestination) {
                Cache::put(self::CursorCacheKey, $last->id, 3600);
            }
        } else {
            Cache::forget(self::CursorCacheKey);
        }

        return $archivedMore || $hasMore;
    }

    /** Digest sources retained on an archived channel never send; the archive action ends recurrence with them. */
    private function suppressArchivedSources(): bool
    {
        $archived = DB::table('notification_destinations')->select('id')->whereNotNull('removed_at');
        $sources = Delivery::recurringDigest()->where('state', DeliveryState::Pending->value);
        $sources->getQuery()->whereNull('digest_event_id')->whereIn('destination_id', $archived)
            ->orderBy('deliveries.id')->limit(self::ArchivedBatch + 1);
        $rows = $sources->with('destination')->get();
        $hasMore = $rows->count() > self::ArchivedBatch;
        foreach ($rows->take(self::ArchivedBatch) as $source) {
            $reason = $source->destination?->removed_reason === 'recipient_deleted' ? 'recipient_deleted' : 'destination_archived';
            $source->update(['suppressed' => true, 'suppression_reason' => $reason, 'suppression_evaluated_at' => CarbonImmutable::now('UTC')]);
            $source->announceChanges();
        }
        if ($hasMore) {
            Cache::put(self::ArchivedMoreCacheKey, true, 3600);
        } else {
            Cache::forget(self::ArchivedMoreCacheKey);
        }

        return $hasMore;
    }
}
