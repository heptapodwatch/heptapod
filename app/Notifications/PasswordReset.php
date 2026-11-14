<?php

declare(strict_types=1);

namespace Heptapod\Notifications;

use Heptapod\Contracts\Mail\MailRenderer;
use Heptapod\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Mail\Mailable;

final class PasswordReset extends ResetPassword
{
    public function toMail(mixed $notifiable): Mailable
    {
        if (! $notifiable instanceof User) {
            throw new \LogicException('Password reset requires an account recipient.');
        }
        $model = app(AccountMailFactory::class)->passwordReset($notifiable, $this->resetUrl($notifiable));

        return app(MailRenderer::class)->mailable($model)->to($notifiable->getEmailForPasswordReset());
    }
}
