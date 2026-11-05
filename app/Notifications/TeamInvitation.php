<?php

declare(strict_types=1);

namespace Heptapod\Notifications;

use Heptapod\Contracts\Mail\MailRenderer;
use Heptapod\Models\Invitation;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Notification;

final class TeamInvitation extends Notification
{
    public function __construct(private readonly Invitation $invitation, private readonly string $url) {}

    public function actionUrl(): string
    {
        return $this->url;
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): Mailable
    {
        $locale = $this->locale ?? app()->getLocale();
        $model = app(AccountMailFactory::class)->invitation($this->invitation, $this->url, $locale);

        return app(MailRenderer::class)->mailable($model)->to($this->invitation->email);
    }
}
