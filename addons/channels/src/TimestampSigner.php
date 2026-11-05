<?php

declare(strict_types=1);

namespace Heptapod\Addons\Channels;

use Spatie\WebhookServer\Signer\Signer;

final readonly class TimestampSigner implements Signer
{
    public function __construct(public int $timestamp) {}

    public function signatureHeaderName(): string
    {
        return 'Signature';
    }

    /** @param array<array-key, mixed> $payload */
    public function calculateSignature(string $webhookUrl, array $payload, #[\SensitiveParameter] string $secret): string
    {
        return hash_hmac('sha256', $this->timestamp.'.'.json_encode($payload, JSON_THROW_ON_ERROR), $secret);
    }
}
