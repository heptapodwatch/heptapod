<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Notifications;

use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Notification;

/** Alerts, digests and test sends to the team and users. System and client notifications are their own classes. */
final class AlertNotification extends Notification
{
    public function __construct(public readonly AlertMessage $message, public readonly string $idempotencyKey) {}

    public function toMail(ChannelRecipient $notifiable): Mailable
    {
        $address = $notifiable->routeNotificationForMail() ?? throw new \LogicException('A mail notification requires a recipient address.');

        return app(AlertMailRenderer::class)->mailable($this->message, $notifiable->destination)->to($address);
    }
}
