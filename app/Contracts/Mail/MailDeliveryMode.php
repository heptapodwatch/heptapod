<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Mail;

use Illuminate\Mail\SentMessage;

final class MailDeliveryMode
{
    public static function includesLocalTransport(?string $mailer = null): bool
    {
        $mailer ??= config()->string('mail.default');
        $transport = config('mail.mailers.'.$mailer.'.transport');
        if (in_array($transport, ['log', 'array'], true)) {
            return true;
        }
        if (! in_array($transport, ['failover', 'roundrobin'], true)) {
            return false;
        }
        /** @var list<string> $members */
        $members = config()->array('mail.mailers.'.$mailer.'.mailers');

        return array_any($members, self::includesLocalTransport(...));
    }

    public static function logOnly(): bool
    {
        return in_array(config('mail.mailers.'.config()->string('mail.default').'.transport'), ['log', 'array'], true);
    }

    /** Whether this send stayed local (log/array), including a fallback mailer that ended at the log transport. */
    public static function stayedLocal(?SentMessage $sent): bool
    {
        return self::logOnly() || $sent?->getSymfonySentMessage() instanceof LoggedSentMessage;
    }
}
