<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Enums\DeliveryGroupOutcome;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class IncidentNotificationGroupData extends Data
{
    /** @param list<string> $recipients */
    public function __construct(
        public readonly string $id,
        public readonly string $event_type,
        public readonly string $event_label,
        public readonly DeliveryGroupOutcome $state,
        public readonly int $count,
        public readonly string $first_at,
        public readonly string $last_at,
        public readonly int $first_id,
        public readonly int $last_id,
        public readonly array $recipients,
        public readonly int $recipient_count,
        public readonly int $accepted_count,
        public readonly int $failed_count,
        public readonly int $suppressed_count,
        public readonly int $pending_count,
        public readonly int $unrecorded_count = 0,
        public readonly string $last_entry_kind = 'delivery',
        /** @var array<string, int> */
        public readonly array $unrecorded_reasons = [],
        public readonly int $logged_count = 0,
    ) {}
}
