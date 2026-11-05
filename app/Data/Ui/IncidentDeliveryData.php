<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Enums\DeliveryOutcome;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class IncidentDeliveryData extends Data
{
    public function __construct(
        public readonly string $address,
        public readonly string $event_type,
        public readonly DeliveryOutcome $state,
        public readonly ?string $diagnosis,
        public readonly ?string $sent_at,
        public readonly string $scheduled_at,
        public readonly int $event_id,
        public readonly int $id,
        public readonly bool $digest,
        public readonly bool $can_preview,
        public readonly string $event_label,
        public readonly ?string $timeline_time = null,
        public readonly ?int $sent_delay_seconds = null,
        public readonly int $retry_count = 0,
    ) {}
}
