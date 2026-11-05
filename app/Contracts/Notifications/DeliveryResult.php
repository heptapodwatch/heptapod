<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Notifications;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class DeliveryResult extends Data
{
    public function __construct(
        public readonly DeliveryState $status,
        public readonly ?string $reason = null,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $diagnosis = null,
    ) {
        if (in_array($status, [DeliveryState::Pending, DeliveryState::Sending], true)) {
            throw new \LogicException('A channel reports only a sent, logged or failed delivery.');
        }
        if (($status === DeliveryState::Failed) !== ($reason !== null)) {
            throw new \InvalidArgumentException('A failed delivery needs a secret-free reason key; another outcome has none.');
        }
    }

    public function recipientRejected(): bool
    {
        return $this->status === DeliveryState::Failed && preg_match('/^SMTP 5\d\d (?:RCPT|DATA)(?: — |$)/', $this->diagnosis ?? '') === 1;
    }
}
