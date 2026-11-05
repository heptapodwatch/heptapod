<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Notifications;

use Heptapod\Contracts\Mail\MailModel;
use Heptapod\Contracts\Mail\MailRenderer;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Notification;

/**
 * A system issue opening or recovery for an Owner, an opted-in Admin or a team channel. Rendered for the recipient's
 * locale before sending: mail uses the system mail model, other transports the same facts as plain lines.
 */
final class SystemIssueNotification extends Notification
{
    public function __construct(public readonly AlertMessage $message, public readonly MailModel $mail, public readonly string $idempotencyKey)
    {
        if ($message->systemFacts === null) {
            throw new \InvalidArgumentException('A system issue message must carry its facts.');
        }
    }

    public function toMail(ChannelRecipient $notifiable): Mailable
    {
        $address = $notifiable->routeNotificationForMail() ?? throw new \LogicException('A mail notification requires a recipient address.');

        return app(MailRenderer::class)->mailable($this->mail)->to($address);
    }
}
