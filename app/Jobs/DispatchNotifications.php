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

    public function __construct()
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
        $isolated = static function (callable $step) use (&$failure): void {
            try {
                $step();
            } catch (Throwable $exception) {
                if (! $failure instanceof Throwable) {
                    $failure = $exception;
                } else {
                    report($exception);
                }
            }
        };
        $requests = Incident::query();
        $requests->getQuery()->whereNotNull('routing_reconcile_requested_at')->orderBy('id')->limit(self::Batch);
        $incidents = $requests->get(['id']);
        foreach ($incidents as $incident) {
            $isolated(fn () => $plan->reconcileActiveEpisode($incident->id));
        }
        $isolated($catchUps);
        $isolated($reminders);
        $events = Event::query();
        $events->getQuery()->whereNull('notification_routed_at')->orderBy('id')->limit(self::Batch);
        $unrouted = $events->get(['id']);
        foreach ($unrouted as $event) {
            $isolated(fn () => $plan($event->id));
        }
        $isolated($digests);
        $due = self::due(CarbonImmutable::now('UTC'));
        $due->getQuery()->limit(self::Batch);
        $deliveries = $due->get(['id']);
        foreach ($deliveries as $delivery) {
            SendNotification::dispatch($delivery->id);
        }
        if ($failure instanceof Throwable) {
            throw $failure;
        }
        // A full batch continues at once; the follow-up queues behind the sends above.
        if (in_array(self::Batch, [$incidents->count(), $unrouted->count(), $deliveries->count()], true)) {
            self::dispatch();
        }
    }
}
