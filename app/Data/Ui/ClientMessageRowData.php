<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Enums\DeliveryOutcome;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One delivery in "Messages to this client" (ClientMessagesReader). Address and resend are null for Members. */
#[TypeScript]
final class ClientMessageRowData extends Data
{
    /** @param array<string, mixed>|null $resend */
    public function __construct(
        public readonly int $id,
        public readonly int $event_id,
        public readonly string $at,
        #[LiteralTypeScriptType("'findings' | 'incident' | 'alert'")]
        public readonly string $kind,
        public readonly string $subject,
        public readonly string $origin,
        public readonly ?string $actor,
        public readonly string $channel,
        public readonly ?string $address,
        public readonly DeliveryOutcome $state,
        public readonly ?string $reason,
        public readonly bool $attachment,
        #[LiteralTypeScriptType("{ kind: 'findings' | 'incident'; site_id: number | null; check_id: number | null; incident_id: number | null; note: string; attach: boolean; design: Heptapod.Contracts.Mail.MailDesign; one_off: string | null } | null")]
        public readonly ?array $resend,
    ) {}
}
