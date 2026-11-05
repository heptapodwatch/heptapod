<?php

declare(strict_types=1);

namespace Heptapod\Mail;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Stringable;

final readonly class CapabilityRedactingMailLogger implements LoggerInterface
{
    use LoggerTrait;

    public function __construct(private LoggerInterface $logger) {}

    /** @param array<array-key, mixed> $context */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        // Same capability paths as docker/prod/Caddyfile's access-log filter; signed query tokens cover other signed routes.
        $redacted = preg_replace([
            '~(/invitations/)[a-f0-9]{64}~i',
            '~(/reset-password/)[^?\s"\'<>]+~i',
            '~(/email/verify/)[^?\s"\'<>]+~i',
            '~((?:\?|&amp;|&)(?:token|signature)=)[^&\s"\'<>]+~i',
        ], '$1[redacted]', (string) $message, count: $count);
        if ($redacted === null) {
            throw new \RuntimeException('Capability mail redaction failed.');
        }
        if ($count > 0) {
            $redacted .= "\n[Heptapod] Capability links were redacted. Get a link with: php artisan heptapod:user:access-link <email> --type=invite|reset";
        }
        $this->logger->log($level, $redacted, $context);
    }
}
