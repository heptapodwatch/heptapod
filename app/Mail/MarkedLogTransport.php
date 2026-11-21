<?php

declare(strict_types=1);

namespace Heptapod\Mail;

use Heptapod\Contracts\Mail\LoggedSentMessage;
use Illuminate\Mail\Transport\LogTransport;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\RawMessage;

/** Marks its result so a fallback mailer that ended at the log is not mistaken for delivered mail. */
final class MarkedLogTransport extends LogTransport
{
    public function send(RawMessage $message, ?Envelope $envelope = null): LoggedSentMessage
    {
        parent::send($message, $envelope);

        return new LoggedSentMessage($message, $envelope ?? Envelope::create($message));
    }
}
