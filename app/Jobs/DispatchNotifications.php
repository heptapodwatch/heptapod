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
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\UniqueFor;

/** Durable scans recover lost wake-ups; deliveries dedupe overlapping dispatchers. */
#[Timeout(60)]
#[UniqueFor(120)]
final class DispatchNotifications implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    public function handle(PlanNotificationEvent $plan, EmitIncidentReminders $reminders, EmitNotificationDigests $digests,
        EmitMaintenanceCatchUps $catchUps): void
    {
        $requests = Incident::query();
        $requests->getQuery()->whereNotNull('routing_reconcile_requested_at')->orderBy('id')->limit(100);
        foreach ($requests->get(['id']) as $incident) {
            $plan->reconcileActiveEpisode($incident->id);
        }
        $catchUps();
        $reminders();
        $events = Event::query();
        $events->getQuery()->whereNull('notification_routed_at')->orderBy('id')->limit(100);
        foreach ($events->get() as $event) {
            $plan($event->id);
        }
        $digests();
        $at = CarbonImmutable::now('UTC');
        $deliveries = Delivery::query()->where('suppressed', false)->where('digest', false)
            ->where(function (Builder $query) use ($at): void {
                $query->where(function (Builder $q) use ($at): void {
                    $q->getQuery()->whereIn('state', [DeliveryState::Pending->value, DeliveryState::Failed->value])->where('next_attempt_at', '<=', $at->format('Y-m-d H:i:s.uP'));
                })->orWhere(fn (Builder $q) => $q->where('state', DeliveryState::Sending->value)->where('lease_until', '<=', $at->format('Y-m-d H:i:s.uP')));
            });
        $deliveries->getQuery()->whereRaw('(last_reason IS NULL OR last_reason NOT IN (?, ?))', [UptimeAlertEpisode::WaitingReason, ClaimNotificationDelivery::SystemProbeReason])->orderBy('id')->limit(100);
        foreach ($deliveries->get() as $delivery) {
            SendNotification::dispatch($delivery->id);
        }
    }
}
