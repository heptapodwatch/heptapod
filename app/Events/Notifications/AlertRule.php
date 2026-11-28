<?php

declare(strict_types=1);

namespace Heptapod\Events\Notifications;

use Carbon\CarbonImmutable;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\NotificationDestination;
use Illuminate\Notifications\Notification;

/**
 * The send-time rule of one alert source (a site alert, a digest, a system issue, a server issue). DeliveryPlan picks the
 * rule by event, after the checks every source shares, and asks it at the claim and again at the send under the same
 * locks.
 */
interface AlertRule
{
    /** @param bool $claimed false at the claim; true at the send, where the row is this worker's own Sending claim */
    public function decide(Delivery $delivery, NotificationDestination $channel, Event $event, CarbonImmutable $at, bool $claimed): DeliveryDecision;

    /** What a delivery this rule let through sends. */
    public function notification(Delivery $delivery, Event $event, NotificationDestination $channel): Notification;
}
