<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Enums\DeliveryDecisionKind;
use Heptapod\Events\Notifications\DeliveryPlan;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Events\Notifications\UptimeAlertEpisode;
use Heptapod\Jobs\SendNotification;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
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

    public function __construct(private DeliveryPlan $plan) {}

    public function __invoke(int $deliveryId): ?Delivery
    {
        return DB::transaction(function () use ($deliveryId): ?Delivery {
            // A busy channel never holds the delivery worker: this delivery waits for the next pass.
            if (! self::lockSubject($deliveryId, skipBusyChannel: true)) {
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
            $decision = $this->plan->decide($delivery, $destination, $event, $at, claimed: false);
            if ($decision->kind !== DeliveryDecisionKind::Send) {
                $this->plan->apply($delivery, $event, $decision, $at, claimed: false);

                return null;
            }
            self::startSending($delivery, $at);
            $delivery->announceChanges();

            return $delivery;
        });
    }

    /**
     * NotificationLocks for deciding one delivery, top-down: the muting windows of its site (deferring an episode opening
     * points it at one; HandleDeferredUptimeOpening), the event's check (a catch-up event may be inserted), its site (a
     * digest: the sites of its items), its incident, then the channel. The claim and the send take the same locks before
     * DeliveryPlan decides. False when the delivery is gone or, with $skipBusyChannel, its channel is busy.
     */
    public static function lockSubject(int $deliveryId, bool $skipBusyChannel): bool
    {
        $subject = DB::table('deliveries')->join('events', 'events.id', '=', 'deliveries.event_id')->where('deliveries.id', $deliveryId)
            ->first(['events.id as event_id', 'events.type', 'events.check_id', 'events.site_id', 'events.incident_id', 'events.payload', 'deliveries.destination_id']);
        if ($subject === null) {
            return false;
        }
        /** @var \stdClass&object{event_id: int, type: string, check_id: int|null, site_id: int|null, incident_id: int|null, payload: string, destination_id: int} $subject */
        $siteIds = [$subject->site_id];
        if ($subject->type === 'notification.digest') {
            /** @var array{delivery_ids?: list<int>} $payload */
            $payload = json_decode($subject->payload, true, flags: JSON_THROW_ON_ERROR);
            $siteIds = DB::table('deliveries as sources')->join('events', 'events.id', '=', 'sources.event_id')
                ->whereIn('sources.id', $payload['delivery_ids'] ?? [])->pluck('events.site_id')->all();
        }
        // A window shortened meanwhile wakes its deliveries under the window lock, so those window keys come first.
        if ($subject->incident_id !== null && $subject->site_id !== null) {
            NotificationLocks::maintenanceWindowKeys(HandleDeferredUptimeOpening::mutingWindows($subject->site_id, CarbonImmutable::now('UTC'))->getQuery()->pluck('id'));
        }
        NotificationLocks::checks([$subject->check_id]);
        NotificationLocks::sites($siteIds);
        NotificationLocks::incidents([$subject->incident_id]);

        return NotificationLocks::channels([$subject->destination_id], skipLocked: $skipBusyChannel);
    }
}
