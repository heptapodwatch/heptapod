<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Mail;

use Heptapod\Contracts\SecretRedactor;
use ReflectionClass;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;

/**
 * Short, stored description of a failed mail transport call: the SMTP status and stage plus the server reply.
 * Stored diagnoses are shown to Owners, so the reply loses SMTP credentials and local parts of addresses.
 */
final class MailTransportDiagnosis
{
    private const int MaxLength = 200;

    public static function describe(TransportExceptionInterface $exception): string
    {
        $reply = $exception->getMessage();
        if ($exception instanceof UnexpectedResponseException && $exception->getCode() >= 400 && $exception->getCode() <= 599) {
            preg_match_all('/^\[[^\]\r\n]+\] > ([^\r\n]+)/m', $exception->getDebug(), $commands);
            $command = end($commands[1]);
            $stage = is_string($command) && str_starts_with($command, 'RCPT TO:') ? 'RCPT'
                : (in_array($command, ['DATA', '.'], true) ? 'DATA' : 'config');
            $prefix = 'SMTP '.$exception->getCode().' '.$stage;
            // Symfony wraps the server reply as: Expected response code "250" but got code "550", with message "550 …".
            $reply = preg_match('/with message "(.*)"\.?$/sD', $reply, $match) === 1 ? $match[1] : $reply;
        } else {
            $prefix = new ReflectionClass($exception)->getShortName();
        }
        $credentials = array_filter([config('mail.mailers.smtp.username'), config('mail.mailers.smtp.password')], static fn (mixed $value): bool => is_string($value) && $value !== '');
        $reply = SecretRedactor::redact($reply, $credentials);
        // The shared redactor skips values under four characters; at this boundary every configured credential is removed.
        foreach ($credentials as $credential) {
            $reply = str_ireplace($credential, '[redacted]', $reply);
        }
        // Local parts may be quoted ("Jane Doe"@example.com) or plain; only the domain stays.
        $reply = preg_replace('/(?:"(?:[^"\\\\\r\n]|\\\\.)*"|[^\s<>"\'@:;,]+)@([A-Za-z0-9.-]+)/', '…@$1', $reply) ?? throw new \RuntimeException('Mail diagnosis redaction failed.');
        $reply = trim(preg_replace('/\s+/', ' ', $reply) ?? throw new \RuntimeException('Mail diagnosis redaction failed.'));

        return mb_strimwidth($reply === '' ? $prefix : $prefix.' — '.$reply, 0, self::MaxLength, '…');
    }
}
