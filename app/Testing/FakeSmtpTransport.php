<?php

declare(strict_types=1);

namespace Heptapod\Testing;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

final readonly class FakeSmtpTransport implements TransportInterface
{
    public function __construct(private string $command, private int $code) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $exception = new UnexpectedResponseException('SMTP response rejected', $this->code);
        $exception->appendDebug("[2026-10-05T00:30:00.000000] > {$this->command}\n[2026-10-05T00:30:00.000001] < {$this->code} rejected\n");

        throw $exception;
    }

    public function __toString(): string
    {
        return 'smtp://fake';
    }
}
