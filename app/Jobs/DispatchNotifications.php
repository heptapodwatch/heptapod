<?php

declare(strict_types=1);

namespace Heptapod\Jobs;

use Carbon\CarbonImmutable;
use Heptapod\Actions\ClaimNotificationDelivery;
use Heptapod\Actions\EmitIncidentReminders;
use Heptapod\Actions\EmitMaintenanceCatchUps;
use Heptapod\Actions\EmitNotificationDigests;
use Heptapod\Actions\PlanNotificationEvent;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Events\Notifications\UptimeAlertEpisode;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\UniqueFor;
use Throwable;

/**
 * Durable scans recover lost wake-ups; deliveries dedupe overlapping dispatchers. The unique lock ends when a run starts,
 * so a wake-up during a run queues the next one. Each item and step runs on its own: one that throws is reported and
 * cannot hold back the others; the job fails afterwards, and notifications_stalled tells the Owner if work stays stuck.
 */
#[Timeout(60)]
#[UniqueFor(120)]
final class DispatchNotifications implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public const int Batch = 100;

    public function __construct(public readonly bool $continuation = false)
    {
        $this->onQueue('notifications');
    }

    /**
     * Deliveries the dispatcher hands to SendNotification when due at $at, oldest due first so retries of a dead channel
     * cannot crowd out fresh alerts.
     *
     * @return Builder<Delivery>
     */
    public static function due(CarbonImmutable $at): Builder
    {
        $deliveries = Delivery::query()->where('suppressed', false)->where('digest', false)
            ->where(function (Builder $query) use ($at): void {
                $query->where(function (Builder $q) use ($at): void {
                    $q->getQuery()->whereIn('state', [DeliveryState::Pending->value, DeliveryState::Failed->value])->where('next_attempt_at', '<=', $at->format('Y-m-d H:i:s.uP'));
                })->orWhere(fn (Builder $q) => $q->where('state', DeliveryState::Sending->value)->where('lease_until', '<=', $at->format('Y-m-d H:i:s.uP')));
            });
        $deliveries->getQuery()->whereRaw('(last_reason IS NULL OR last_reason NOT IN (?, ?))', [UptimeAlertEpisode::WaitingReason, ClaimNotificationDelivery::SystemProbeReason])
            ->orderBy('next_attempt_at')->orderBy('id');

        return $deliveries;
    }

    public function handle(PlanNotificationEvent $plan, EmitIncidentReminders $reminders, EmitNotificationDigests $digests,
        EmitMaintenanceCatchUps $catchUps): void
    {
        $failure = null;
        $isolated = static function (callable $step) use (&$failure): mixed {
            try {
                return $step();
            } catch (Throwable $exception) {
                if (! $failure instanceof Throwable) {
                    $failure = $exception;
                } else {
                    report($exception);
                }

                return null;
            }
        };
        /** @var list<int> $dispatchedIds */
        $dispatchedIds = [];
        $dispatchDue = static function () use (&$dispatchedIds): int {
            $remaining = self::Batch - count($dispatchedIds);
            if ($remaining <= 0) {
                return 0;
            }
            $due = self::due(CarbonImmutable::now('UTC'));
            if ($dispatchedIds !== []) {
                $due->getQuery()->whereNotIn('id', $dispatchedIds);
            }
            $due->getQuery()->limit($remaining);
            $deliveries = $due->get(['id']);
            foreach ($deliveries as $delivery) {
                $dispatchedIds[] = $delivery->id;
                SendNotification::dispatch($delivery->id);
            }

            return $deliveries->count();
        };

        // Send already-due alerts first so a housekeeping backlog or error can never delay ready deliveries.
        $dispatchDue();
        $requests = Incident::query();
        $requests->getQuery()->whereNotNull('routing_reconcile_requested_at')->orderBy('id')->limit(self::Batch);
        $incidents = $requests->get(['id']);
        foreach ($incidents as $incident) {
            $isolated(fn () => $plan->reconcileActiveEpisode($incident->id));
        }
        $catchUpsMore = $isolated($catchUps) === true;
        $remindersMore = $isolated(fn (): bool => $reminders($this->continuation)) === true;
        $events = Event::query();
        $events->getQuery()->whereNull('notification_routed_at')->orderBy('id')->limit(self::Batch);
        $unrouted = $events->get(['id']);
        foreach ($unrouted as $event) {
            $isolated(fn () => $plan($event->id));
        }
        $digestsMore = $isolated(fn (): bool => $digests($this->continuation)) === true;
        $dispatchDue();

        if ($failure instanceof Throwable) {
            throw $failure;
        }
        // A full batch or bounded housekeeping backlog continues at once; continuation passes resume only active sweeps.
        if ($catchUpsMore || $remindersMore || $digestsMore
            || in_array(self::Batch, [$incidents->count(), $unrouted->count(), count($dispatchedIds)], true)) {
            self::dispatch(continuation: true);
        }
    }
}
