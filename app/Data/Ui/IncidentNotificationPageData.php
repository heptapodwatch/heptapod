<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class IncidentNotificationPageData extends Data
{
    /** @param list<IncidentDeliveryData> $deliveries */
    public function __construct(
        public readonly string $group_id,
        public readonly array $deliveries,
        public readonly ?string $next_cursor,
        public readonly bool $has_previous = false,
    ) {}
}
